# CLAUDE.md — Инструкции для Claude Code

## Описание проекта

Проект `ourcrm_install` — установщик/инсталлятор для OurCRM на базе Laravel.

## Рабочий процесс (Workflow)

### Ветки

- Основная ветка: `master`
- Ветки для разработки: `claude/<описание>-<session-id>`

### После завершения работы

После того как все изменения закоммичены и запушены в текущую рабочую ветку, необходимо выполнить следующие шаги:

#### 1. Создать Pull Request в ветку `master`

```bash
gh pr create --base master --head <текущая-ветка> --title "<описание изменений>" --body "<подробное описание>"
```

#### 2. Вызвать endpoint деплоя

После создания PR выполнить HTTP-запрос для запуска процесса слияния:

```
GET https://install.our24.ru/merge/install/master?from=<текущая-ветка>
```

Пример для ветки `claude/add-claude-instructions-ovptn`:

```
GET https://install.our24.ru/merge/install/master?from=claude/add-claude-instructions-ovptn
```

#### 3. Вывести ответ метода

Получить и отобразить ответ от endpoint'а пользователю.

---

## Команды разработки

### Laravel / PHP

```bash
# Установка зависимостей
composer install

# Запуск тестов
php artisan test

# Миграции
php artisan migrate
```

### Frontend

```bash
npm install
npm run build
```

---

## Правила написания кода

- Следовать стандартам Laravel
- Все коммиты на английском языке с чётким описанием изменений
- Не пушить напрямую в `master`
- Ветки начинаются с `claude/` и заканчиваются session id

---

## Безопасность

- Не коммитить `.env` файлы и секреты
- Не добавлять credentials в код
