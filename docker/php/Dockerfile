# WAFFRLI local development image: PHP 8.3 (FPM + CLI) on Ubuntu 24.04 LTS.
# One image runs the app (FPM), the queue worker, the scheduler and the ONE
# shared Reverb server. Development only — not a production image.
#
# Ubuntu's own PHP 8.3 packages are used (no PECL compilation), including
# phpredis; Composer comes from the official composer image.
FROM ubuntu:24.04

ARG UID=1000
ARG GID=1000
ENV DEBIAN_FRONTEND=noninteractive

# Optional extra CA certificates (e.g. a corporate TLS-inspecting proxy):
# drop *.crt files in docker/certs/ (git-ignored) before building.
COPY docker/certs/ /usr/local/share/ca-certificates/extra/

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && update-ca-certificates \
    && apt-get install -y --no-install-recommends \
        php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-bcmath php8.3-intl \
        php8.3-gd php8.3-zip php8.3-mbstring php8.3-xml php8.3-curl php8.3-redis php8.3-opcache \
        git unzip curl mysql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /etc/php/8.3/fpm/conf.d/99-waffrli.ini
COPY docker/php/php.ini /etc/php/8.3/cli/conf.d/99-waffrli.ini
COPY docker/php/www.conf /etc/php/8.3/fpm/pool.d/www.conf

# Files written from containers belong to the developer's WSL user.
RUN (getent passwd "${UID}" | cut -d: -f1 | grep -qvx www-data && userdel -r "$(getent passwd "${UID}" | cut -d: -f1)" || true) \
    && groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g www-data www-data \
    && mkdir -p /var/www/html /var/www/.composer /run/php && chown -R www-data:www-data /var/www /run/php \
    && ln -s /usr/sbin/php-fpm8.3 /usr/local/bin/php-fpm \
    && sed -i -e 's|^error_log = .*|error_log = /proc/self/fd/2|' -e 's|^pid = .*|pid = /run/php/php-fpm.pid|' /etc/php/8.3/fpm/php-fpm.conf

WORKDIR /var/www/html
USER www-data

EXPOSE 9000 8080
CMD ["php-fpm", "--nodaemonize", "--force-stderr"]
