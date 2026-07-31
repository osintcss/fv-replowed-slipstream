# FV Replowed (Modified)

## Changes

- Uses Laravel's Eloquent ORM
- Realtime chat powered by Laravel Reverb
- Artisan commands:
  - `quest:parse` - Parse quest XML and populate quests table
  - `chat:cleanup` - Delete chat messages older than 7 days
  - `db:backup` - Backup database to Backblaze B2
  - `discord:status` - Update Discord server status message
  - `world:cleanup-deleted` - Hard delete soft-deleted world objects

## Installation

Run the installer script:
```bash
./installer.sh
```

## Docker

Docker Desktop can run the full local stack (Apache/PHP, MariaDB, and Reverb):

```bash
make init
```

The game is then served at `http://localhost:8000`; MariaDB is exposed on
port `33061`, and Reverb on port `8080`. Use `make stop` to stop the stack,
`make logs` to follow its logs, and `make test` to run the Laravel test suite.

The application migrations extend the original FarmVille database, so import
its item dump before migrating:

```bash
make items ITEMS_SQL=/path/to/farmvilledb_trimmed.sql
make migrate
```

Game assets are intentionally bind-mounted from `public/farmville/assets` so
they are not copied into the Docker image. Download and extract them with
`make assets` before starting the game client. The four Internet Archive WARC
files are about 20 GB in total; downloads resume from `.cache/fv-assets` if
interrupted.

**Important:** Change the admin password in `app/Http/Controllers/AdminController.php` (line 21).

## Apache2 Configuration

An example Apache2 configuration is included at `apache2-config`.

## Credits

This project is based on [FV-Replowed](https://github.com/FV-Replowed/fv-replowed).

Original credits:
- kehayeah: PHP work and reverse engineering
- puccamite.tech: Dehasher development
- rabbetsbigday: Additional technical advising
