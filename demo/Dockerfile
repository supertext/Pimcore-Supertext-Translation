# Supertext Translation for Pimcore: public demo (Railway). Build context = repository root:
#   docker build -f demo/Dockerfile -t supertext-pimcore-demo .
# One container runs Apache (Pimcore + Studio), the Messenger worker and a Mercure hub.
# MySQL/MariaDB and OpenSearch are separate services (see demo/.env.example).
FROM public.ecr.aws/docker/library/php:8.4-apache

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mysqli intl gd zip bcmath exif opcache imagick \
 && apt-get update && apt-get install -y --no-install-recommends unzip \
 && rm -rf /var/lib/apt/lists/* \
 && (a2dismod -f mpm_event mpm_worker >/dev/null 2>&1 || true) \
 && a2enmod mpm_prefork rewrite headers proxy proxy_http \
 && printf 'memory_limit=512M\nmax_execution_time=600\nupload_max_filesize=32M\npost_max_size=32M\nopcache.memory_consumption=256\nopcache.max_accelerated_files=50000\n' > /usr/local/etc/php/conf.d/pimcore.ini

COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer
# Mercure hub (live updates in Pimcore Studio), proxied by Apache under /.well-known/mercure.
COPY --from=docker.io/dunglas/mercure:v0.21 /usr/bin/caddy /usr/local/bin/mercure
COPY --from=docker.io/dunglas/mercure:v0.21 /etc/caddy/Caddyfile /etc/mercure/Caddyfile
COPY demo/docker/apache.conf /etc/apache2/sites-available/000-default.conf

# The bundle goes to demo/module, which the demo project installs through a path repository.
WORKDIR /app
COPY composer.json /app/demo/module/
COPY config /app/demo/module/config
COPY lib /app/demo/module/lib
COPY public /app/demo/module/public
COPY src /app/demo/module/src
COPY translations /app/demo/module/translations
COPY demo/project /app/demo/project
COPY demo/docker /app/demo/docker
WORKDIR /app/demo/project
RUN rm -f .env.local && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts \
 && mkdir -p var public/var \
 && chown www-data:www-data . && chown -R www-data:www-data var public config \
 && for d in vendor/pimcore/*/public; do chown -R www-data:www-data "$d"; done \
 && chmod +x /app/demo/docker/entrypoint.sh

ENV APP_ENV=prod APP_DEBUG=0 PORT=8080
EXPOSE 8080
CMD ["/app/demo/docker/entrypoint.sh"]
