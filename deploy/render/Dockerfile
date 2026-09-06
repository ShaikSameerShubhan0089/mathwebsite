# Áis Mhatamaitice — Render web service
#
# Render has no native PHP runtime, so the service is a container. This image is
# WordPress + Apache + the PostgreSQL driver + the cogg-mata plugin and theme,
# built to talk to a Supabase Postgres database through the PG4WP drop-in.
#
# COPY paths are relative to the REPOSITORY ROOT, not to this folder. Render's
# "Docker Build Context Directory" defaults to the repo root, and a blank field
# is the easiest thing in the world to leave blank — a build that only works
# when someone remembers to set it is a build that fails.
#
# Build locally to check it before pushing, FROM THE REPOSITORY ROOT:
#   docker build -f deploy/render/Dockerfile -t mata .
#   docker run --rm -p 8080:8080 -e PORT=8080 \
#     -e DB_HOST=... -e DB_NAME=postgres -e DB_USER=... -e DB_PASSWORD=... mata

FROM php:8.3-apache

# --------------------------------------------------------------- extensions
# pgsql      — PG4WP calls pg_connect directly, so the procedural driver is the
#              one that matters; pdo_pgsql is added because some plugins expect it.
# intl       — Transliterator, used to fold fadas so "codain" finds "Codáin".
#              The plugin falls back to a manual map without it, but the ICU
#              path is the correct one.
# gd, zip    — media handling and plugin/theme installation.
# opcache    — the container is read-only in practice; caching compiled PHP is
#              the single biggest performance win available here.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        unzip curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pgsql pdo_pgsql intl zip gd opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers expires deflate remoteip

# ------------------------------------------------------------------ php.ini
RUN { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=32M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=60'; \
      echo 'expose_php=Off'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/mata.ini

# ------------------------------------------------------------------ apache
# AllowOverride All so the .htaccess we ship actually applies. Without it the
# rewrite rules are ignored and every page except the home page returns 404.
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '  Options -Indexes +FollowSymLinks' \
    '  AllowOverride All' \
    '  Require all granted' \
    '</Directory>' \
    'ServerTokens Prod' \
    'ServerSignature Off' \
    'ServerName localhost' \
    > /etc/apache2/conf-available/mata.conf \
 && a2enconf mata

# Render terminates TLS at its edge and forwards plain HTTP. Trust the
# forwarded-for header so access logs and any IP-based logic see the visitor
# rather than the proxy.
RUN printf '%s\n' \
    'RemoteIPHeader X-Forwarded-For' \
    'RemoteIPTrustedProxy 10.0.0.0/8' \
    'RemoteIPTrustedProxy 172.16.0.0/12' \
    > /etc/apache2/conf-available/remoteip.conf \
 && a2enconf remoteip

# --------------------------------------------------------------- wordpress
ARG WP_VERSION=6.7.1
WORKDIR /var/www/html
RUN curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o /tmp/wp.tgz \
 && tar -xzf /tmp/wp.tgz -C /tmp \
 && cp -a /tmp/wordpress/. /var/www/html/ \
 && rm -rf /tmp/wp.tgz /tmp/wordpress \
 && rm -f /var/www/html/wp-config-sample.php \
          /var/www/html/readme.html \
          /var/www/html/license.txt \
          /var/www/html/xmlrpc.php

# ------------------------------------------------------------ wp importer
# The WordPress Importer is what reads mata-content.xml. It ships as a separate
# plugin, and DISALLOW_FILE_MODS deliberately blocks installing plugins through
# wp-admin — so it is baked in here instead. That is the right place for it
# regardless: the container filesystem is rebuilt on every deploy, so anything
# installed through the browser disappears at the next push.
RUN curl -fsSL https://downloads.wordpress.org/plugin/wordpress-importer.zip -o /tmp/imp.zip  && unzip -q /tmp/imp.zip -d /var/www/html/wp-content/plugins/  && rm -f /tmp/imp.zip

# ---------------------------------------------------- postgres translation
# PG4WP is what lets WordPress speak to Postgres at all. It must be present
# BEFORE the WordPress installer runs, or the installer creates MySQL syntax
# against a database that will reject it.
COPY deploy/render/pg4wp/ /var/www/html/wp-content/pg4wp/
COPY deploy/render/pg4wp/db.php /var/www/html/wp-content/db.php

# ------------------------------------------------------- the site's own code
COPY deploy/render/wp-content/plugins/cogg-mata/ /var/www/html/wp-content/plugins/cogg-mata/
COPY deploy/render/wp-content/themes/mata/   /var/www/html/wp-content/themes/mata/
COPY deploy/render/wp-config.php             /var/www/html/wp-config.php
COPY deploy/render/.htaccess                 /var/www/html/.htaccess
COPY deploy/render/mata-content.xml          /var/www/html/wp-content/mata-content.xml

RUN mkdir -p /var/www/html/wp-content/uploads \
 && chown -R www-data:www-data /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} \; \
 && find /var/www/html -type f -exec chmod 644 {} \;

COPY deploy/render/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
