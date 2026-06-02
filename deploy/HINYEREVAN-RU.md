# hinyerevan.ru — dev-сайт на VPS 45.138.25.76 (Нидерланды)

**Прод и dev сейчас один домен:** `https://hinyerevan.ru`.

## Не открывается из РФ без VPN

DNS у вас уже правильный (A → `45.138.25.76`, без AAAA на `@`/`www`).  
Если **с VPN работает, без VPN — нет**, это обычно **не reg.ru DNS**, а доступ провайдера к **иностранному IP**.

**Что делать:** пошагово в **`deploy/RU-ACCESS.md`** (Cloudflare — самый простой вариант).

## DNS (reg.ru)

**A-записи (оставить):**
- `hinyerevan.ru` → `45.138.25.76`
- `www.hinyerevan.ru` → `45.138.25.76`

**AAAA для корня и www — удалить.**  
Если в списке записей их нет, а `nslookup` всё ещё показывает IPv6 — в reg.ru: **Сайты / хостинг** для домена отключите IPv6 или обратитесь в поддержку (иногда AAAA добавляется автоматически).

Проверка с ПК:

```text
nslookup hinyerevan.ru 8.8.8.8
```

Должен быть **только** `45.138.25.76`, **без** `2a00:f940:...`.

## Почему «Не удается получить доступ»

| URL | Причина |
|-----|---------|
| **https://**hinyerevan.ru | На сервере ещё нет SSL (порт 443 закрыт) → `ERR_CONNECTION_CLOSED` |
| **http://** при IPv6 | Запрос уходит на хостинг reg.ru (`2a00:f940:...`), не на dev |

**Пока:** откройте **http://45.138.25.76** или временно в `C:\Windows\System32\drivers\etc\hosts`:

```text
45.138.25.76 hinyerevan.ru www.hinyerevan.ru
```

## SSL (на сервере после удаления AAAA)

```bash
bash /root/setup-domain-ssl.sh hinyerevan.ru
```

## OAuth callbacks

```
https://hinyerevan.ru/api/auth/social/vkontakte/callback
https://hinyerevan.ru/api/auth/social/yandex/callback
```
