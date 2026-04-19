FROM php:8.3-fpm-alpine

# Set working directory safely
WORKDIR /var/www/html

# Install core OS dependencies and alpine-specific development headers
RUN apk update && apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite \
    sqlite-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install isolated PHP extensions natively tracking sockets and maths
RUN docker-php-ext-install pdo pdo_sqlite pdo_mysql bcmath sockets pcntl mbstring exif

# Inject Pecl Redis explicitly safely against alpine architectures
RUN pecl install redis && docker-php-ext-enable redis

# Erase heavy compiler deps preserving the ultra-lightweight Alpine footprint
RUN apk del $PHPIZE_DEPS linux-headers

# Grab latest Composer injection completely autonomously
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Map standard developer privileges using Alpine's adduser layout
RUN adduser -D -u 1000 -h /home/dev -G www-data dev
RUN mkdir -p /home/dev/.composer && \
    chown -R dev:www-data /home/dev

USER dev
