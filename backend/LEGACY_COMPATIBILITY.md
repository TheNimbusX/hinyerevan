# HinYerevan Legacy Compatibility

The API is intentionally mapped to the old HinYerevan MySQL schema. Do not run schema-changing migrations against the production dump until the compatibility pass is complete.

## Expected Tables

- `users`: legacy auth, roles and profile data.
- `photos`: photo metadata, coordinates, direction, year and moderation state.
- `comments`: photo comments and threaded replies.
- `news`: public news posts.
- `pages`: static CMS pages by alias.
- `views`: photo view counters.

## Environment

Set these values in `.env` after importing the dump:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hin-yerevan
DB_USERNAME=...
DB_PASSWORD=...
HINYEREVAN_LEGACY_ROOT=../hin-yerevan-backup-prod/hin-yerevan1
```

## Validation Command

Run the read-only inspection command after the dump and photo files are available:

```shell
php artisan legacy:inspect
```

It checks required tables, important legacy columns and the expected photo folders without modifying data.
