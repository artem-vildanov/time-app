FROM php:8.1.17-cli-alpine

RUN apk update && apk add --no-cache \
    curl \
    unzip \
    git

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN composer --version

WORKDIR /var/www/app

COPY . .

RUN composer install

CMD ["php", "index.php"]