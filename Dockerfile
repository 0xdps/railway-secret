# ── Stage: build-core ────────────────────────────────────────────────────────
# Builds the mesahub-server Go binary for embedded mode.
# Override MESAHUB_CORE_VERSION to pin a specific commit:
#   docker build --build-arg MESAHUB_CORE_VERSION=<git-sha> .
FROM golang:1.24-alpine AS build-core
RUN apk add --no-cache gcc musl-dev sqlite-dev git
ARG MESAHUB_CORE_VERSION=faef918de9c4c8eb0d3098adbc219379fa6830a5
RUN git clone --filter=blob:none --no-checkout https://github.com/0xdps/mesahub-core.git /mesahub-core \
 && git -C /mesahub-core fetch --depth 1 origin ${MESAHUB_CORE_VERSION} \
 && git -C /mesahub-core checkout --detach FETCH_HEAD
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
