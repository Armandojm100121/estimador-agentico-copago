FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html/

# El servidor embebido de PHP atiende UNA petición a la vez por defecto. Mientras
# espera a Groq (hasta 40 s) los demás pacientes quedarían bloqueados. Con varios
# workers atiende peticiones en paralelo. Se puede sobrescribir desde Railway.
ENV PHP_CLI_SERVER_WORKERS=4

# Escuchar en [::] (IPv6 dual-stack) - obligatorio en Railway porque su red interna es IPv6
CMD ["sh", "-c", "php -S [::]:${PORT} -t /var/www/html"]
