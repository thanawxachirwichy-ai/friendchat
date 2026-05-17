FROM php:8.2-apache

# Enable required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql gd

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create upload directories
RUN mkdir -p uploads profile && chmod -R 777 uploads profile

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
