# Telegram Bot — документация

## Обзор

Бот интегрирован в Laravel-проект как фоновый демон (`php artisan telegram:listen`), управляемый через PM2. Архитектура двунаправленная:

- **Сайты → Telegram**: сайты пишут сигналы в Redis → бот читает и отправляет уведомления
- **Telegram → Сайты**: пользователь отправляет команду → бот пишет в Redis → сайт читает и выполняет

---

## Быстрый старт

### 1. Создать бота

В Telegram открыть [@BotFather](https://t.me/BotFather), создать бота командой `/newbot`, скопировать токен.

### 2. Узнать свой chat_id

Написать боту [@userinfobot](https://t.me/userinfobot) — он вернёт ваш числовой ID.

### 3. Настроить `.env`

```env
TELEGRAM_BOT_TOKEN=1234567890:AAEhBOwXtfGE...
TELEGRAM_ALLOWED_CHAT_IDS=123456789,987654321
TELEGRAM_POLL_INTERVAL=2
```

| Переменная | Описание |
|---|---|
| `TELEGRAM_BOT_TOKEN` | Токен бота от @BotFather |
| `TELEGRAM_ALLOWED_CHAT_IDS` | Whitelist chat ID через запятую. Пустое = без ограничений |
| `TELEGRAM_POLL_INTERVAL` | Пауза (секунды) между итерациями опроса. Default: `2` |

### 4. Запустить

```bash
# Вручную (для теста)
php artisan telegram:listen

# Через PM2 (продакшн)
pm2 start 'php artisan telegram:listen' --name telegram_bot
pm2 save
```

Deploy-скрипт делает это автоматически:

```bash
bash deploy.sh
```

---

## Команды бота

| Команда | Описание |
|---|---|
| `/start` | Приветственное сообщение |
| `/help` | Список всех доступных команд |
| `/status {project}` | Показать текущий Redis-сигнал для проекта |
| `/deploy {project}` | Запросить деплой для проекта |
| `/sites` | Список всех проектов с активными сигналами в Redis |

### Примеры

```
/status ourcrm
→ ourcrm — active signal:
  {"action":"deploy","requested_by":123456789,...}

/deploy aider
→ 🚀 Deploy requested for aider.
  The site will pick it up on its next Redis poll.

/sites
→ Active signals:
  • ourcrm/commands  {"action":"deploy",...}
  • install/commands {"action":"sync",...}
```

---

## Redis-команды

### Формат ключа

```
{project}/commands
```

Где `{project}` — имя проекта/сайта, например: `ourcrm`, `aider`, `install`.

> **Важно:** ключи хранятся **без префикса**. Бот использует отдельное Redis-соединение `telegram` с `prefix = ''`, чтобы ключи совпадали с теми, что пишут другие сайты.

### Направление: Сайт → Telegram

Сайт пишет сигнал в Redis:

```bash
redis-cli SET ourcrm/commands '{"action":"deploy","status":"started","commit":"abc123"}'
```

Бот при следующей итерации (≤ 2 секунды):
1. Читает ключ (`GET ourcrm/commands`)
2. Отправляет уведомление во все чаты из `TELEGRAM_ALLOWED_CHAT_IDS`
3. Удаляет ключ (`DEL ourcrm/commands`)

Сообщение в Telegram будет выглядеть так:

```
📡 Signal from [ourcrm]
Action: deploy

{
  "action": "deploy",
  "status": "started",
  "commit": "abc123"
}
```

### Направление: Telegram → Сайт

Пользователь пишет `/deploy ourcrm`. Бот записывает в Redis:

```json
{
  "action": "deploy",
  "requested_by": 123456789,
  "requested_at": "2026-03-13T12:00:00+00:00"
}
```

Сайт `ourcrm` на своём цикле читает ключ:

```bash
redis-cli GET ourcrm/commands
# → {"action":"deploy","requested_by":123456789,...}

# После обработки — удалить ключ
redis-cli DEL ourcrm/commands
```

### Структура payload

Рекомендуемый формат JSON:

```json
{
  "action": "deploy | sync | restart | ...",
  "status": "started | done | error",
  "data":   { ... },
  "requested_by": 123456789,
  "requested_at": "2026-03-13T12:00:00+00:00"
}
```

Поле `action` используется ботом для форматирования уведомления. Остальные поля — произвольные.

---

## Архитектура

```
app/
  Console/Commands/
    TelegramListenCommand.php     — Artisan-демон (infinite loop)
  Services/
    TelegramBotService.php        — обёртка над Telegram Bot API
  Telegram/
    CommandDispatcher.php         — регистрация и маршрутизация команд
    RedisSignalProcessor.php      — обработка входящих сигналов из Redis
    Commands/
      TelegramCommandInterface.php
      StartCommand.php
      HelpCommand.php
      StatusCommand.php
      DeployCommand.php
      SitesCommand.php
config/
  telegram.php                    — конфигурация бота
```

### Цикл работы демона

```
telegram:listen
  └─ while (true):
       ├─ TelegramBotService::fetchUpdates()
       │    └─ Telegram getUpdates (offset polling)
       │         → проверка whitelist
       │         → CommandDispatcher::dispatch()
       │              → XxxCommand::handle($args, $chatId)
       │         → TelegramBotService::sendMessage()
       │
       ├─ RedisSignalProcessor::processSignals()
       │    └─ Redis KEYS */commands
       │         → GET key
       │         → TelegramBotService::broadcast()
       │         → DEL key
       │
       └─ sleep(TELEGRAM_POLL_INTERVAL)
```

---

## Добавить новую команду

1. Создать класс в `app/Telegram/Commands/`:

```php
<?php

namespace App\Telegram\Commands;

class RestartCommand implements TelegramCommandInterface
{
    public function getName(): string        { return 'restart'; }
    public function getDescription(): string { return 'Restart a project: /restart {project}'; }

    public function handle(array $args, int $chatId): string
    {
        $project = $args[0] ?? null;
        if (!$project) return 'Usage: /restart {project}';

        \Illuminate\Support\Facades\Redis::connection('telegram')
            ->set("{$project}/commands", json_encode(['action' => 'restart']));

        return "♻️ Restart requested for <b>{$project}</b>.";
    }
}
```

2. Зарегистрировать в `config/telegram.php`:

```php
'commands' => [
    // ... существующие команды ...
    \App\Telegram\Commands\RestartCommand::class,
],
```

Перезапускать PM2 или менять другие файлы не нужно — dispatcher читает конфиг при старте.

---

## PM2

Процесс называется `telegram_bot`. Основные команды:

```bash
pm2 status telegram_bot          # статус
pm2 logs telegram_bot            # логи в реальном времени
pm2 logs telegram_bot --lines 50 # последние 50 строк
pm2 reload telegram_bot          # zero-downtime перезапуск
pm2 stop telegram_bot            # остановить
pm2 restart telegram_bot         # полный перезапуск
```

Процесс автоматически стартует/перезапускается при деплое через `bash deploy.sh`.

---

## Диагностика

### Бот не отвечает

```bash
pm2 status telegram_bot   # проверить, что процесс running
pm2 logs telegram_bot     # смотреть ошибки
php artisan telegram:listen  # запустить вручную для дебага
```

### Сигнал не доставляется

```bash
# Проверить, что ключ существует
redis-cli GET ourcrm/commands

# Проверить соединение
redis-cli PING

# Посмотреть все ключи проектов
redis-cli KEYS '*/commands'
```

### Ошибка "token not supplied"

`TELEGRAM_BOT_TOKEN` не задан в `.env`. Добавить токен и перезапустить:

```bash
pm2 reload telegram_bot
```

### Бот отвечает "Rejected message from unauthorized chat"

Chat ID не добавлен в `TELEGRAM_ALLOWED_CHAT_IDS`. Узнать ID можно у [@userinfobot](https://t.me/userinfobot), добавить в `.env` и перезапустить бота.
