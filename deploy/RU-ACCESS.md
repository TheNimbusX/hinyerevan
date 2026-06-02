# hinyerevan.ru — доступ из России без VPN

**Dev = `https://hinyerevan.ru`** (VPS `45.138.25.76`, Нидерланды).

## Диагностика (с ПК в РФ, без VPN)

```text
nslookup hinyerevan.ru
```

Должно быть **только** `45.138.25.76`. Если есть IPv6 `2a00:f940:...` — удалите **AAAA** у `@` и `www` в reg.ru (см. ниже).

```text
curl -v --connect-timeout 15 https://hinyerevan.ru/
```

| Симптом | Причина |
|--------|---------|
| DNS верный, `curl` висит / timeout / reset | Часто **блокировка или DPI** до иностранного IP (не «сломанный DNS») |
| С VPN всё открывается | То же: трафик идёт не из РФ к `45.138.25.76` |
| `ERR_CONNECTION_CLOSED` на https | Нет SSL или не тот IP (редко сейчас) |

Сервер с NL отвечает нормально; проблема на пути **провайдер (РФ) → IP VPS**.

---

## Что НЕ удалять в DNS reg.ru (ваш список)

Оставить как есть:

- `hinyerevan.ru`, `www` → **A** `45.138.25.76` (пока не включите прокси ниже)
- MX, NS, SOA, TXT (SPF)
- `ftp`, `mail`, `smtp`, `pop` → A/AAAA на `31.31.196.205` (почта reg.ru)

**Удалить только если появятся:**

- **AAAA** у **`hinyerevan.ru`** (корень `@`)
- **AAAA** у **`www.hinyerevan.ru`**

Сейчас у вас AAAA только у ftp/mail — **их не трогайте**.

---

## Решение 1 (рекомендуется): Cloudflare перед сайтом

Пользователи в РФ ходят на IP Cloudflare, Cloudflare — на ваш VPS.

1. [dash.cloudflare.com](https://dash.cloudflare.com) → **Add site** → `hinyerevan.ru` → Free.
2. Импорт DNS: A `@` и `www` → `45.138.25.76`, MX как в reg.ru.
3. У записей `@` и `www` включить **Proxied** (оранжевое облако).
4. SSL/TLS → **Full (strict)** (на VPS уже Let's Encrypt).
5. В reg.ru **сменить NS** домена на те, что даст Cloudflare  
   (или по инструкции CF для reg.ru — часто проще именно NS).
6. Подождать 15–60 мин, проверить с телефона без VPN.

После включения CF на сервере можно добавить реальные IP клиентов (см. `deploy/nginx-cloudflare.conf`).

---

## Решение 2: прокси на reg.ru (если есть хостинг `31.31.196.205`)

Если на reg.ru есть SSH/ISPmanager и можно поставить nginx:

1. В reg.ru: **A** для `hinyerevan.ru` и `www` → **`31.31.196.205`** (вместо NL).
2. На хостинге nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name hinyerevan.ru www.hinyerevan.ru;
    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_pass https://45.138.25.76;
        proxy_ssl_server_name on;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

3. Сертификат Let's Encrypt на reg.ru для `hinyerevan.ru`.

Почта (`mail.hinyerevan.ru` и т.д.) остаётся на `31.31.196.205` — не мешает.

---

## Решение 3: временно для команды

В `C:\Windows\System32\drivers\etc\hosts` (нужны права админа):

```text
45.138.25.76 hinyerevan.ru www.hinyerevan.ru
```

Помогает только если **блокируется домен**, а не IP. Если блок IP — не поможет, нужен Cloudflare или прокси в РФ.

---

## OAuth / VK

После Cloudflare или прокси callback остаётся:

`https://hinyerevan.ru/api/auth/social/.../callback`

В VK ID / Google / Yandex URL не меняется, если в браузере открывается тот же `https://hinyerevan.ru`.
