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
