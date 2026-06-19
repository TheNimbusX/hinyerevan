# dev.hinyerevan.com — доступ из России без VPN

**Dev = `https://dev.hinyerevan.com`** (VPS `45.138.25.76`, Нидерланды).

## Диагностика (с ПК в РФ, без VPN)

```text
nslookup dev.hinyerevan.com
```

Должно быть **только** `45.138.25.76`. Если есть IPv6 `2a00:f940:...` — удалите **AAAA** у `@` и `www` в reg.ru (см. ниже).

```text
curl -v --connect-timeout 15 https://dev.hinyerevan.com/
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

- `dev.hinyerevan.com`, `www` → **A** `45.138.25.76` (пока не включите прокси ниже)
- MX, NS, SOA, TXT (SPF)
- `ftp`, `mail`, `smtp`, `pop` → A/AAAA на `31.31.196.205` (почта reg.ru)

**Удалить только если появятся:**

- **AAAA** у **`dev.hinyerevan.com`** (корень `@`)
- **AAAA** у **`www.dev.hinyerevan.com`**

Сейчас у вас AAAA только у ftp/mail — **их не трогайте**.

---

## Решение 1 (рекомендуется): Cloudflare перед сайтом

Пользователи в РФ ходят на IP Cloudflare, Cloudflare — на ваш VPS.

1. [dash.cloudflare.com](https://dash.cloudflare.com) → **Add site** → `dev.hinyerevan.com` → Free.
2. Импорт DNS: A `@` и `www` → `45.138.25.76`, MX как в reg.ru.
3. У записей `@` и `www` включить **Proxied** (оранжевое облако).
4. SSL/TLS → **Full (strict)** (на VPS уже Let's Encrypt).
5. В reg.ru **сменить NS** домена на те, что даст Cloudflare  
   (или по инструкции CF для reg.ru — часто проще именно NS).
6. Подождать 15–60 мин, проверить с телефона без VPN.

После включения CF на сервере можно добавить реальные IP клиентов (см. `deploy/nginx-cloudflare.conf`).

---

## Решение 2: прокси в РФ (отдельный VPS, НЕ старый reg.ru)

**Не использовать `31.31.199.153`** — это старый боевой сервер, его скоро отключат.

**Схема:** пользователь в РФ → **новый маленький VPS в РФ** (Selectel / ServerSpace RU / Timeweb) → nginx `proxy_pass` → `45.138.25.76` (NL, новый сайт).

### Шаг 1 — арендовать RU VPS

- 1 CPU, 1 GB RAM, Ubuntu 22.04 — достаточно только для nginx
- Открыты порты 80/443
- Запомнить публичный IP, например `RU_PROXY_IP`

### Шаг 2 — nginx на RU VPS

Скопировать `deploy/nginx-reg-ru-proxy.conf`, заменить `listen 31.31.199.153` на `listen 80` / `listen 443 ssl`, выпустить certbot:

```bash
certbot --nginx -d hinyerevan.com -d www.hinyerevan.com
```

`proxy_pass https://45.138.25.76` — как в конфиге.

### Шаг 3 — DNS

| Имя | Значение |
|-----|----------|
| `hinyerevan.com` A | `RU_PROXY_IP` |
| `www.hinyerevan.com` A | `RU_PROXY_IP` |

Сайт и БД остаются на NL VPS `45.138.25.76`. RU VPS — только reverse proxy.

### Откат

DNS A → `45.138.25.76` (напрямую на NL).

---

## Решение 2 (устарело): прокси на старом reg.ru `31.31.199.153`

**Не делать.** Старый сервер снимают. Скрипт `setup-reg-ru-proxy.sh` оставлен только для отката/истории; используйте `restore-reg-ru-vhost.sh` если эксперимент уже ставился.

---

## Решение 2b (legacy doc): прокси на reg.ru `31.31.196.205`

Если на reg.ru есть SSH/ISPmanager и можно поставить nginx:

1. В reg.ru: **A** для `dev.hinyerevan.com` и `www` → **`31.31.196.205`** (вместо NL).
2. На хостинге nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name dev.hinyerevan.com www.dev.hinyerevan.com;
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

3. Сертификат Let's Encrypt на reg.ru для `dev.hinyerevan.com`.

Почта (`mail.dev.hinyerevan.com` и т.д.) остаётся на `31.31.196.205` — не мешает.

---

## Решение 3: временно для команды

В `C:\Windows\System32\drivers\etc\hosts` (нужны права админа):

```text
45.138.25.76 dev.hinyerevan.com www.dev.hinyerevan.com
```

Помогает только если **блокируется домен**, а не IP. Если блок IP — не поможет, нужен Cloudflare или прокси в РФ.

---

## OAuth / VK

После Cloudflare или прокси callback остаётся:

`https://dev.hinyerevan.com/api/auth/social/.../callback`

В VK ID / Google / Yandex URL не меняется, если в браузере открывается тот же `https://dev.hinyerevan.com`.

---

## Прокси «Россия, порт 18888» (логин/пароль) — поможет ли?

**Коротко:** для того, чтобы **сайт открывался в браузере из РФ без VPN** — **нет**, если это обычный **исходящий** HTTP/SOCKS-прокси (как «Универсальные прокси» с IP `185.42.x.x` и портом `18888`).

| Направление | Что это | Ваш прокси | Cloudflare |
|-------------|---------|------------|------------|
| **Входящий** (пользователь в РФ → сайт) | Нужен **обратный** прокси или CDN перед NL VPS | ❌ не подходит | ✅ да |
| **Исходящий** (VPS в NL → VK / Яндекс / uLogin) | `OAUTH_PROXY` в `backend/.env` | ✅ да | не нужен |

Схема проблемы:

```text
Без VPN:  Браузер (РФ) ──X──► 45.138.25.76 (NL)   ← часто режет провайдер
С VPN:    Браузер ──► VPN ──► 45.138.25.76       ← работает

Исходящий прокси:  VPS (NL) ──► 185.42.27.226 (РФ) ──► login.yandex.ru
                   (OAuth с сервера, не открытие сайта пользователю)
```

### Куда вставить российский прокси (исходящий)

На VPS в `/var/www/hinyerevan/backend/.env` (пароль **не** коммитить в git):

```env
OAUTH_PROXY=http://LOGIN:PASSWORD@185.42.27.226:18888
```

Проверка на сервере:

```bash
cd /var/www/hinyerevan/backend
php artisan config:cache
curl -x "http://LOGIN:PASSWORD@185.42.27.226:18888" -s -o /dev/null -w "%{http_code}\n" --max-time 20 https://login.yandex.ru/
```

Ожидается `200` или `302`. Затем снова вход через Яндекс/VK на сайте.

### Как реально открыть dev из РФ без VPN

1. **Cloudflare** (бесплатно, 15–60 мин) — см. «Решение 1» выше. Это основной фикс для **открытия сайта**.
2. **Nginx на reg.ru** (`31.31.196.205`) — «Решение 2», если есть SSH/хостинг.
3. **Отдельный VPS в РФ** с nginx `proxy_pass` на `45.138.25.76` и A-запись домена на этот VPS — только если есть **свой сервер** с входящими 80/443, не «прокси 18888».

Прокси с одним портом `18888` **не принимает** HTTPS-запросы пользователей к `dev.hinyerevan.com` — он для исходящих подключений **с** вашего сервера **через** прокси.
