#!/usr/bin/env bash
# Deploy de um servico do Footfy, chamado pelo GitHub Actions (pelo atalho <pasta>/deploy.sh) ou na mao.
#
#   deploy.sh backend     atualiza o footfy-backend
#   deploy.sh frontend    atualiza o footfy-frontend
#   deploy.sh all         os dois
#
# O ambiente e a pasta onde o script esta: <pasta>/footfy-backend/infra/deploy.sh -> <pasta>.
# Se o build falhar, a versao antiga continua no ar.
# Backend: backup do banco (db_predeploy_*), Redis no ar e migrations antes de trocar o container.
# Se o backup ou a migration falhar, a versao antiga continua no ar e o commit volta.
# Se a nova versao nao responder, volta automaticamente para a anterior (as migrations ficam).
set -euo pipefail

log()  { printf "\n\033[1;34m[deploy] %s\033[0m\n" "$*"; }
fail() { printf "\n\033[1;31m[deploy] ERRO: %s\033[0m\n" "$*" >&2; exit 1; }

env_value() {
  local value
  value="$(sed -n "s/^$1=//p" .env | tail -n 1)"
  value="${value%\"}"; value="${value#\"}"
  printf '%s' "$value"
}

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" -f footfy-backend/infra/docker-compose.yml --env-file .env "$@"
}

healthy() {
  for _ in $(seq 1 40); do
    if curl -fsS "http://127.0.0.1:$WEB_PORT/up" >/dev/null 2>&1 \
       && curl -fsS "http://127.0.0.1:$WEB_PORT/" | grep -qi "<div id=\"app\""; then
      return 0
    fi
    sleep 3
  done
  return 1
}

# Volta o commit e a tag da imagem. O compose esta no clone do backend: precisa vir antes de recriar o container.
# Retorna 1 se nao houver imagem anterior para devolver.
rollback() {
  local repo="$1" image="$2" old_commit="$3"
  git -C "$repo" reset --quiet --hard "$old_commit"
  docker image inspect "$image:$IMAGE_TAG-previous" >/dev/null 2>&1 || return 1
  docker tag "$image:$IMAGE_TAG-previous" "$image:$IMAGE_TAG"
}

deploy_one() {
  local repo="$1" service="$2" image="footfy/$2"
  log "Atualizando $repo ($DEPLOY_BRANCH)"
  git -C "$repo" fetch --quiet origin "$DEPLOY_BRANCH"
  local old_commit new_commit
  old_commit="$(git -C "$repo" rev-parse HEAD)"
  git -C "$repo" checkout --quiet "$DEPLOY_BRANCH"
  git -C "$repo" reset --quiet --hard "origin/$DEPLOY_BRANCH"
  new_commit="$(git -C "$repo" rev-parse HEAD)"
  log "$repo: ${old_commit:0:7} -> ${new_commit:0:7}"

  # Guarda a imagem atual para poder voltar
  docker image inspect "$image:$IMAGE_TAG" >/dev/null 2>&1 \
    && docker tag "$image:$IMAGE_TAG" "$image:$IMAGE_TAG-previous"

  log "Construindo $service"
  if ! compose build "$service"; then
    rollback "$repo" "$image" "$old_commit" || true
    fail "Build de $service falhou. Nada foi alterado no ar."
  fi

  if [ "$service" = backend ]; then
    log "Backup do banco antes das migrations"
    if ! footfy-backend/infra/backup.sh predeploy; then
      rollback "$repo" "$image" "$old_commit" || true
      fail "Backup antes das migrations falhou. Nada foi alterado no ar."
    fi

    log "Subindo o Redis"
    if ! compose up -d --wait redis; then
      rollback "$repo" "$image" "$old_commit" || true
      fail "O Redis nao ficou saudavel. O backend antigo continua no ar (${old_commit:0:7})."
    fi

    log "Aplicando migrations"
    # Como root: o www-data nao le o .env (chmod 600). Log no stderr para o root nao criar o log do dia.
    if ! compose run --rm --no-deps -e LOG_CHANNEL=stderr backend php artisan migrate --force --no-interaction; then
      rollback "$repo" "$image" "$old_commit" || true
      fail "Migration falhou. O backend antigo continua no ar (${old_commit:0:7}). Backup de antes em backups/db_predeploy_* (veja infra/README.md, Migration com erro)."
    fi
  fi

  log "Trocando $service"
  compose up -d --no-deps "$service"

  log "Verificando se o site responde"
  if ! healthy; then
    log "Nova versao nao respondeu. Voltando para a anterior..."
    compose logs --tail=60 "$service" || true
    if rollback "$repo" "$image" "$old_commit"; then
      compose up -d --no-deps "$service"
    fi
    fail "Deploy de $service desfeito (rollback para ${old_commit:0:7})."
  fi

  log "$service no ar com ${new_commit:0:7}"
}

main() {
  local target="${1:-all}" env_dir
  env_dir="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
  cd "$env_dir"

  [ -f .env ] || fail "Arquivo .env nao encontrado em $env_dir. Rode footfy-backend/infra/setup.sh primeiro."
  [ -f footfy-backend/infra/docker-compose.yml ] || fail "Esperava o clone em $env_dir/footfy-backend."

  COMPOSE_PROJECT_NAME="$(env_value COMPOSE_PROJECT_NAME)"
  DEPLOY_BRANCH="$(env_value DEPLOY_BRANCH)"
  IMAGE_TAG="$(env_value IMAGE_TAG)"
  WEB_PORT="$(env_value WEB_PORT)"; WEB_PORT="${WEB_PORT:-8081}"
  for name in COMPOSE_PROJECT_NAME DEPLOY_BRANCH IMAGE_TAG; do
    [ -n "${!name}" ] || fail "$name nao definido em $env_dir/.env (veja infra/README.md)."
  done
  [ -n "$(env_value REDIS_PASSWORD)" ] \
    || fail "REDIS_PASSWORD nao definido em $env_dir/.env. Acrescente as variaveis do Redis antes do deploy (veja infra/README.md, Redis)."

  case "$target" in
    backend|frontend|all) ;;
    *) fail "Uso: deploy.sh backend|frontend|all" ;;
  esac

  # Um deploy por vez nesta pasta (backend e frontend podem disparar juntos)
  exec 9>.deploy.lock
  log "[$COMPOSE_PROJECT_NAME] Aguardando outros deploys terminarem..."
  flock 9

  case "$target" in
    backend)  deploy_one footfy-backend backend ;;
    frontend) deploy_one footfy-frontend web ;;
    all)      deploy_one footfy-backend backend; deploy_one footfy-frontend web ;;
  esac

  docker image prune -f >/dev/null 2>&1 || true
  log "Deploy concluido."
}

# O deploy do backend faz reset --hard neste clone, inclusive neste arquivo.
# O bash ja leu as funcoes acima e esta linha inteira; o exit impede que ele leia o arquivo trocado.
main "$@"; exit $?
