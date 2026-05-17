FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    zlib1g-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Disable conflicting MPM modules
RUN a2dismod mpm_prefork mpm_worker mpm_event 2>/dev/null || true

# Enable mpm_prefork explicitly
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
