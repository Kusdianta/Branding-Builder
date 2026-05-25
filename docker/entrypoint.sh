#!/bin/sh
# Runtime bootstrap. config:cache runs HERE (not at build) so the real APP_KEY
# (.env.shared), per-service env, and vault mounts are present — caching an
# empty APP_KEY at build time would break Crypt/vault decryption + sessions.
set -e
cd /app

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
    chown www-data:www-data database/database.sqlite 2>/dev/null || true
fi

php artisan migrate --force || true
php artisan storage:link 2>/dev/null || true

php artisan config:cache || true
php artisan event:cache  || true
php artisan route:cache  || true
php artisan view:cache   || true

exec "$@"
