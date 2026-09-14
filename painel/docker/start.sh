#!/bin/sh
set -e

# O Render informa a porta em $PORT; a imagem do Apache vem escutando na 80.
PORT="${PORT:-80}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan storage:link || true

# Migrations SEMPRE como dono das tabelas (pgsql_owner = neondb_owner no Neon).
php artisan migrate --database=pgsql_owner --force

# Cache em runtime, não no build: as variáveis do Render só existem agora.
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

# Worker no mesmo container: sem serviço pago extra e lendo o mesmo disco das
# fotos originais que a web recebeu. O laço religa o worker se ele morrer;
# --max-time recicla a memória a cada hora.
( while true; do
    su -s /bin/sh www-data -c "php artisan queue:work --tries=3 --max-time=3600" || true
    sleep 2
  done ) &

exec apache2-foreground
