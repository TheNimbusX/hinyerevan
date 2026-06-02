# Facebook Page — HinYerevanCom

Страница: **https://www.facebook.com/HinYerevanCom/**

Приложение Meta (OAuth вход): `802992039416856` — см. также [`deploy/SSL-AND-OAUTH.md`](SSL-AND-OAUTH.md).

## Что делает сайт

| Функция | URL / API |
|---------|-----------|
| Виджет подписчиков (угол) | `GET /api/facebook/page` |
| Страница с Page Plugin | `/facebook` |
| Публикация фото по галочке | при approve / сразу у админа |
| Лайки/комментарии FB на карточке | синхронизация каждые 30 мин |

**Список имён подписчиков** Meta API не отдаёт — только число + официальный embed на `/facebook`.

---

## 1. Права в Meta Developer

В [developers.facebook.com/apps](https://developers.facebook.com/apps) → приложение → **App Review → Permissions**:

- `pages_manage_posts` — публикация фото
- `pages_read_engagement` — лайки и комментарии
- `pages_show_list` — выбор страницы при получении токена

**Facebook Login → Settings** — redirect для входа пользователей (отдельно от Page API):

```text
https://hinyerevan.ru/api/auth/social/facebook/callback
```

**Privacy Policy URL:** `https://hinyerevan.ru/pages/privacy`

---

## 2. Page Access Token

1. [Graph API Explorer](https://developers.facebook.com/tools/explorer/) → приложение `802992039416856`.
2. User Token с правами `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`.
3. `GET /me/accounts` — найти **HinYerevanCom**, скопировать **id** страницы и **access_token**.
4. Обменять на long-lived token (60 дней):

```text
GET /oauth/access_token?grant_type=fb_exchange_token
  &client_id={app-id}&client_secret={app-secret}
  &fb_exchange_token={short-lived-page-token}
```

---

## 3. `.env` на сервере

```env
FACEBOOK_CLIENT_ID=802992039416856
FACEBOOK_CLIENT_SECRET=...

FACEBOOK_PAGE_ID=123456789
FACEBOOK_PAGE_URL=https://www.facebook.com/HinYerevanCom/
FACEBOOK_PAGE_ACCESS_TOKEN=EAA...
# Для Page Plugin на фронте (можно = FACEBOOK_CLIENT_ID)
FACEBOOK_APP_ID=802992039416856
```

```bash
cd /var/www/hinyerevan/backend
php artisan migrate --force
php artisan config:cache
```

Cron (если ещё нет):

```cron
* * * * * cd /var/www/hinyerevan/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 4. Проверка

```bash
curl -s https://hinyerevan.ru/api/facebook/page
curl -s https://hinyerevan.ru/api/facebook/plugin-config
```

- Открыть https://hinyerevan.ru/facebook — виджет Page Plugin.
- Загрузить фото с галочкой «Добавить в Facebook» → одобрить в админке → пост на странице.

---

## 5. Development vs Live

В **Development** публикация и Graph API работают для админов приложения/страницы. Для всех пользователей — **Live** + Business Verification (см. обсуждение App Review).

При блокировке Graph API с VPS NL — `OAUTH_PROXY` в `.env` (исходящий прокси).
