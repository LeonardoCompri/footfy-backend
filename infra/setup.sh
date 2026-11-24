#!/usr/bin/env bash
# Primeira instalacao de um ambiente do Footfy no servidor da Analitix. Pode rodar de novo sem problema.
#
#   footfy-backend/infra/setup.sh production    pasta ~/analitix/footfy
#   footfy-backend/infra/setup.sh staging       pasta ~/analitix/footfy-staging
#
# O ambiente e a pasta onde o clone do backend esta: <pasta>/footfy-backend/infra/setup.sh -> <pasta>.
set -euo pipefail

GITHUB_USER="LeonardoCompri"

info() { printf "\n\033[1;32m==> %s\033[0m\n" "$1"; }
fail() { printf "\n\033[1;31mERRO: %s\033[0m\n" "$1" >&2; exit 1; }

env_value() {
  local value
  value="$(sed -n "s/^$1=//p" .env | tail -n 1)"
  value="${value%\"}"; value="${value#\"}"
  printf '%s' "$value"
}

ENVIRONMENT="${1:-}"
case "$ENVIRONMENT" in
  production)
    COMPOSE_PROJECT_NAME="footfy"
    DEPLOY_BRANCH="main"
    IMAGE_TAG="production"
    APP_ENV="production"
    BASE_PATH="/footfy"
    PUBLIC_PORT=""
    WEB_PORT="8081"
    DB_FORWARD_PORT="3307"
    SEED_ON_FIRST_RUN="true"
    ;;
  staging)
    COMPOSE_PROJECT_NAME="footfy-staging"
    DEPLOY_BRANCH="staging"
    IMAGE_TAG="staging"
    APP_ENV="staging"
    BASE_PATH=""
    PUBLIC_PORT=":8443"
    WEB_PORT="8082"
    DB_FORWARD_PORT="3308"
    SEED_ON_FIRST_RUN="true"
    ;;
  *)
    fail "Uso: footfy-backend/infra/setup.sh production|staging"
    ;;
esac

ENV_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
cd "$ENV_DIR"
INFRA="footfy-backend/infra"
GATEWAY_DIR="$(cd .. && pwd)/gateway"

command -v docker >/dev/null || fail "Docker nao instalado."
command -v tailscale >/dev/null || fail "Tailscale nao instalado."
[ -f "$INFRA/docker-compose.yml" ] || fail "Esperava o clone do backend em $ENV_DIR/footfy-backend."
if [ -n "$BASE_PATH" ]; then
  [ -f "$GATEWAY_DIR/docker-compose.yml" ] || fail "Portao nao encontrado em $GATEWAY_DIR. Instale o gateway primeiro."
fi

# 1. Atalho chamado pelos workflows: <pasta>/deploy.sh
SHORTCUT='#!/usr/bin/env bash
exec "$(dirname "$0")/footfy-backend/infra/deploy.sh" "$@"'
if [ -e deploy.sh ] && [ "$(cat deploy.sh)" != "$SHORTCUT" ]; then
  fail "$ENV_DIR/deploy.sh existe e nao e o atalho. Mova-o para _legacy/ (veja infra/README.md) e rode de novo."
fi
if [ ! -e deploy.sh ]; then
  info "Criando o atalho $ENV_DIR/deploy.sh"
  printf '%s\n' "$SHORTCUT" > deploy.sh
fi
chmod +x deploy.sh

# 2. Codigo do frontend
if [ ! -d footfy-frontend/.git ]; then
  info "Baixando footfy-frontend ($DEPLOY_BRANCH)"
  git clone --branch "$DEPLOY_BRANCH" "https://github.com/$GITHUB_USER/footfy-frontend.git" footfy-frontend \
    || fail "Nao consegui clonar footfy-frontend. Rode 'gh auth login' e 'gh auth setup-git'."
fi

# 3. Endereco publico do Tailscale
PUBLIC_ORIGIN="$(tailscale status --json | sed -n 's/.*"DNSName": *"\([^"]*\)".*/\1/p' | head -n1)"
PUBLIC_ORIGIN="${PUBLIC_ORIGIN%.}"
[ -n "$PUBLIC_ORIGIN" ] || fail "Tailscale nao esta conectado. Rode 'sudo tailscale up'."

# 4. Arquivo .env (gerado so uma vez, com senhas aleatorias)
if [ ! -f .env ]; then
  info "Gerando .env ($ENVIRONMENT) com senhas aleatorias"
  sed -e "s|__COMPOSE_PROJECT_NAME__|$COMPOSE_PROJECT_NAME|" \
      -e "s|__DEPLOY_BRANCH__|$DEPLOY_BRANCH|" \
      -e "s|__IMAGE_TAG__|$IMAGE_TAG|" \
      -e "s|__APP_ENV__|$APP_ENV|" \
      -e "s|__APP_KEY__|base64:$(openssl rand -base64 32)|" \
      -e "s|__APP_URL__|https://$PUBLIC_ORIGIN$PUBLIC_PORT$BASE_PATH|" \
      -e "s|__BASE_PATH__|$BASE_PATH|" \
      -e "s|__PUBLIC_ORIGIN__|$PUBLIC_ORIGIN|" \
      -e "s|__PUBLIC_PORT__|$PUBLIC_PORT|" \
      -e "s|__DB_PASSWORD__|$(openssl rand -hex 16)|" \
      -e "s|__DB_ROOT_PASSWORD__|$(openssl rand -hex 16)|" \
      -e "s|__WEB_PORT__|$WEB_PORT|" \
      -e "s|__DB_FORWARD_PORT__|$DB_FORWARD_PORT|" \
      -e "s|__SEED_ON_FIRST_RUN__|$SEED_ON_FIRST_RUN|" \
      "$INFRA/env.template" > .env
  chmod 600 .env
else
  for name in COMPOSE_PROJECT_NAME DEPLOY_BRANCH IMAGE_TAG; do
    [ -n "$(env_value "$name")" ] || fail "$name nao definido em $ENV_DIR/.env. Acrescente $name=${!name} (veja infra/README.md)."
  done
  [ "$(env_value COMPOSE_PROJECT_NAME)" = "$COMPOSE_PROJECT_NAME" ] \
    || fail "O .env de $ENV_DIR e de outro ambiente (COMPOSE_PROJECT_NAME=$(env_value COMPOSE_PROJECT_NAME), esperado $COMPOSE_PROJECT_NAME)."
  # Mantem senhas e chave, mas atualiza o endereco se o nome da maquina mudou
  BASE_PATH="$(env_value APP_BASE_PATH)"
  PUBLIC_PORT="$(env_value PUBLIC_PORT)"
  sed -i -e "s|^APP_URL=.*|APP_URL=https://$PUBLIC_ORIGIN$PUBLIC_PORT$BASE_PATH|" \
         -e "s|^PUBLIC_ORIGIN=.*|PUBLIC_ORIGIN=$PUBLIC_ORIGIN|" .env
fi
COMPOSE_PROJECT_NAME="$(env_value COMPOSE_PROJECT_NAME)"
WEB_PORT="$(env_value WEB_PORT)"; WEB_PORT="${WEB_PORT:-8081}"

# 5. Containers
info "Construindo e subindo os containers (a primeira vez demora alguns minutos)"
docker compose -p "$COMPOSE_PROJECT_NAME" -f "$INFRA/docker-compose.yml" --env-file .env up -d --build

info "Aguardando o Footfy responder"
for _ in $(seq 1 60); do
  curl -fsS "http://127.0.0.1:$WEB_PORT/up" >/dev/null 2>&1 && ok=1 && break
  sleep 3
done
[ "${ok:-}" = 1 ] || fail "O Footfy nao respondeu. Veja os logs: $ENV_DIR/footfy-backend/infra/README.md (Logs)."

# 6. Publicacao: rota no portao (com caminho) ou Funnel em porta propria (sem caminho)
if [ -n "$BASE_PATH" ]; then
  info "Registrando $BASE_PATH no portao da Analitix"
  sed -e "s|__BASE_PATH__|$BASE_PATH|g" -e "s|__WEB_PORT__|$WEB_PORT|g" \
      "$INFRA/gateway.conf.template" > "$GATEWAY_DIR/projects/$COMPOSE_PROJECT_NAME.conf"
  "$GATEWAY_DIR/reload.sh"
else
  info "Sem caminho no portao. Publique uma vez (persistente), com a porta ${PUBLIC_PORT#:} livre em 'tailscale funnel status':"
  printf "    sudo tailscale funnel --bg --https=%s http://127.0.0.1:%s\n" "${PUBLIC_PORT#:}" "$WEB_PORT"
fi

info "Pronto! https://$PUBLIC_ORIGIN$PUBLIC_PORT$BASE_PATH/"
