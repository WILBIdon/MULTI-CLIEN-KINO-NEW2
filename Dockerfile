FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    poppler-utils \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Habilitar módulos de PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Habilitar mod_rewrite de Apache y FIX para MPM conflict
RUN a2dismod mpm_event && a2enmod mpm_prefork && a2enmod rewrite

# Copiar archivos de la aplicación
COPY . /var/www/html/

# Crear directorios necesarios con permisos
RUN mkdir -p /var/www/html/clients \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/clients

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Script de inicio que configura el puerto dinámicamente y arregla MPM
RUN echo '#!/bin/bash\n\
    rm -f /etc/apache2/mods-enabled/mpm_event.* 2>/dev/null\n\
    sed -i "s/80/${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf\n\
    sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf\n\
    apache2-foreground' > /start.sh && chmod +x /start.sh

EXPOSE 8080

CMD ["/bin/bash", "/start.sh"]
