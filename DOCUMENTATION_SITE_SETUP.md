# Документация по развёртыванию сайтов (site:setup)

Данный документ описывает процесс автоматического создания и настройки нового Laravel-сайта на сервере с aaPanel с помощью команды `site:setup`.

---

## 1. Обзор

Команда `site:setup` выполняет полный цикл развёртывания сайта:

1. Создаёт DNS A-запись через API 1cloud.ru
2. Регистрирует сайт в aaPanel (создаёт nginx-хост)
3. Устанавливает свежий Laravel через `composer create-project`
4. Настраивает `.env` файл из именованного пресета или интерактивно
5. Прописывает PostgreSQL-схему (`DB_SCHEMA` + `search_path`)
6. Запускает `key:generate` и `migrate`
7. Выставляет права доступа для aaPanel (`www:www`)
8. Обновляет nginx-конфиг (document root → `/public`)
9. Выпускает SSL-сертификат Let's Encrypt через aaPanel
10. Добавляет Laravel-планировщик в crontab пользователя `www`

---

## 2. Требования

### Переменные окружения (`.env`)

```env
# aaPanel API
AAPANEL_URL=http://your-server-ip:7800
AAPANEL_KEY=your-aapanel-api-key
AAPANEL_SERVER_IP=1.2.3.4

# 1cloud.ru DNS API
ONECLOUD_TOKEN=your-1cloud-api-token

# Путь к корневой директории сайтов (по умолчанию /www/wwwroot)
PROJECTS_BASE_PATH=/www/wwwroot
```

Где взять ключи:
- **AAPANEL_KEY** — Панель aaPanel → Настройки → API-ключ панели
- **AAPANEL_SERVER_IP** — внешний IP-адрес сервера с aaPanel
- **ONECLOUD_TOKEN** — личный кабинет 1cloud.ru → API

---

## 3. Использование

### Базовый запуск

```bash
php artisan site:setup {domain}
```

### С пресетом базы данных

```bash
php artisan site:setup {domain} {preset}
```

Пресет — именованный набор параметров подключения к БД из `config/presets.json`.

### С пресетом и схемой PostgreSQL

```bash
php artisan site:setup {domain} {preset} {schema}
```

### Полный пример

```bash
php artisan site:setup mysite.our24.ru main_pg crm_schema
```

Что произойдёт:
1. Создаётся DNS-запись `mysite.our24.ru → <AAPANEL_SERVER_IP>`
2. В aaPanel регистрируется сайт `mysite.our24.ru`
3. В `/www/wwwroot/mysite.our24.ru` устанавливается Laravel
4. В `.env` прописываются параметры из пресета `main_pg`
5. В `.env` добавляется `DB_SCHEMA=crm_schema`, в `config/database.php` — `search_path => env('DB_SCHEMA', 'public')`
6. Выполняются `key:generate` и `migrate`
7. Выставляются права, обновляется nginx, выпускается SSL
8. В crontab `www` добавляется запись планировщика

---

## 4. Аргументы и опции

| Параметр | Тип | Описание |
|----------|-----|----------|
| `domain` | аргумент (обязательный) | Полное доменное имя сайта (например, `mysite.our24.ru`) |
| `preset` | аргумент (опциональный) | Название пресета из `config/presets.json` |
| `schema` | аргумент (опциональный) | Схема PostgreSQL (`DB_SCHEMA` + `search_path`) |
| `--php-version` | опция | Версия PHP для aaPanel (по умолчанию `82` = PHP 8.2) |
| `--skip-dns` | флаг | Пропустить создание DNS-записи |
| `--skip-aapanel` | флаг | Пропустить создание сайта в aaPanel |
| `--skip-composer` | флаг | Пропустить `composer create-project` |
| `--skip-ssl` | флаг | Пропустить получение SSL-сертификата |
| `--skip-nginx` | флаг | Пропустить обновление nginx-конфига |
| `--skip-cron` | флаг | Пропустить добавление в crontab |

---

## 5. Пресеты конфигурации

Пресеты хранятся в `config/presets.json`. Каждый пресет — именованный набор параметров для `.env`.

### Формат файла

```json
{
    "preset_name": {
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "5432",
        "DB_DATABASE": "database_name",
        "DB_USERNAME": "username",
        "DB_PASSWORD": "password"
    }
}
```

### Добавление нового пресета

Отредактируйте `config/presets.json`, добавив новый ключ:

```json
{
    "main_pg": {
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "5432",
        "DB_DATABASE": "main_our24",
        "DB_USERNAME": "dev",
        "DB_PASSWORD": "secret"
    },
    "replica_pg": {
        "DB_CONNECTION": "pgsql",
        "DB_HOST": "10.0.0.2",
        "DB_PORT": "5432",
        "DB_DATABASE": "replica_db",
        "DB_USERNAME": "readonly",
        "DB_PASSWORD": "secret"
    }
}
```

### Если пресет не указан

Команда запросит параметры БД интерактивно:
```
Database driver? [pgsql/mysql/sqlite]:
DB_HOST:
DB_PORT:
DB_DATABASE:
DB_USERNAME:
DB_PASSWORD:
```

---

## 6. Флаги `--skip-*` — перезапуск с определённого шага

Флаги полезны, если развёртывание прервалось на каком-то шаге. Позволяют продолжить с нужного места, не повторяя ранее выполненные шаги.

**Пример:** Laravel уже установлен, нужно только настроить SSL и cron:

```bash
php artisan site:setup mysite.our24.ru main_pg \
    --skip-dns \
    --skip-aapanel \
    --skip-composer \
    --skip-nginx
```

**Пример:** Только добавить планировщик в cron:

```bash
php artisan site:setup mysite.our24.ru --skip-dns --skip-aapanel --skip-composer --skip-ssl --skip-nginx
```

---

## 7. Архитектура сервисов

### `App\Services\AaPanelService`

Клиент для aaPanel API.

| Метод | Описание |
|-------|----------|
| `createSite(domain, path, phpVersion)` | Создаёт сайт в aaPanel |
| `applySsl(domain)` | Выпускает Let's Encrypt сертификат |

Аутентификация: `md5(timestamp + md5(apiKey))` — стандартная схема aaPanel API.

### `App\Services\OnecloudService`

Клиент для DNS API 1cloud.ru.

| Метод | Описание |
|-------|----------|
| `findZone(domainName)` | Находит DNS-зону по имени домена |
| `addARecord(zoneId, name, ip, ttl)` | Добавляет A-запись в зону |

Аутентификация: `Authorization: Bearer {token}`.

---

## 8. Структура файлов сайта после установки

```
/www/wwwroot/mysite.our24.ru/
├── app/
├── bootstrap/
├── config/
│   └── database.php     ← search_path = env('DB_SCHEMA', 'public')
├── public/              ← document root в nginx
├── storage/
├── .env                 ← APP_URL, DB_*, DB_SCHEMA
└── ...
```

nginx-конфиг (`/www/server/panel/vhost/nginx/mysite.our24.ru.conf`):
```nginx
root /www/wwwroot/mysite.our24.ru/public;
```

Crontab (`crontab -u www -l`):
```
* * * * * cd /www/wwwroot/mysite.our24.ru && php artisan schedule:run >> /dev/null 2>&1
```

---

## 9. Порядок действий при ошибках

| Ошибка | Причина | Решение |
|--------|---------|---------|
| `AAPANEL_URL is not set` | Не заполнен `.env` | Добавить `AAPANEL_URL` в `.env` |
| `DNS zone not found` | Домен не найден в 1cloud.ru | Проверить `ONECLOUD_TOKEN` и наличие зоны в аккаунте |
| `aaPanel API error: site already exists` | Сайт уже создан в панели | Использовать `--skip-aapanel` |
| `composer create-project failed` | Нет Composer или нет доступа в интернет | Проверить окружение сервера |
| `Could not find root directive in nginx config` | Нестандартный конфиг nginx | Обновить root вручную и пересоздать сайт в aaPanel |
| `SSL certificate failed` | DNS ещё не распространился | Подождать 5–10 минут и перезапустить с `--skip-dns --skip-aapanel --skip-composer --skip-nginx` |
