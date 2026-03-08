FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    curl \
    sqlite-dev \
    openssl-dev

# Install PHP extensions
RUN docker-php-ext-install bcmath
# SQLite3 and OpenSSL are usually built-in to the base image, but we ensure functionality

# Configure Nginx
COPY .docker/nginx.conf /etc/nginx/http.d/default.conf

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Ensure storage is writable
RUN mkdir -p /var/www/html/storage/db && \
    chown -R www-data:www-data /var/www/html/storage && \
    chmod -R 770 /var/www/html/storage

# Install crontab
RUN crontab .docker/crontab

# Make the start script executable
RUN chmod +x .docker/start.sh

# Expose port
EXPOSE 8080

# Use the start script — it launches crond, php-fpm, and nginx
CMD ["sh", ".docker/start.sh"]
