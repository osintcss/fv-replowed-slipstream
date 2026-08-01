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
  - `user:admin email@example.com` - Grant a registered account administrator access

## Installation

Run the installer script:
```bash
./installer.sh
```

## Docker

Docker can run the full local stack (Apache/PHP, MariaDB, and Reverb). On
Ubuntu, run the complete setup in this order:

```bash
cp .env.example .env
make tools
make assets
make init
make items
make migrate
```

`make tools` installs the MEGA downloader dependency. `make assets` downloads
and extracts the game files, then downloads `farmvilledb_trimmed.sql` from the
configured public MEGA folder into `.cache/fv-assets`. The four Internet
Archive WARC files are about 20 GB in total, and interrupted downloads resume
automatically. Once the game assets are present, repeated runs skip WARC
extraction.

The game is then served at `http://localhost:8000`; MariaDB is exposed on
port `33061`, and Reverb on port `8080`. Use `make stop` to stop the stack,
`make logs` to follow its logs, and `make test` to run the Laravel test suite.

For an internet-facing deployment, edit the untracked `.env` file before
starting the stack and set at least `APP_ENV=production`, `APP_DEBUG=false`,
and unique database credentials.

### Administrator access

Administrator access is assigned to individual registered accounts; there is no
shared admin password. After registering your owner account and running the
migrations, grant it access with:

```bash
docker compose exec fv-replowed-slipstream php artisan user:admin owner@example.com
```

To revoke access later, run the same command with `--revoke`. Only accounts
with this role can open `/admin` or call its currency-management endpoints.

## Apache2 Configuration

An example Apache2 configuration is included at `apache2-config`.

## Credits

This project is based on [FV-Replowed](https://github.com/FV-Replowed/fv-replowed).

Original credits:
- kehayeah: PHP work and reverse engineering
- puccamite.tech: Dehasher development
- rabbetsbigday: Additional technical advising
