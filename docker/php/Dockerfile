# PHP-FPM สำหรับพัฒนา (production จริงรันบน IIS + PHP NTS — ให้ใช้เวอร์ชันและ extension ชุดเดียวกัน)
FROM php:8.3-fpm-bookworm

ENV TZ=Asia/Bangkok \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev \
        fonts-thai-tlwg tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl mbstring bcmath opcache \
    && rm -rf /var/lib/apt/lists/*
# Chromium (สำหรับ export PDF/ภาพ) จะเพิ่มใน M4

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
