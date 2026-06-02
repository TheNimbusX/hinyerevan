# DNS для dev.hinyerevan.com (обязательно в reg.ru)

Зона **hinyerevan.com** в интернете обслуживается **ns5.hosting.reg.ru** / **ns6.hosting.reg.ru**, а не только bind на VPS 31.31.199.153.

Запись на prod-VPS в ISPmanager **есть**, но в публичном DNS её **ещё нет** — поэтому Let's Encrypt выдаёт NXDOMAIN.

## Добавить запись (2 минуты)

1. Войти в [reg.ru](https://www.reg.ru) (логин владельца домена, не SSH root VPS).
2. **Домены** → **hinyerevan.com** → **DNS-серверы и зона** / **Управление зоной**.
3. Убедиться, что NS: `ns5.hosting.reg.ru`, `ns6.hosting.reg.ru`.
4. **Добавить запись**:
   - Тип: **A**
   - Subdomain / имя: **dev**
   - IP: **45.138.25.76**
   - TTL: 3600 (по умолчанию)
5. Сохранить. Подождать 5–15 минут.

Проверка с ПК:

```bash
nslookup dev.hinyerevan.com 8.8.8.8
```

Должен быть `45.138.25.76`.

## Dev из России без VPN (важно)

Если **prod** (`hinyerevan.ru`) открывается, а **dev** (`dev.hinyerevan.com`) — только с VPN, чаще всего браузер уходит на **IPv6 (AAAA)** в зоне reg.ru, а не на VPS в Нидерландах.

1. В зоне **hinyerevan.com** на reg.ru проверьте запись **`dev`**:
   - **A** → `45.138.25.76`
   - **AAAA для `dev` — удалить** (как для `hinyerevan.ru` / `www`, см. `deploy/HINYEREVAN-RU.md`).
2. Отключите автоматический IPv6 для домена в reg.ru, если AAAA снова появляется.
3. Проверка с ПК в РФ:

```bash
nslookup dev.hinyerevan.com 8.8.8.8
```

Должен быть **только** `45.138.25.76`, **без** `2a00:f940:...`.

4. Открывайте сайт по **https://dev.hinyerevan.com**, не по IP.

**Пока DNS чинится:** временно в `C:\Windows\System32\drivers\etc\hosts`:

```text
45.138.25.76 dev.hinyerevan.com
```

## После появления DNS — на dev-сервере

```bash
bash /root/finish-dev-ssl.sh
```

(скрипт `deploy/finish-dev-ssl.sh` — SSL + https в `.env`).

## VK

В [id.vk.com](https://id.vk.com) → приложение → **Доверенный Redirect URL**:

```
https://dev.hinyerevan.com/api/auth/social/vkontakte/callback
```

## API reg.ru (опционально)

Если включён API в личном кабинете reg.ru:

```bash
REG_RU_USER=ваш_логин REG_RU_PASS=пароль_api php deploy/reg-add-dev-dns.php
```
