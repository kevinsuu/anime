FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app

COPY public ./public
COPY src ./src
COPY database ./database
COPY tests ./tests

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
