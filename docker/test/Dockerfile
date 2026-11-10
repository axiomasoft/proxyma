FROM php:8.4-cli-bookworm@sha256:b3e907a1108629c562c4bb313ce3449e34d49991f6bb2487913f6146f47200fe
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libcurl4-openssl-dev libonig-dev libxml2-dev libzip-dev libicu-dev libpng-dev python3 openssl git unzip && docker-php-ext-install pdo_pgsql curl mbstring xml zip intl gd bcmath && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install dom simplexml xmlreader xmlwriter
COPY docker/test/quality.ini /usr/local/etc/php/conf.d/zz-proxyma-test.ini
WORKDIR /work
