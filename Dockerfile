FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Remove all MPM modules and config files
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
          /etc/apache2/mods-available/mpm_worker.load \
          /etc/apache2/mods-available/mpm_event.load

# Enable only mpm_prefork
RUN a2enmod mpm_prefork

# Enable required PHP extensions
RUN docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql gd

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
