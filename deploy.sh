#!/bin/bash
set -e
export COMPOSER_ALLOW_SUPERUSER=1
# ✅ Задаём алиас на PHP 8.2
# alias php="/opt/php82/bin/php"

# или вариант с приоритетом в PATH:
# export PATH="/opt/php82/bin:$PATH"

echo "------------ whoami"
whoami
ls -la

echo "------------ php"
php -v

echo "------------ composer"
#php /usr/local/bin/composer install
composer install

echo "------------ pm2"
export PATH=$PATH:/usr/local/bin:/usr/bin:/root/.npm-global/bin
command -v pm2 >/dev/null 2>&1 || { echo "pm2 not found, skipping..."; }

if command -v pm2 >/dev/null 2>&1; then
    pm2 delete 'ssh_tunnel' || true
    pm2 start 'bash ssh_tunnel.sh' --watch --name ssh_tunnel

    pm2 delete 'git_merge_watcher' || true
    pm2 start 'php artisan git:merge-watcher' --name git_merge_watcher
fi

echo "------------ migrate"
php artisan migrate
# php artisan project:menu

echo "------------ swagger"
# php artisan l5-swagger:generate

echo "------------ npm"
npm install @vitejs/plugin-vue
npm install
npm run build



#cd app/Bots
#npm install
#pm2 delete 'chats_mattermost_bot' || true
#pm2 start 'node mattermost_bot.js' --name chats_mattermost_bot
#cd ../..

COMMIT_INFO=$(git log -1 --format="%ad %h %s %an" --date=format:"%d.%m %H:%M")
curl -G --data-urlencode "message=Релиз install.our24.ru на проде: $COMMIT_INFO" https://aider.our24.ru/send

cd ../pytalking.our24.ru
pm2 delete 'pyTalking' || true
pm2 start 'bash start.sh' --watch --name pyTalking
curl -G --data-urlencode "message=pyTalking перезапущен" https://aider.our24.ru/send

