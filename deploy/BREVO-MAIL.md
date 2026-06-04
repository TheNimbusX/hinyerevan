# Почта (Brevo) для сброса пароля

## Проблема «кнопка ничего не делает»

1. **Порт SMTP:** на VPS часто **закрыты 587/465**. Рабочий порт Brevo — **2525** (`MAIL_PORT=2525` в `.env`).
2. **IP сервера в Brevo:** без этого письма не уйдут, запрос «висит» ~20–45 с, потом ошибка.

## Настройка Brevo

1. [Brevo](https://app.brevo.com) → **Transactional** → **Settings** → **SMTP & API**.
2. **Authorized IPs** → добавить IP origin-сервера: **`45.138.25.76`** (или отключить ограничение, если допустимо).
3. Домен `hinyerevan.ru` — SPF/DKIM/DMARC (уже настраивали для аутентификации).

## `.env` на сервере (пример)

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=2525
MAIL_ENCRYPTION=tls
MAIL_USERNAME=ad884d001@smtp-brevo.com
MAIL_PASSWORD=<SMTP key из Brevo>
MAIL_FROM_ADDRESS=noreply@hinyerevan.ru
MAIL_FROM_NAME=HinYerevan.com
FRONTEND_URL=https://hinyerevan.ru
```

После правок:

```bash
cd /var/www/hinyerevan/backend
php artisan config:clear && php artisan config:cache
php /var/www/hinyerevan/deploy/test-mail.php your@email.com
```

Успех: `Sent to your@email.com`. Ошибка `525 Unauthorized IP` — не добавлен IP в Brevo.

## Проверка сброса пароля

`POST /api/auth/forgot-password` с телом `{"email":"..."}` — в UI кнопка показывает «Отправляем…», затем успех или текст ошибки.
