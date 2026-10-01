# Imagem de DESENVOLVIMENTO do painel. Usada só pelo docker-compose.yml da raiz.
# A de produção (Render) é painel/Dockerfile e não é usada aqui.
#
# Por que não reaproveitar a de produção: ela copia o código e roda
# `composer install --no-dev` no build (rebuild a cada mudança) e o start.sh dela
# faz config:cache, que congela o env e mata o hot reload.
#
# Mantenha as extensões em sincronia com painel/Dockerfile: o que falta aqui
# aparece como erro de extensão, mas o que falta lá só aparece em produção.
FROM php:8.4-cli

# Extensões: pgsql (banco), gd com jpeg/webp (pipeline de imagem), intl e zip (Filament).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
        libicu-dev libzip-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo_pgsql gd intl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Destino dos caches do Laravel (APP_*_CACHE no docker-compose.yml). O Laravel
# não cria o diretório sozinho e falha se ele não existir.
RUN mkdir -p /tmp/laravel && chmod 777 /tmp/laravel

WORKDIR /var/www/html
