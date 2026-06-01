# HTTPS для OAuth (VK, Google, Apple) и прокси для Яндекса

## VK: «Обязателен протокол https»

[VK ID](https://id.vk.com) **не принимает** redirect URL вида `http://45.138.25.76/...` — только **https** и **доменное имя** (не голый IP).

### Что сделать на dev-сервере

1. **Поддомен** на ваш домен (например `dev.hinyerevan.com`):
   - DNS: запись **A** → `45.138.25.76`
2. На сервере (один раз):

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d dev.hinyerevan.com
```

3. В `backend/.env` на сервере:

```env
OAUTH_REDIRECT_BASE=https://dev.hinyerevan.com
FRONTEND_URL=https://dev.hinyerevan.com
APP_URL=https://dev.hinyerevan.com
```

```bash
cd /var/www/hinyerevan/backend && php artisan config:cache
```

4. В кабинете VK ID → **Доверенный Redirect URL**:

```
https://dev.hinyerevan.com/api/auth/social/vkontakte/callback
```

(именно `vkontakte` в пути — так настроен Laravel.)

5. То же **https://** добавить в Google / Facebook / Yandex / OK, если используете их на этом стенде.

Сайт открывать по **https://dev.hinyerevan.com**, не по IP.

---

## Яндекс: «сервер не может связаться с login.yandex.ru»

С VPS IP датацентра хост `login.yandex.ru` часто **недоступен**. OAuth-код приходит, профиль — нет.

В `backend/.env`:

```env
OAUTH_PROXY=socks5://127.0.0.1:1080
# или http://user:pass@proxy.example:8080
```

Прокси должен открывать `https://login.yandex.ru` (проверка на сервере):

```bash
curl -x "$OAUTH_PROXY" -s -o /dev/null -w "%{http_code} %{time_total}s\n" --max-time 15 https://login.yandex.ru/
```

Затем: `php artisan config:cache`

**Без прокси:** тестируйте Яндекс с локального `php artisan serve` и `OAUTH_REDIRECT_BASE=http://127.0.0.1:8000`.

---

## Порядок callback URL (все провайдеры)

| Провайдер | Путь callback |
|-----------|----------------|
| VK | `/api/auth/social/vkontakte/callback` |
| Google | `/api/auth/social/google/callback` |
| Facebook | `/api/auth/social/facebook/callback` |
| Yandex | `/api/auth/social/yandex/callback` |
| OK | `/api/auth/social/odnoklassniki/callback` |

Префикс: `{OAUTH_REDIRECT_BASE}` (с **https** для VK).
