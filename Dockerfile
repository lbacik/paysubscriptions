# syntax=docker/dockerfile:1

# Comments are provided throughout this file to help you get started.
# If you need more help, visit the Dockerfile reference guide at
# https://docs.docker.com/go/dockerfile-reference/

################################################################################

# FrankenPHP is the production runtime; there is only one stage, so release.yml
# needs no `target:`.
#
# Pinned to the FrankenPHP 1.x major and the PHP 8.4 minor rather than a patch:
# the 8.4 matches .php-version, the PHP_VERSION that test.yml/quality.yml pin CI
# to, and the 8.4 the rest of the suite runs. Keep those four in step - CI is
# only a gate if it runs the same interpreter as production.
FROM dunglas/frankenphp:1-php8.4

ENV COMPOSER_ALLOW_SUPERUSER=1

# Serve plain HTTP on :80 and leave TLS to nginx-proxy / acme-companion in front,
# as the rest of the suite does. The image defaults SERVER_NAME to `localhost`,
# which switches on Caddy's automatic HTTPS with a self-signed internal
# certificate - a listener the proxy cannot talk to.
ENV SERVER_NAME=:80

# The image's Caddyfile resolves `{$SERVER_ROOT:public/}` against the working
# directory. This app lives in /opt/app rather than the image's default /app, so
# name the document root outright instead of relying on the cwd.
ENV SERVER_ROOT=/opt/app/public

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy app files from the app directory.
COPY . /opt/app
WORKDIR /opt/app

# Your PHP application may require additional PHP extensions to be installed
# manually. For detailed instructions for installing extensions can be found, see
# https://github.com/docker-library/docs/tree/master/php#how-to-install-more-php-extensions
# The following code blocks provide examples that you can edit and use.
#
# Add core PHP extensions, see
# https://github.com/docker-library/docs/tree/master/php#php-core-extensions
# This example adds the apt packages for the 'gd' extension's dependencies and then
# installs the 'gd' extension. For additional tips on running apt-get:
# https://docs.docker.com/go/dockerfile-aptget-best-practices/
#
# opcache is absent from the list below on purpose: the FrankenPHP image already
# builds and enables it (conf.d/docker-php-ext-opcache.ini), unlike php:8.4-apache
# which shipped it disabled and needed the explicit install.
RUN apt -y update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    librabbitmq-dev \
    libssl-dev \
    && apt -y clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* \
    && rm -rf /var/cache/apk/* \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && docker-php-ext-configure \
    intl \
	&& docker-php-ext-install \
    pdo_mysql \
    intl

# Add PECL extensions, see
# https://github.com/docker-library/docs/tree/master/php#pecl-extensions
# This example adds the 'redis' and 'xdebug' extensions.
# RUN pecl install redis-5.3.7 \
#    && pecl install xdebug-3.2.1 \
#    && docker-php-ext-enable redis xdebug

# Use the default production configuration for PHP runtime arguments, see
# https://github.com/docker-library/docs/tree/master/php#configuration
# RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# The production image contains only what production runs: --no-dev drops the
# test tooling (PHPUnit, fixtures, profiler) and --optimize-autoloader dumps
# the classmap the prod autoloader uses. The release-gates CI job replays
# this resolution (composer install --no-dev --dry-run), so a dev-only
# dependency breaks the gate before it can ship a broken image.
#
# APP_ENV is set explicitly because the committed .env says `dev`: without this
# the composer auto-scripts (cache:clear) and the console commands below boot the
# dev kernel, which registers DebugBundle - a package --no-dev just left out.
ENV APP_ENV=prod

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && ./bin/console tailwind:build \
    && ./bin/console assets:install \
    && ./bin/console asset-map:compile

# FrankenPHP is the web server, so there is no /var/www/html to point at
# /opt/app/public and no mod_rewrite to enable: the Caddyfile's `php_server`
# directive serves SERVER_ROOT and falls back to public/index.php for every
# unmatched path.
#
# public/.htaccess (from symfony/apache-pack) is deleted rather than left in
# place. Caddy never reads it, but it does *serve* it: Apache treated .htaccess
# as configuration and refused to send it, while to a file server it is just
# another file under the document root, and `GET /.htaccess` would return the
# app's rewrite rules with a 200. Removing symfony/apache-pack from composer.json
# is the tidier long-term fix; this keeps the image correct meanwhile.
#
# CAP_NET_BIND_SERVICE is what lets the unprivileged user below bind port 80. It
# was not needed under Apache, where a root master process bound the port and
# dropped to www-data by itself; FrankenPHP has no such split, the whole server
# runs as USER.
#
# /data and /config are Caddy's XDG_DATA_HOME and XDG_CONFIG_HOME (set by the
# base image); it writes to both on startup, so www-data has to own them.
RUN rm -f /opt/app/public/.htaccess \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data /opt/app/var /data /config

# The base image healthchecks Caddy's admin API on :2019. Drop it rather than
# inherit it, which also keeps php:8.4-apache's behaviour of shipping no probe at
# all: health belongs in the compose files, next to the service it guards, and
# compose.prod.yaml declares the one that matters for `up --wait`. Inheriting it
# would additionally mark every one-shot `compose run ... web ./bin/console ...`
# container unhealthy - the migration step in deploy.yml is one - since a console
# command starts no server for the probe to reach.
HEALTHCHECK NONE

# Switch to a non-privileged user (defined in the base image) that the app will run under.
# See https://docs.docker.com/go/dockerfile-user-best-practices/
USER www-data
