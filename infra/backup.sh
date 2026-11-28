#!/usr/bin/env bash
# Gera backup do banco e dos arquivos enviados em <pasta>/backups (veja infra/README.md).
#
#   backup.sh              diario (cron): db_daily_* e uploads_*, guarda os 14 mais recentes de cada.
#                          Com BACKUP_REMOTE no .env, envia tambem o dump do banco para o remote do rclone.
#   backup.sh predeploy    antes das migrations (deploy.sh): so o dump, db_predeploy_*, guarda os 10 mais recentes.
#
# O ambiente e a pasta onde o clone do backend esta: <pasta>/footfy-backend/infra/backup.sh -> <pasta>.
set -euo pipefail
shopt -s nullglob

fail() { printf "ERRO: %s\n" "$*" >&2; exit 1; }

env_value() {
  local value
  value="$(sed -n "s/^$1=//p" .env | tail -n 1)"
  value="${value%\"}"; value="${value#\"}"
  printf '%s' "$value"
}

prune() {
  local keep="$1"
  shift
  [ "$#" -gt 0 ] || return 0
  ls -1t -- "$@" | tail -n +"$((keep + 1))" | xargs -r rm --
}

find_rclone() {
  if [ -n "${RCLONE_BIN:-}" ]; then
    [ -x "$RCLONE_BIN" ] && printf '%s' "$RCLONE_BIN"
    return 0
  fi
  if command -v rclone >/dev/null; then
    command -v rclone
  elif [ -x "$HOME/.local/bin/rclone" ]; then
    printf '%s' "$HOME/.local/bin/rclone"
  fi
}

dump_db() {
  local file="$1"
  compose exec -T db sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' \
    | gzip > "$file.part" \
    || { rm -f "$file.part"; fail "Falha ao gerar o dump do banco. Nada foi enviado nem apagado."; }
  mv "$file.part" "$file"
}

MODE="${1:-daily}"
case "$MODE" in
  daily|predeploy) ;;
  *) fail "Uso: backup.sh [predeploy]" ;;
esac

ENV_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
cd "$ENV_DIR"
[ -f .env ] || fail "Arquivo .env nao encontrado em $ENV_DIR."

COMPOSE_PROJECT_NAME="$(env_value COMPOSE_PROJECT_NAME)"
[ -n "$COMPOSE_PROJECT_NAME" ] || fail "COMPOSE_PROJECT_NAME nao definido em $ENV_DIR/.env (veja infra/README.md)."
BACKUP_REMOTE="$(env_value BACKUP_REMOTE)"
BACKUP_REMOTE_KEEP_DAYS="$(env_value BACKUP_REMOTE_KEEP_DAYS)"
BACKUP_REMOTE_KEEP_DAYS="${BACKUP_REMOTE_KEEP_DAYS:-30}"

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" -f footfy-backend/infra/docker-compose.yml --env-file .env "$@"
}

mkdir -p backups

if [ "$MODE" = predeploy ]; then
  DB_FILE="backups/db_predeploy_$(date +%Y-%m-%d_%H%M%S).sql.gz"
  dump_db "$DB_FILE"
  prune 10 backups/db_predeploy_*.sql.gz
  echo "Backup antes das migrations salvo em $ENV_DIR/$DB_FILE"
  exit 0
fi

STAMP="$(date +%Y-%m-%d_%H%M)"
dump_db "backups/db_daily_$STAMP.sql.gz"
compose exec -T backend tar -czf - -C /var/www/html/storage/app public > "backups/uploads_$STAMP.tar.gz"

prune 14 backups/db_daily_*.sql.gz backups/db_[0-9]*.sql.gz
prune 14 backups/uploads_*.tar.gz
echo "Backup salvo em $ENV_DIR/backups ($STAMP)"

[ -n "$BACKUP_REMOTE" ] || exit 0

[[ "$BACKUP_REMOTE_KEEP_DAYS" =~ ^[1-9][0-9]*$ ]] \
  || fail "BACKUP_REMOTE_KEEP_DAYS invalido em $ENV_DIR/.env ($BACKUP_REMOTE_KEEP_DAYS). Use um numero de dias, ex.: 30."
RCLONE="$(find_rclone)"
[ -n "$RCLONE" ] \
  || fail "BACKUP_REMOTE definido, mas o rclone nao foi encontrado (PATH, ~/.local/bin ou RCLONE_BIN). O backup local foi mantido."

"$RCLONE" copy backups "$BACKUP_REMOTE" --include 'db_daily_*.sql.gz' --max-age "${BACKUP_REMOTE_KEEP_DAYS}d" \
  || fail "Falha ao enviar o dump para $BACKUP_REMOTE. O backup local foi mantido em $ENV_DIR/backups."
"$RCLONE" delete "$BACKUP_REMOTE" --include 'db_daily_*.sql.gz' --min-age "${BACKUP_REMOTE_KEEP_DAYS}d" \
  || fail "Dump enviado para $BACKUP_REMOTE, mas a limpeza dos dumps com mais de $BACKUP_REMOTE_KEEP_DAYS dias falhou."
echo "Dump enviado para $BACKUP_REMOTE (dumps externos com mais de $BACKUP_REMOTE_KEEP_DAYS dias apagados)"
