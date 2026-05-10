# MyInvoice.cz — multi-stage Docker build
#
# Stage 1: build Vue frontend (web/dist)
# Stage 2: install PHP dependencies (api/vendor)
# Stage 3: runtime image (PHP 8.5 + Apache)
#
# Build:    docker compose build       (or cmd/docker-build.{sh,ps1})
# First run: cmd/docker-install.{sh,ps1}
# Daily:    docker compose up -d / down

# ---------- Stage 1: frontend ----------
FROM node:24-alpine AS web-build
WORKDIR /app
COPY web/package.json web/pnpm-lock.yaml ./
RUN corepack enable && corepack prepare pnpm@latest --activate \
 && pnpm install --frozen-lockfile
COPY web/ ./
RUN pnpm build

# ---------- Stage 2: composer ----------
# The composer image has only a minimal PHP without pdo_mysql/gd/intl extensions,
# but the runtime stage installs them — so skip platform checks here.
FROM composer:2 AS php-deps
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-reqs
COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative

# ---------- Stage 3: runtime ----------
FROM php:8.5-apache AS runtime

# Use mlocati/docker-php-extension-installer — the de-facto installer for PHP-Docker
# extensions. Handles apt deps, parallel builds, PECL packages and the
# install-modules race condition that bites raw `docker-php-ext-install` on PHP 8.5.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_mysql gd mbstring intl zip opcache exif bcmath redis \
 && apt-get update \
 && apt-get install -y --no-install-recommends tini librsvg2-bin \
 && a2enmod rewrite headers deflate expires \
 && rm -f /etc/apache2/mods-enabled/mpm_* \
 && a2enmod mpm_prefork \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# PHP runtime config
RUN { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 20M'; \
        echo 'post_max_size = 25M'; \
        echo 'max_execution_time = 60'; \
        echo 'date.timezone = Europe/Prague'; \
        echo 'expose_php = Off'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 128'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/myinvoice.ini

# Apache: doc root → /var/www/html, allow .htaccess, dynamický port přes ${PORT}
# (Apache 2.4 expanduje ${PORT} z env při parsingu konfigurace — funguje
# pro Railway/Heroku/Fly.io, kde je port přidělen dynamicky. Default 80.)
ENV PORT=80
RUN sed -ri \
        -e 's!/var/www/html!/var/www/html!g' \
        -e 's!AllowOverride None!AllowOverride All!g' \
        /etc/apache2/apache2.conf /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's!^Listen 80$!Listen ${PORT}!' /etc/apache2/ports.conf \
 && sed -ri 's!\*:80>!*:${PORT}>!' /etc/apache2/sites-available/000-default.conf

# Copy application code
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
RUN chmod +x /var/www/html/docker-entrypoint.sh
COPY --from=web-build --chown=www-data:www-data /app/dist ./web/dist
COPY --from=php-deps  --chown=www-data:www-data /app/vendor ./api/vendor

# Generate HTML + PDF manual from manual/*.md
# (HTML servíruje /manual route, PDF se nabízí jako "Stáhnout PDF" v sidebaru)
RUN php tools/generateManualHtml.php \
 && php tools/exportManualToPdf.php \
 && chown -R www-data:www-data manual/generated manual/manual.pdf

# Writable dirs (cfg.php, log/, storage/, private/ are bind-mounted from host)
RUN mkdir -p log storage private && chown -R www-data:www-data log storage private

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/var/www/html/docker-entrypoint.sh"]
