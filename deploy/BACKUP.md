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

## ⚠️ Disk space

The photo library is ~13 GB and the server disk is only 39 GB (~4.5 GB free).
A full archive is roughly the size of the photo library, so **you cannot keep
several full backups on the server** — it would fill the disk and take the site
down. Two strategies:

- **Daily DB-only backup** on the server (a few MB) — this is the data that
  actually changes every day.
- **Full archive on demand / offsite** — make it right before migrating, or
  send it offsite, instead of stockpiling locally.

`backup.sh` refuses to write a full archive if there isn't enough free space
(override with `FORCE=1`).

## Create a backup

On the production VPS:

```bash
bash /var/www/hinyerevan/deploy/backup.sh           # full: DB + photos + config
MODE=db bash /var/www/hinyerevan/deploy/backup.sh   # DB + config only (tiny)
```

Output: `/root/backups/hinyerevan-YYYYMMDD-HHMMSS.tar` (full) or
`/root/backups/hinyerevan-db-YYYYMMDD-HHMMSS.tar` (db-only).

Useful env vars:

```bash
KEEP=14 bash deploy/backup.sh                       # keep 14 archives (per type)
COMPRESS=1 bash deploy/backup.sh                    # gzip the whole file (slower)
FORCE=1 bash deploy/backup.sh                       # skip the free-space check
OFFSITE_SCP=user@backup-host:/backups bash deploy/backup.sh   # copy offsite
OFFSITE_RCLONE=mydrive:hinyerevan      bash deploy/backup.sh   # rclone offsite
```

## Automate backups

```bash
bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
# daily DB-only backup at 03:00, keeps newest 14; log: /var/log/hinyerevan-backup.log
# (no scheduled full/photo backup unless an offsite target is given)
```

Daily DB + weekly full, all pushed offsite (the safe production setup):

```bash
OFFSITE_SCP=user@backup-host:/backups \
  bash /var/www/hinyerevan/deploy/setup-backup-cron.sh
# offsite target present -> a weekly full backup is scheduled automatically
```

> **Important:** keep at least one copy of the **full** archive off the server.
> A backup that only lives on the dying server does not protect you. Download it
> periodically (`scp root@IP:/root/backups/hinyerevan-*.tar .`) or set
> `OFFSITE_SCP` / `OFFSITE_RCLONE`.

## Offsite: Yandex.Disk (rclone)

Because the disk is small and the server has little RAM, the offsite copy is a
**file mirror** on Yandex.Disk (not a single huge file). It is incremental,
resumable and low-memory. Layout on `yadisk:hinyerevan`:

```
db/hin_yerevan-<stamp>.sql.gz   daily DB dumps (newest KEEP kept)
config/                          backend.env, secrets.env, *.nginx, cron
meta/manifest.txt                git commit + paths
legacy/                          mirror of all photos
storage-app/                     mirror of storage/app (caches excluded)
```

rclone remote setup (already done on prod; redo on a new box if needed):

```bash
rclone config create yadisk webdav url https://webdav.yandex.ru vendor other \
  user <yandex-login> pass <app-password> --obscure
```

(The app password is created at id.yandex.ru → Security → App passwords →
"Files (WebDAV)", and can be revoked anytime.)

## Restore on a new VPS

### Option A — from the Yandex mirror (recommended for DR)

```bash
# 1. install rclone + configure the remote (or pass --provision to install it)
rclone config create yadisk webdav url https://webdav.yandex.ru vendor other \
  user <yandex-login> pass <app-password> --obscure

# 2. fetch restore.sh and run it
bash restore.sh --from-rclone yadisk:hinyerevan --provision   # fresh box
bash restore.sh --from-rclone yadisk:hinyerevan               # stack present
```

### Option B — from a single portable file

```bash
scp hinyerevan-YYYYMMDD-HHMMSS.tar root@NEW_IP:/root/
bash restore.sh /root/hinyerevan-YYYYMMDD-HHMMSS.tar --provision
```

Either way restore will: install packages (with `--provision`), clone the code at
the backup's commit, restore `.env`/secrets, restore all photos, create the DB +
user and import the dump, restore the nginx vhost + cron, then run the normal
build (`composer`, `migrate`, frontend `npm run build`) and fix permissions.

Finally point DNS (`hinyerevan.com`) at the new IP and issue SSL / finalise nginx:

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
