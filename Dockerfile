FROM php:8.2-apache

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    poppler-utils \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Habilitar módulos de PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Habilitar mod_rewrite de Apache
RUN a2enmod rewrite

# Configurar Apache para Railway (puerto dinámico)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Configurar DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Copiar archivos de la aplicación
COPY . /var/www/html/

# Crear directorios necesarios con permisos
RUN mkdir -p /var/www/html/clients \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/clients

# Permitir .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Puerto por defecto (Railway lo sobrescribe)
ENV PORT=8080
EXPOSE 8080

# Comando de inicio
CMD ["apache2-foreground"]
