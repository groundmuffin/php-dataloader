FROM php:7.1-cli
RUN apt-get update && apt-get install -y libzip-dev zip && docker-php-ext-install zip
RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer

COPY ./composer.json /usr/src/myapp/composer.json
WORKDIR /usr/src/myapp
RUN composer install

COPY ./src /usr/src/myapp/src
COPY ./tests /usr/src/myapp/tests
COPY ./.scrutinizer.yml /usr/src/myapp/.scrutinizer.yml
COPY ./.travis.yml /usr/src/myapp/.travis.yml
COPY ./phpunit.xml.dist /usr/src/myapp/phpunit.xml.dist

CMD ["./vendor/bin/phpunit", "tests"]