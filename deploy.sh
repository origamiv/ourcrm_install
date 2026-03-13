#!/bin/bash
set -e
export COMPOSER_ALLOW_SUPERUSER=1

echo "------------ USER & PATH"
whoami
echo "PATH=$PATH"

# -------------------------
# PHP (если нужно выбрать версию)
# -------------------------
# export PATH="/opt/php82/bin:$PATH"
php -v

# -------------------------
# Composer
# -------------------------
echo "------------ composer"
composer install --no-interaction --optimize-autoloader --no-dev

# -------------------------
# Node/NPM/PM2 через NVM
# -------------------------
echo "------------ node/npm/pm2"

export NVM_DIR="/www/server/nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
nvm use default || nvm use 24

# на всякий случай добавляем PATH
export PATH="/www/server/nvm/versions/node/v24.14.0/bin:$PATH"

echo "Node: $(node -v)"
echo "NPM: $(npm -v)"
echo "PM2: $(pm2 -v || echo 'pm2 not found')"

# -------------------------
# PM2 процессы
# -------------------------
if command -v pm2 >/dev/null 2>&1; then
    # ssh_tunnel
    pm2 describe ssh_tunnel >/dev/null 2>&1 && pm2 reload ssh_tunnel || pm2 start 'bash ssh_tunnel.sh' --watch --name ssh_tunnel

    # git_merge_watcher
    pm2 describe git_merge_watcher >/dev/null 2>&1 && pm2 reload git_merge_watcher || pm2 start 'php artisan git:merge-watcher' --name git_merge_watcher
fi

# -------------------------
# Laravel migrate
# -------------------------
echo "------------ migrate"
php artisan migrate --force

# -------------------------
# npm install & build
# -------------------------
echo "------------ npm install & build"
npm install @vitejs/plugin-vue --save-dev
npm install
npm run build

# -------------------------
# Отправка уведомлений о релизе
# -------------------------
COMMIT_INFO=$(git log -1 --format="%ad %h %s %an" --date=format:"%d.%m %H:%M")
curl -G --data-urlencode "message=Релиз install.our24.ru на проде: $COMMIT_INFO" https://aider.our24.ru/send

# -------------------------
# pyTalking
# -------------------------
echo "------------ pyTalking"
cd ../pytalking.our24.ru
pm2 describe pyTalking >/dev/null 2>&1 && pm2 reload pyTalking || pm2 start 'bash start.sh' --watch --name pyTalking
curl -G --data-urlencode "message=pyTalking перезапущен" https://aider.our24.ru/send

echo "------------ DEPLOY DONE"
