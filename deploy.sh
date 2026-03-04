#!/bin/bash
set -e
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

echo "------------ migrate"
php artisan migrate
# php artisan project:menu

echo "------------ swagger"
# php artisan l5-swagger:generate

echo "------------ npm"
npm install @vitejs/plugin-vue
npm install
npm run build

echo "------------ pm2"
pm2 delete 'ssh_tunnel' || true
pm2 start 'bash ssh_tunnel.sh' --watch --name ssh_tunnel

#cd app/Bots
#npm install
#pm2 delete 'chats_mattermost_bot' || true
#pm2 start 'node mattermost_bot.js' --name chats_mattermost_bot
#cd ../..

#cd ../pytalking.our24.ru
# pm2 delete 'pyTalking' || true
# pm2 start 'bash start.sh' --watch --name pyTalking
