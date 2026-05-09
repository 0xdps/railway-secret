# ── Stage: build-core ────────────────────────────────────────────────────────
# Builds the mesahub-server Go binary for embedded mode.
# Override MESAHUB_CORE_VERSION to pin a specific commit/tag:
#   docker build --build-arg MESAHUB_CORE_VERSION=v1.0.0 .
FROM golang:1.24-alpine AS build-core
RUN apk add --no-cache gcc musl-dev sqlite-dev git
ARG MESAHUB_CORE_VERSION=trunk
RUN git clone --depth 1 --branch ${MESAHUB_CORE_VERSION} \
    https://github.com/0xdps/mesahub-core.git /mesahub-core
WORKDIR /mesahub-core/server
RUN CGO_ENABLED=1 GOOS=linux go build -o /go/bin/mesahub-server ./cmd/server

# ── Stage: app ───────────────────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

# Grab Composer binary from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy mesahub-server binary (for embedded mode)
COPY --from=build-core /go/bin/mesahub-server /usr/local/bin/mesahub-server

# Install system dependencies (openssl for random token generation in start.sh)
RUN apk add --no-cache \
    nginx \
    curl \
    openssl

# Configure Nginx
COPY .docker/nginx.conf /etc/nginx/http.d/default.conf

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies (no dev packages, optimise autoloader)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Persistent data directory for embedded mesahub-server
RUN mkdir -p /data

# Install crontab
RUN crontab .docker/crontab

# Make the start script executable
RUN chmod +x .docker/start.sh

# Expose port
EXPOSE 8080

# Use the start script — it launches mesahub (if embedded), crond, php-fpm, and nginx
CMD ["sh", ".docker/start.sh"]
