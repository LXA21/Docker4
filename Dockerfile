FROM php:8.2-apache

# Instalar extensiones de PHP necesarias para MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copiar el código fuente de tu sistema a la carpeta pública de Apache
COPY ./src/ /var/www/html/

# Dar permisos adecuados
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80