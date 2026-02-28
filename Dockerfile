FROM php:8.3-cli

# --------------------------------------------------
# 1. System dependencies + PHP extensions
# --------------------------------------------------
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    unzip \
    curl \
    cron \
    supervisor \
    pngquant \
    optipng \
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis \
    && docker-php-ext-install pdo pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --------------------------------------------------
# 2. Composer
# --------------------------------------------------
RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# --------------------------------------------------
# 3. Set working directory BEFORE copying files
# --------------------------------------------------
WORKDIR /app

# --------------------------------------------------
# 4. Copy app files into container
#    (do this BEFORE composer install so composer.json
#     from your project is what gets used)
# --------------------------------------------------
COPY . /app

# --------------------------------------------------
# 5. Install PHP dependencies from YOUR composer.json
# --------------------------------------------------
RUN composer install --no-dev --optimize-autoloader --no-interaction

# --------------------------------------------------
# 6. Supervisor + cron config
# --------------------------------------------------
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY crontab.txt /etc/cron.d/requeue-cron

RUN chmod 0644 /etc/cron.d/requeue-cron \
    && crontab /etc/cron.d/requeue-cron \
    && touch /var/log/requeue-cron.log

# --------------------------------------------------
# 7. Start supervisor (manages worker.php + cron daemon)
# --------------------------------------------------
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]