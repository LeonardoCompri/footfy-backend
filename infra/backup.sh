#!/usr/bin/env bash
# Gera backup do banco e dos arquivos enviados em <pasta>/backups (guarda os 14 mais recentes).
# O ambiente e a pasta onde o clone do backend esta: <pasta>/footfy-backend/infra/backup.sh -> <pasta>.
set -euo pipefail

fail() { printf "ERRO: %s\n" "$*" >&2; exit 1; }

ENV_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/../.." && pwd)"
cd "$ENV_DIR"
[ -f .env ] || fail "Arquivo .env nao encontrado em $ENV_DIR."

COMPOSE_PROJECT_NAME="$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' .env | tail -n 1)"
[ -n "$COMPOSE_PROJECT_NAME" ] || fail "COMPOSE_PROJECT_NAME nao definido em $ENV_DIR/.env (veja infra/README.md)."

compose() {
  docker compose -p "$COMPOSE_PROJECT_NAME" -f footfy-backend/infra/docker-compose.yml --env-file .env "$@"
}

mkdir -p backups
STAMP="$(date +%Y-%m-%d_%H%M)"

compose exec -T db sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' \
  | gzip > "backups/db_$STAMP.sql.gz"
compose exec -T backend tar -czf - -C /var/www/html/storage/app public > "backups/uploads_$STAMP.tar.gz"

ls -1t backups/db_*.sql.gz | tail -n +15 | xargs -r rm --
ls -1t backups/uploads_*.tar.gz | tail -n +15 | xargs -r rm --
echo "Backup salvo em $ENV_DIR/backups ($STAMP)"
