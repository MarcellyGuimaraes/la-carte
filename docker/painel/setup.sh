#!/bin/sh
# Prepara o painel antes de web e worker subirem. Roda a cada `docker compose up`
# e é idempotente: em banco já populado só aplica migrations pendentes.
# Qualquer falha encerra com código != 0, e o compose não sobe web nem worker.
set -eu

echo "[setup] composer install"
composer install --no-interaction --no-progress

echo "[setup] migrations (como dono das tabelas: DDL nunca pelo papel da aplicação)"
php artisan migrate --database=pgsql_owner --force

# Seed só em banco sem nenhum restaurante. Nunca apaga nem duplica dados.
# Checagem pelo dono (superuser ignora RLS), senão a contagem viria filtrada.
# Código de saída: 0 = tem restaurante, 3 = vazio, qualquer outro = erro (não
# confunde "não consegui checar" com "está vazio").
# php -r e não tinker: o tinker intercepta exit() e sempre sai com 1.
status=0
php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    exit(Illuminate\Support\Facades\DB::connection("pgsql_owner")->table("tenants")->exists() ? 0 : 3);
' || status=$?

case "$status" in
    0) echo "[setup] banco já tem restaurantes: seed pulado" ;;
    3) echo "[setup] banco vazio: rodando seed"
       php artisan db:seed --database=pgsql_owner --force ;;
    *) echo "[setup] ERRO ao checar se o banco tem restaurantes (código $status)" >&2
       exit "$status" ;;
esac

echo "[setup] pronto"
