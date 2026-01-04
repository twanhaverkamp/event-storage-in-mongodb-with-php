apk add --update --no-cache $PHPIZE_DEPS linux-headers libressl-dev
pecl install mongodb xdebug

docker-php-ext-enable mongodb xdebug

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer install --dev

tail -f /dev/null
