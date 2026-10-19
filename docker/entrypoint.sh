#!/bin/sh
set -e
cd /var/www/server

# ThinkPHP 只读取 .env 文件（php-fpm 也会清空环境变量），由容器环境变量生成
: > .env
for key in APP_DEBUG APP_URL APP_KEY DB_DRIVER DB_HOST DB_PORT DB_NAME DB_USER DB_PASS DB_PREFIX \
           CACHE_DRIVER QUEUE_DRIVER REDIS_HOST REDIS_PORT REDIS_PASSWORD DEFAULT_LANG DEFAULT_TIMEZONE \
           ADMIN_EMAIL ADMIN_PASSWORD; do
    eval "val=\${$key-__unset__}"
    if [ "$val" != "__unset__" ]; then
        printf '%s = "%s"\n' "$key" "$(printf '%s' "$val" | sed 's/"/\\"/g')" >> .env
    fi
done
chmod 640 .env
chown root:www-data .env

# 未配置 APP_KEY 时生成一次并保存在持久化的 runtime 目录（务必备份，丢失后已加密的 Bot Token 无法解密）
if [ -z "$APP_KEY" ] && [ ! -f runtime/app.key ]; then
    php -r 'echo base64_encode(random_bytes(32));' > runtime/app.key
    chmod 600 runtime/app.key
fi
chown -R www-data:www-data runtime

# 等待数据库
if [ "${DB_DRIVER:-mysql}" = "mysql" ]; then
    i=0
    until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:3306), getenv("DB_USER"), getenv("DB_PASS"));' 2>/dev/null; do
        i=$((i+1))
        [ $i -ge 60 ] && echo "database not reachable" && exit 1
        echo "waiting for database..."; sleep 2
    done
fi

case "$1" in
    web)
        # 只在 Web 容器执行迁移与初始化（可重复执行）
        php think checklist:install
        chown -R www-data:www-data runtime
        exec supervisord -c /etc/supervisord.conf
        ;;
    worker)
        exec su-exec www-data php think queue:work --queue notify,detect --sleep 3 --tries 3
        ;;
    scheduler)
        exec su-exec www-data php think checklist:schedule --loop
        ;;
    telegram)
        exec su-exec www-data php think checklist:telegram-poll
        ;;
    *)
        exec "$@"
        ;;
esac
