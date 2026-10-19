# ---------- 前端构建 ----------
FROM node:22-alpine AS web
WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
RUN npm run build

# ---------- PHP 依赖 ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---------- 运行镜像 ----------
FROM php:8.3-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis intl bcmath opcache pcntl \
    && apk add --no-cache nginx supervisor tzdata su-exec \
    && mkdir -p /run/nginx

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www/server
COPY server/ ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=web /web/dist /var/www/web

RUN rm -f .env \
    && php think service:discover \
    && php think vendor:publish \
    && mkdir -p runtime \
    && chown -R www-data:www-data runtime \
    && chmod +x /usr/local/bin/entrypoint.sh

ENV TZ=UTC
EXPOSE 80
VOLUME ["/var/www/server/runtime"]
ENTRYPOINT ["entrypoint.sh"]
# 默认：Web（nginx + php-fpm）。worker / scheduler / telegram 容器通过 command 覆盖
CMD ["web"]
