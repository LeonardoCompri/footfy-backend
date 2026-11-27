#!/bin/sh
# Prepara o container a cada start (deploy, restart e reboot). Nao roda migrations nem seed:
# as migrations ficam no deploy.sh (com backup antes) e o seed no setup.sh (primeira instalacao).
# Com --user www-data (migrate do deploy, setup.sh) pula o que precisa de root: chown e storage:link.
set -e
cd /var/www/html

is_root() {
  [ "$(id -u)" = "0" ]
}

mkdir -p storage/app/public storage/app/private
if is_root; then
  chown -R www-data:www-data storage
fi
# Descarta caches antigos para sempre ler o .env atual
php artisan optimize:clear >/dev/null 2>&1 || true

if is_root; then
  php artisan storage:link --force >/dev/null 2>&1 || true
fi
php artisan config:cache || echo "Aviso: config:cache falhou, seguindo sem cache."
php artisan route:cache || echo "Aviso: route:cache falhou, seguindo sem cache."
if is_root; then
  chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
