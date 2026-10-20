FROM ghcr.io/roadrunner-server/roadrunner:3.0.0 AS roadrunner
FROM golang:1.25-bookworm AS client
RUN CGO_ENABLED=0 go install github.com/fullstorydev/grpcurl/cmd/grpcurl@v1.9.3

FROM php:8.4-cli-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libonig-dev libxml2-dev unzip ca-certificates \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring sockets pcntl bcmath dom xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=client /go/bin/grpcurl /usr/local/bin/grpcurl
WORKDIR /app
ENV APP_ENV=production APP_DEBUG=false GRPC_ADDRESS=0.0.0.0:50051 PUBLISH_CONCURRENCY=4

FROM base AS build
RUN apt-get update && apt-get install -y --no-install-recommends protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*
COPY . .
RUN mkdir -p generated bootstrap/cache storage/logs \
    && protoc -I api --php_out=generated api/queue/v1/queue.proto \
    && composer validate --strict --no-check-publish \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM build AS test
RUN composer install --no-interaction --prefer-dist
CMD ["vendor/bin/phpunit"]

FROM base AS runtime
COPY --from=build --chown=10001:10001 /app /app
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin lab
USER 10001:10001
CMD ["rr", "serve", "-c", ".rr.yaml"]
