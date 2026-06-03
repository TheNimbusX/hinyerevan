# Тестовая Facebook-страница для HinYerevan (dev)

Цель: **своя** страница под вашим Meta-приложением `802992039416856`, без участия владельца HinYerevanCom. В режиме **Development** этого достаточно, чтобы прогнать весь функционал сайта.

Продакшен-паблик босса подключается позже по [`FACEBOOK-PAGE-INTEGRATION.md`](FACEBOOK-PAGE-INTEGRATION.md).

---

## 1. Создать страницу (5 мин)

1. Откройте [facebook.com/pages/create](https://www.facebook.com/pages/create) под **своим** Facebook.
2. Тип: **Компания или бренд** (или «Общественная деятельность»).
3. Название, например: **HinYerevan Dev Test**.
4. Категория: «Сайт» / «История» / «Музей» — любая подходящая.
5. Завершите создание. Запомните URL вида `https://www.facebook.com/YourPageName`.

Вы автоматически становитесь **администратором** страницы — этого достаточно для API.

---

## 2. Настроить приложение Meta (если ещё не сделано)

[developers.facebook.com/apps/802992039416856](https://developers.facebook.com/apps/802992039416856/)

| Шаг | Действие |
|-----|----------|
| Продукты | **Facebook Login** → Settings → Valid OAuth Redirect URIs: `https://hinyerevan.ru/api/auth/social/facebook/callback` и для локали `http://127.0.0.1:8000/api/auth/social/facebook/callback` |
| Use cases | Добавить доступ к **Pages** (управление страницами), если Meta предлагает в мастере |
| Роли | Вы уже Admin приложения |
| Режим | **Development** — для тестов нормально (работает для админов app + страницы) |

Права для Graph API Explorer (не обязательно ждать App Review в Development):

- `pages_show_list`
- `pages_manage_posts`
- `pages_read_engagement`

---

## 3. Получить Page ID и токен

### 3.1 Graph API Explorer

1. [developers.facebook.com/tools/explorer](https://developers.facebook.com/tools/explorer/)
2. **Meta App:** `802992039416856`
3. **User or Page** → **Get User Access Token** → отметить права из §2.
4. Запрос:

```http
GET /me/accounts
```

5. В ответе найдите **HinYerevan Dev Test** (или как назвали):
   - `id` → это `FACEBOOK_PAGE_ID`
   - `access_token` → short-lived Page token

### 3.2 Long-lived token (~60 дней)

В Explorer или браузере (подставьте значения):

```http
GET /oauth/access_token?grant_type=fb_exchange_token
  &client_id=802992039416856
  &client_secret={FACEBOOK_CLIENT_SECRET}
  &fb_exchange_token={SHORT_PAGE_TOKEN}
```

В ответе `access_token` → `FACEBOOK_PAGE_ACCESS_TOKEN`.

Или на сервере/локально:

```bash
cd backend
php artisan facebook:exchange-token "EAAshort..."
```

---

## 4. `.env` (локально и/или на hinyerevan.ru)

```env
FACEBOOK_CLIENT_ID=802992039416856
FACEBOOK_CLIENT_SECRET=...

FACEBOOK_PAGE_ID=123456789012345
FACEBOOK_PAGE_URL=https://www.facebook.com/YourTestPageName/
FACEBOOK_PAGE_ACCESS_TOKEN=EAA...long...
FACEBOOK_APP_ID=802992039416856
```

```bash
php artisan config:clear
php artisan facebook:diagnose
```

На VPS после правок:

```bash
php artisan config:cache
php artisan facebook:diagnose
```

---

## 5. Чеклист функционала сайта

| # | Что проверить | Как |
|---|----------------|-----|
| 1 | API страницы | `curl -s https://hinyerevan.ru/api/facebook/page` → `configured: true`, `followers_count` |
| 2 | Page Plugin | Открыть `/facebook` — виджет с **тестовой** страницей |
| 3 | Бейдж в углу | На главной — число подписчиков (может быть 0) |
| 4 | Публикация фото | Загрузить фото с галочкой «Facebook» → одобрить в админке → пост на **тестовой** странице |
| 5 | Метрики на карточке | Лайкнуть пост на FB → через ~30 мин или `php artisan facebook:sync-stats` (если есть) / дождаться cron |
| 6 | Вход через Facebook | Войти на сайт кнопкой Facebook (отдельно от Page API) |

### Важно про публикацию фото

Facebook скачивает картинку по **публичному HTTPS URL**. Для теста публикации:

- используйте **https://hinyerevan.ru** (не `localhost`), **или**
- временный туннель (ngrok) с `APP_URL` / `FRONTEND_URL` на HTTPS.

Локально без публичного URL пост в FB **не создастся** — остальное (статистика, виджет) работает.

---

## 6. Cron (синхронизация лайков/комментариев)

На сервере:

```cron
* * * * * cd /var/www/hinyerevan/backend && php artisan schedule:run >> /dev/null 2>&1
```

Проверка вручную:

```bash
php artisan schedule:list
# задача SyncFacebookPostStatsJob — каждые 30 мин
```

---

## 7. Когда перейдёте на паблик босса

В `.env` на проде замените только:

- `FACEBOOK_PAGE_ID`
- `FACEBOOK_PAGE_URL`
- `FACEBOOK_PAGE_ACCESS_TOKEN` (новый токен от **HinYerevanCom**, выданный админом страницы)

`FACEBOOK_CLIENT_ID` / `SECRET` — то же приложение или отдельное — на ваш выбор.

---

## Частые ошибки

| Симптом | Решение |
|---------|---------|
| `/me/accounts` пустой | Вы не админ страницы или не выбраны права `pages_*` |
| `configured: false` | Нет `FACEBOOK_PAGE_ID` или `FACEBOOK_PAGE_ACCESS_TOKEN` в `.env` после `config:cache` |
| Publish: URL not reachable | Картинка должна быть на публичном HTTPS |
| Graph API timeout с VPS | `OAUTH_PROXY=...` в `.env` (см. SSL-AND-OAUTH.md) |
| Page Plugin пустой | Нужен `FACEBOOK_APP_ID`; в Development виджет видят в основном админы app |
