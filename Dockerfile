# syntax=docker/dockerfile:1.7
# fiapx-video-processor: worker sem HTTP que roda `php artisan processor:consume` (spec §8.5, ADR-009). Alvos:
#   dev   ambiente de desenvolvimento e CI (composer, pcov)   docker build --target dev .
#   prod  imagem final, sem dependências de desenvolvimento    docker build --platform linux/amd64 .

ARG PHP_VERSION=8.4.27

# --- base: PHP CLI + ffmpeg + extensões exigidas (zip e pcntl) ---------------------------------------
FROM php:${PHP_VERSION}-cli-alpine AS base
RUN apk add --no-cache ffmpeg libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pcntl zip \
 && apk del .build-deps
WORKDIR /var/www/html

# --- dev: ferramentas de desenvolvimento e teste ----------------------------------------------------
FROM base AS dev
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
 && pecl install pcov && docker-php-ext-enable pcov \
 && apk del .build-deps \
 && apk add --no-cache git unzip
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- vendor: dependências de produção --------------------------------------------------------------
FROM dev AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -rf tests docs .github k8s

# --- prod ---------------------------------------------------------------------------------------------
FROM base AS prod
# Agente New Relic PHP (ADR-011). Só há build para Alpine em x86_64: a imagem de produção é linux/amd64.
# Fica inativo sem NEW_RELIC_LICENSE_KEY (ambiente local).
ARG NEWRELIC_VERSION=12.12.0.41
RUN set -eux; \
    apk add --no-cache --virtual .nr curl; \
    curl -fsSL "https://download.newrelic.com/php_agent/archive/${NEWRELIC_VERSION}/newrelic-php5-${NEWRELIC_VERSION}-linux-musl.tar.gz" -o /tmp/nr.tgz; \
    tar -xzf /tmp/nr.tgz -C /tmp; \
    NR_INSTALL_SILENT=1 NR_INSTALL_USE_CP_NOT_LN=1 /tmp/newrelic-php5-*/newrelic-install install; \
    rm -rf /tmp/nr.tgz /tmp/newrelic-php5-*; apk del .nr; \
    { echo 'extension = "newrelic.so"'; \
      echo 'newrelic.enabled = ${NEW_RELIC_ENABLED}'; \
      echo 'newrelic.license = "${NEW_RELIC_LICENSE_KEY}"'; \
      echo 'newrelic.appname = "${NEW_RELIC_APP_NAME}"'; \
      echo 'newrelic.distributed_tracing_enabled = true'; \
      echo 'newrelic.application_logging.forwarding.enabled = true'; \
      echo 'newrelic.daemon.address = "${NEW_RELIC_DAEMON_ADDRESS}"'; } > "$PHP_INI_DIR/conf.d/newrelic.ini"
ENV NEW_RELIC_ENABLED=false NEW_RELIC_APP_NAME=fiapx-video-processor NEW_RELIC_DAEMON_ADDRESS=@newrelic NEW_RELIC_LICENSE_KEY=""

COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-fiapx.ini"
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
# /work: diretório de trabalho dos vídeos (no Kubernetes, um emptyDir com sizeLimit montado por cima).
RUN mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache /work \
 && chown -R www-data:www-data storage bootstrap/cache /work
ENV FIAPX_WORK_DIR=/work
# Numérico: com runAsNonRoot, o Kubernetes só aceita a imagem se puder confirmar que o UID não é 0.
USER 82:82
# Liveness: o laço do consumidor (e o heartbeat durante o ffmpeg) toca este arquivo a cada volta.
HEALTHCHECK --interval=60s --timeout=5s --start-period=60s CMD php artisan processor:alive
CMD ["php", "artisan", "processor:consume"]
