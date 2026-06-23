# HinYerevan — Backup & Disaster Recovery

Goal: if the server dies, move the whole site to a new VPS with **one file**.

## What is backed up

A single archive (`/root/backups/hinyerevan-<date>.tar`) contains everything
irreplaceable:

| Inside the archive | What it is |
|---|---|
| `db/hin_yerevan.sql.gz` | full MySQL dump (photos, users, comments, ratings, …) |
| `legacy/…` | **all photo files** — both legacy and new uploads live here (`HINYEREVAN_LEGACY_ROOT`) |
| `storage/app/…` | Laravel storage (watermark/avatar caches are excluded — they regenerate) |
| `config/backend.env` | app secrets: `APP_KEY`, DB creds, Facebook/OAuth tokens, mail keys |
| `config/secrets.env` | `/root/.hinyerevan-secrets.env` (legacy-server sync creds) |
| `config/*.nginx` | the nginx vhost(s) |
| `config/cron-scheduler` | the Laravel scheduler cron |
| `meta/manifest.txt` | git commit + paths, so restore pins the exact code version |

The **application code is not stored** in the archive — it is pulled from git
during restore and pinned to the commit recorded in `manifest.txt`.

## Create a backup

On the production VPS:

```bash
bash /var/www/hinyerevan/deploy/backup.sh
```

Output: `/root/backups/hinyerevan-YYYYMMDD-HHMMSS.tar`

Useful env vars:

```bash
KEEP=14 bash deploy/backup.sh                       # keep 14 archives
COMPRESS=1 bash deploy/backup.sh                    # gzip the whole file (slower)
OFFSITE_SCP=user@backup-host:/backups bash deploy/backup.sh   # copy offsite
OFFSITE_RCLONE=mydrive:hinyerevan      bash deploy/backup.sh   # rclone offsite
```

## Automate daily backups

```bash
bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
# daily at 03:00, keeps newest 7; log: /var/log/hinyerevan-backup.log
```

With offsite copy:

```bash
OFFSITE_SCP=user@backup-host:/backups HOUR=4 KEEP=14 \
  bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
```

> **Important:** keep at least one copy of the archive **off the server**.
> A backup that only lives on the dying server does not protect you. Download
> it periodically, or set `OFFSITE_SCP` / `OFFSITE_RCLONE`.

## Restore on a new VPS

1. Copy the archive to the new server, e.g. from your PC:

   ```bash
   scp hinyerevan-YYYYMMDD-HHMMSS.tar root@NEW_IP:/root/
   ```

2. Get `restore.sh` (clone the repo, or just scp the one script) and run it:

   ```bash
   # Fresh, empty Ubuntu/Debian server (installs the whole stack first):
   bash restore.sh /root/hinyerevan-YYYYMMDD-HHMMSS.tar --provision

   # Server that already has nginx + php8.1-fpm + MySQL + node:
   bash restore.sh /root/hinyerevan-YYYYMMDD-HHMMSS.tar
   ```

   Restore will: install packages (with `--provision`), clone the code at the
   backup's commit, restore `.env`/secrets, restore all photos, create the DB +
   user and import the dump, restore the nginx vhost + cron, then run the normal
   build (`composer`, `migrate`, frontend `npm run build`) and fix permissions.

3. Point DNS (`hinyerevan.com`) at the new IP, then issue SSL / finalise nginx:

   ```bash
   bash /var/www/hinyerevan/deploy/setup-prod-com.sh
   ```

That's it — the site is back.

## Restore only the database (no full DR)

```bash
mkdir /tmp/r && tar xf hinyerevan-*.tar -C /tmp/r db meta
gunzip -c /tmp/r/db/*.sql.gz | mysql hin_yerevan
```

## Notes

- The dump uses the local MySQL root socket if available, otherwise the
  credentials in `backend/.env`.
- Watermarked images and avatar caches are intentionally excluded; they are
  rebuilt automatically on first request after restore.
- `restore.sh` pins code to the backup commit so migrations match the DB. If
  that commit is missing from git it falls back to `origin/dev`.
