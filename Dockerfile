# syntax=docker/dockerfile:1

# Comments are provided throughout this file to help you get started.
# If you need more help, visit the Dockerfile reference guide at
# https://docs.docker.com/go/dockerfile-reference/

# Want to help us make this template better? Share your feedback here: https://forms.gle/ybq9Krt8jtBL3iCk7

################################################################################

# The PHP Apache image is the production runtime; there is only one stage, so
# release.yml needs no `target:`.
#
# Pinned to the 8.4 minor rather than a patch: it matches .php-version, the
# PHP_VERSION that test.yml/quality.yml pin CI to, and the 8.4 the rest of the
# suite runs. Keep those four in step - CI is only a gate if it runs the same
# interpreter as production.
FROM php:8.4-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

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
    opcache \
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

RUN composer install --no-interaction --no-progress \
    && ./bin/console tailwind:build \
    && ./bin/console assets:install \
    && ./bin/console asset-map:compile

RUN rm -drf /var/www/html \
    && ln -s /opt/app/public /var/www/html \
    && chown -R www-data:www-data /opt/app/var \
    && a2enmod rewrite
#    && a2enmod headers

# Switch to a non-privileged user (defined in the base image) that the app will run under.
# See https://docs.docker.com/go/dockerfile-user-best-practices/
USER www-data
