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

### 2.1 Почему нет «Сценариев» и `pages_manage_posts`

У приложения **HinYerevan** тип **Потребительское (Consumer)** — в меню слева **нет** пункта «Сценарии использования». Это нормально: такие приложения заточены под **Вход через Facebook**, а не под Pages API.

**Куда идти в вашем кабинете (как на скриншоте):**

1. Слева **Проверка приложения** → **Разрешения и функции**.
2. В поиске введите `pages` и добавьте (кнопка **Добавить** / **Request**):
   - `pages_show_list`
   - `pages_read_engagement`
   - `pages_manage_posts`
3. Для тестов переключите приложение в **режим разработки** (переключатель вверху; сейчас у вас **рабочий режим** — для своих тестов лучше Development).
4. Обновите [Graph API Explorer](https://developers.facebook.com/tools/explorer/) (F5).

Если в **Разрешения и функции** прав `pages_*` **вообще нет** — **не обязательно** создавать новое приложение (см. §2.3).

В Explorer: группа **Events, Groups and Pages**, не User Data; **очистите поиск** (не `email`).

### 2.3 Застряли на «Компания» при создании нового приложения

Сообщение **«Нет доступных компаний»** и серая кнопка **Далее** — Meta требует **бизнес-портфолио** (Business Portfolio). Без него мастер с сценарием «Страница» не продолжить.

**Рекомендуемый путь (без нового приложения):**

1. **Отмена** в мастере создания — второе приложение для теста **не нужно**.
2. Оставьте приложение **802992039416856** (Consumer).
3. [Graph API Explorer](https://developers.facebook.com/tools/explorer/) → **Получить маркер доступа к странице** → **HinYerevan TEST** → скопировать токен.
4. Проверка: `GET /{page-id}?fields=id,name` (id из `/me/accounts`) — если есть `name`, токен рабочий → §3.3 и `.env`.

Так можно протестировать сайт **без** Business Portfolio и без `pages_*` в кабинете.

**Если всё же нужно новое Business-приложение** — сначала привяжите тестовую страницу к портфолио (§2.4), затем в мастере на шаге «Компания» выберите это портфолио.

### 2.4 Бизнес-портфолио (например lenstoremy)

**Верификация не обязательна** для своей тестовой страницы и токена в Explorer. Неподтверждённое портфолио нормально; верификация нужна позже, если чужие люди/бизнесы будут давать вашему приложению доступ к своим данным (App Review / Live).

Сейчас в портфолио может быть **другая** страница (у вас **lenstoremy**, ID `110565793716725`). Для HinYerevan нужна **HinYerevan TEST** (Page ID из API, см. `/me/accounts`) — это разные страницы.

**Добавить HinYerevan TEST в портфолио:**

1. [business.facebook.com/settings](https://business.facebook.com/settings) → слева выберите портфолио **lenstoremy** (или своё).
2. **Аккаунты** → **Страницы** → синяя кнопка **+ Добавить**.
3. В меню выберите **Добавить страницу** (не «Создать», если страница уже есть):
   - **Добавить страницу** — если вы админ HinYerevan TEST в личном Facebook;
   - **Запросить доступ к странице** — если страница на другом аккаунте;
   - **Создать новую страницу** — только если ещё не создавали TEST.
4. В списке отметьте **HinYerevan TEST** → подтвердить. Должна появиться в списке рядом с lenstoremy.

Если **HinYerevan TEST** в списке нет — зайдите на [facebook.com](https://www.facebook.com) → ваша страница TEST → **Настройки страницы** → **Новый доступ к странице** / роли — убедитесь, что вы **Администратор** под тем же Facebook, что и Business Suite.

**Проверка верификации (не блокер для теста):** Настройки → **Информация о компании** / **Центр безопасности** — статус «Не подтверждено» можно игнорировать до продакшена.

После добавления страницы: мастер нового приложения на шаге **Компания** → выбрать **lenstoremy**. Либо без нового app — только **маркер страницы** в Explorer (§3.1).

**Ошибка «Unable to add Facebook Page» / «действие временно заблокировано»** — ограничение Meta на аккаунт, не настройка сайта. Портфолио для теста **не обязательно**. Обход:

1. Не добавляйте страницу в Business Suite — идите сразу в [Graph API Explorer](https://developers.facebook.com/tools/explorer/) → **маркер страницы** → HinYerevan TEST (§3.1).
2. Снятие блока (если нужен именно Business Manager): подождать 24–72 ч; зайти с [facebook.com](https://www.facebook.com) → **Настройки и конфиденциальность** → проверить ограничения; не спамить повторными «Добавить»; при долгой блокировке — [справка Meta](https://www.facebook.com/business/help).
3. Страница TEST и так ваша как **админ страницы** — API часто работает без привязки к портфолио lenstoremy.

**Продакшен-страница босса (HinYerevanCom):** без портфолио/прав владельца страницы API не даст — см. [`FACEBOOK-PAGE-INTEGRATION.md`](FACEBOOK-PAGE-INTEGRATION.md).

### 2.2 Facebook Login и режим

| Шаг | Действие |
|-----|----------|
| Продукты | **Facebook Login** → Settings → Valid OAuth Redirect URIs: `https://hinyerevan.ru/api/auth/social/facebook/callback` и `http://127.0.0.1:8000/api/auth/social/facebook/callback` |
| Режим | **Development** — для тестов достаточно |

Права для Explorer (после §2.1): `pages_show_list`, `pages_manage_posts`, `pages_read_engagement`.

**Privacy Policy URL:** `https://hinyerevan.ru/pages/privacy`

---

## 3. Получить Page ID и токен

Тестовая страница: **HinYerevan TEST**. **Page ID для API** берите из `GET /me/accounts` → поле `id` (например `1051690344704492`). Число в URL `profile.php?id=61590549752538` — другое идентификатор, в `.env` нужен именно `id` из API.

### 3.0 Почему везде `Invalid Scopes: manage_pages, pages_show_list`

Приложение **802992039416856** — тип **Consumer**. У таких приложений Meta **не даёт** page token ни через «маркер страницы», ни через `pages_show_list` (в OAuth подставляется устаревший `manage_pages` → ошибка).

**Решение:** второе приложение типа **Business** (§2.5). Старое оставить для **входа** на сайт (`FACEBOOK_CLIENT_ID`).

### 2.5 Второе приложение Business (без сценария «Страница»)

1. [Создать приложение](https://developers.facebook.com/apps/creation/) → имя, например **HinYerevan Pages**.
2. На шаге сценариев прокрутите вниз → **Другое** → **Далее**.
3. Тип **Компания (Business)** → **Далее**.
4. **Компания:** выберите портфолио **lenstoremy** (страницу TEST в Business Suite добавлять **не обязательно**).
5. **Создать приложение** → режим **Разработка**.
6. **Проверка приложения → Разрешения и функции** → добавить `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`.
7. Explorer → выберите **новое** приложение (не 802992039416856) → **маркер страницы** → HinYerevan TEST.

В `.env` на сервере:

```env
FACEBOOK_CLIENT_ID=802992039416856
FACEBOOK_CLIENT_SECRET=...   # secret от Consumer-приложения (вход)
FACEBOOK_APP_ID=<ID нового Business-приложения>   # для Page Plugin
FACEBOOK_PAGE_ACCESS_TOKEN=... # токен из нового app
```

Секрет для обмена long-lived токена страницы — **от того приложения, которым получили page token** (нового Business).

Опционально: в старом app **Проверка приложения** → внизу **Удалить тип приложения** (Remove App Type) — иногда снимает Consumer; надёжнее отдельное Business-приложение.

### 3.1 Способ A: маркер страницы (только Business-приложение)

1. [Graph API Explorer](https://developers.facebook.com/tools/explorer/) → **HinYerevan Pages** (не Consumer 802992039416856).
2. **Получить маркер доступа к странице** → **HinYerevan TEST**.
3. Права user token: только то, что предлагает Meta для Business app (не вручную `pages_show_list` в Consumer).
4. Скопировать токен → `FACEBOOK_PAGE_ACCESS_TOKEN`.

### 3.2 Способ B: User Token + `/me/accounts`

1. User Token с правами из §2.1.
2. `GET /me/accounts` → для **HinYerevan TEST**: `id` = `FACEBOOK_PAGE_ID`, `access_token` = short-lived page token.

### 3.3 Long-lived token (~60 дней)

**Важно:** обмен делается через приложение **HinYerevanPage** (`443529411008579`), не через Consumer `802992039416856`.

1. [developers.facebook.com/apps/443529411008579/settings/basic](https://developers.facebook.com/apps/443529411008579/settings/basic/) → **Секрет приложения** → Показать → скопировать.
2. В `.env`: `FACEBOOK_APP_SECRET=...` (только для HinYerevanPage).
3. На VPS:

```bash
cd /var/www/hinyerevan/backend
php artisan config:clear
php artisan facebook:exchange-token "EAA...short_page_token..." --write-env
php artisan config:cache
php artisan facebook:diagnose
```

Или одной командой (подставьте секрет):

```bash
export FACEBOOK_APP_SECRET='...'
bash /var/www/hinyerevan/deploy/facebook-exchange-vps.sh
```

---

## 4. `.env` (локально и/или на hinyerevan.ru)

```env
FACEBOOK_CLIENT_ID=802992039416856
FACEBOOK_CLIENT_SECRET=...

FACEBOOK_PAGE_ID=1051690344704492
FACEBOOK_PAGE_URL=https://www.facebook.com/profile.php?id=1051690344704492
FACEBOOK_PAGE_ACCESS_TOKEN=EAA...long...
FACEBOOK_APP_ID=443529411008579
FACEBOOK_APP_SECRET=...   # secret HinYerevanPage, не Consumer
FACEBOOK_PLUGIN_APP_ID=802992039416856
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
