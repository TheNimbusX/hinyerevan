# HinYerevan.com

Архив старых фотографий Еревана с картой, лентой по годам/авторам/местам и интеграцией с Facebook-страницей.

## Стек

- **Backend:** Laravel 10 (PHP 8.1), MySQL, Sanctum (токены), Socialite (OAuth), планировщик через cron
- **Frontend:** Vue 3 + Vite, Vue Router, SCSS, Leaflet (карта), i18n (hy/ru/en)
- **Инфраструктура:** Nginx + php-fpm на VPS, Let's Encrypt, Cloudflare (доступ из РФ), Brevo (SMTP), деплой по `git push` + скрипт

## Возможности

- Загрузка фото с вотермаркой, гео-привязкой, направлением и модерацией
- Карта и каталог: фильтры по годам, авторам, местам, фото/видео; рейтинги
- Комментарии и лайки сайта + двусторонняя синхронизация с Facebook (комментарии, лайки, просмотры; кросспостинг комментариев в FB в фоне)
- Авторизация: email + соцсети (Google, Яндекс, VK, Facebook); сброс пароля по почте
- Профили пользователей и аватары (включая кэш аватарок из Facebook)
- Раздел новостей и форма обратной связи
- Админка: фото, пользователи, новости, обратная связь; поиск и модерация
- Тёмная/светлая тема, мультиязычность, мобильный splash-экран

## Главные фишки

- **Живая интеграция с Facebook-страницей** — публикация постов и синхронизация метрик/комментариев через Graph API; токен страницы обновляется автоматически
- **Без тормозов** — тяжёлые операции (синк Facebook, кросспостинг) вынесены из HTTP-запроса в фон
- **Доступ из России** через Cloudflare без VPN
- **Полная локализация** на армянский, русский и английский

## Структура репозитория

```
backend/    Laravel API (контроллеры, модели, сервисы Facebook, миграции)
frontend/   Vue 3 SPA (views, components, i18n, стили)
deploy/     Скрипты и заметки по деплою, Nginx, Cloudflare, SSL, почте
```

## Локальный запуск

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan serve

# frontend
cd frontend
npm install
npm run dev
```

## Деплой

Прод-ветка — `dev`. Деплой накатывается на VPS скриптом, который подтягивает код, ставит зависимости, гоняет миграции и собирает фронт:

```bash
ssh root@<vps> "bash /var/www/hinyerevan/deploy/deploy-dev.sh"
```

Разовая настройка планировщика (cron) и Cloudflare — см. `deploy/setup-cron.sh` и `deploy/setup-cloudflare.sh`.
