# ==============================================================================
# ISG Load Balancer: Turnkey Production Gateway Dockerfile (app03)
# Base: PHP 8.3 FPM on Debian 12 (Bookworm)
# Multi-platform: Linux x86_64, ARM64 (AWS Graviton, Apple Silicon)
# Drivers: SQL Server (sqlsrv/pdo_sqlsrv), Redis, MySQL, PostgreSQL
# ==============================================================================

FROM php:8.3-fpm-bookworm AS base

ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /var/www/html

# 1. Install OS tools, Nginx, and Microsoft ODBC Driver 18 for MSSQL
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    gnupg2 \
    ca-certificates \
    apt-transport-https \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    supervisor \
    nginx \
    unixodbc-dev \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && rm -rf /var/lib/apt/lists/*

# 2. Compile and install PHP extensions (MSSQL sqlsrv, Redis, Opcache, BCMath)
RUN pecl install sqlsrv pdo_sqlsrv redis \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv redis \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        mbstring \
        opcache \
        pdo \
        pdo_mysql \
        pcntl \
        sockets \
        zip

# 3. Production PHP & Opcache configurations
RUN { \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
        echo 'opcache.fast_shutdown=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=10M'; \
        echo 'post_max_size=10M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/security-tuning.ini \
    && { \
        echo '[www]'; \
        echo 'pm = dynamic'; \
        echo 'pm.max_children = 64'; \
        echo 'pm.start_servers = 16'; \
        echo 'pm.min_spare_servers = 8'; \
        echo 'pm.max_spare_servers = 32'; \
        echo 'pm.max_requests = 2000'; \
    } > /usr/local/etc/php-fpm.d/zz-docker-tuning.conf

# 4. Copy Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5. Copy Nginx and Supervisor configs
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 6. Copy full Laravel application code
COPY . /var/www/html

# 7. Install production dependencies and optimize
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 8. Expose standard HTTP/HTTPS ports
EXPOSE 80 443

# 9. Start Nginx and PHP-FPM via Supervisord
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
