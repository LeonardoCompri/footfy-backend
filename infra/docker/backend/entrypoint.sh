#!/bin/sh
set -e
cd /var/www/html

mkdir -p storage/app/public storage/app/private
chown -R www-data:www-data storage
# Descarta caches antigos para sempre ler o .env atual
php artisan optimize:clear >/dev/null 2>&1 || true

# Aguarda o banco aceitar conexoes e aplica as migrations
tries=0
until php artisan migrate --force --no-interaction; do
  tries=$((tries + 1))
  if [ "$tries" -ge 20 ]; then
    echo "Nao foi possivel conectar ao banco de dados." >&2
    exit 1
  fi
  echo "Banco ainda nao esta pronto, tentando de novo em 3s..."
  sleep 3
done

# Dados de demonstracao: so na primeira subida
if [ "$SEED_ON_FIRST_RUN" = "true" ] && [ ! -f storage/app/.seeded ]; then
  echo "Populando o banco com dados de demonstracao..."
  php artisan db:seed --force --no-interaction
  touch storage/app/.seeded
fi

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan config:cache || echo "Aviso: config:cache falhou, seguindo sem cache."
php artisan route:cache || echo "Aviso: route:cache falhou, seguindo sem cache."
php artisan view:cache >/dev/null 2>&1 || true
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
