# syntax=docker/dockerfile:1.7
# ============================================================================
# branding-builder — Laravel 13 + Livewire 4/Volt + Anthropic SDK + dompdf.
# Auth via Hub SSO. Multi-stage, multi-arch (linux/arm64, Synology DS223j).
#
# BUILD CONTEXT = WORKSPACE ROOT: composer pulls
#   nema/ui-kit        from ../nema-ui-kit        (path repo)
#   nema/worker-client from ../nema-worker-client (path repo)
# Build with:
#   docker buildx build --platform linux/arm64 -f branding-builder/Dockerfile -t branding-builder .
# ============================================================================

# PHP 8.4 (not 8.3): the committed composer.lock pulls Symfony 8 (symfony/clock
# requires php >=8.4), so vendor/composer/platform_check.php aborts at runtime on
# 8.3. Match the runtime to the lock.
ARG PHP_BASE=dunglas/frankenphp:1-php8.4-alpine

# ---- Stage 1: Composer vendor (build-host arch) ----------------------------
FROM --platform=$BUILDPLATFORM composer:2 AS vendor
ENV COMPOSER_MIRROR_PATH_REPOS=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY nema-ui-kit /nema-ui-kit
COPY nema-worker-client /nema-worker-client
COPY branding-builder/composer.json branding-builder/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader \
        --prefer-dist --ignore-platform-reqs
COPY branding-builder/ ./
# composer.json marks the nema/* path repos symlink:true (and MIRROR_PATH_REPOS
# does NOT override that) → vendor/nema/* would be symlinks dangling in the
# runtime image. Replace each with a real copy before dumping the autoloader.
RUN for d in vendor/nema/*; do \
        if [ -L "$d" ]; then t="$(readlink -f "$d")"; rm "$d"; cp -r "$t" "$d"; fi; \
    done \
 && composer dump-autoload --no-dev --optimize

# ---- Stage 2: Frontend assets (build-host arch) ----------------------------
FROM --platform=$BUILDPLATFORM node:22-alpine AS assets
WORKDIR /app
COPY branding-builder/package.json branding-builder/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY branding-builder/ ./
# app.css imports tokens from the sibling nema-ui-kit via ../../../nema-ui-kit.
COPY nema-ui-kit /nema-ui-kit
RUN npm run build

# ---- Stage 3: Runtime (FrankenPHP, target arch) ----------------------------
FROM ${PHP_BASE} AS app
WORKDIR /app

RUN apk add --no-cache curl sqlite icu-data-full \
    && install-php-extensions pdo_sqlite intl mbstring opcache zip gd

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo "opcache.enable=1"; \
        echo "opcache.enable_cli=0"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "memory_limit=256M"; \
        echo "expose_php=Off"; \
    } > "$PHP_INI_DIR/conf.d/zz-nema.ini"

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

COPY branding-builder/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY branding-builder/docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
