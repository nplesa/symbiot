# Symbiot

GPS tracking and navigation web app for Romania, built with Laravel 13, Livewire and OpenLayers:
live position, recorded tracks, planned routes, points of interest (fuel prices, parking, police, cameras),
public transport stops, timetables and real-time vehicles, flights and trains. UI in English, Romanian and Italian.

## Requirements

| Component | Version / notes |
|-----------|-----------------|
| PHP | 8.5, with `pdo_mysql`, `intl`, `gd`, `zip`, `bcmath`, `redis` (phpredis) |
| Composer | 2.x |
| Node.js | 24 (Vite 8) |
| Database | MariaDB (`DB_CONNECTION=mariadb`) |
| Redis | real-time transit vehicles, connection `transit` (database `REDIS_TRANSIT_DB`, default 2) |
| osmium-tool | the `osmium` binary, used by the OSM POI and transit imports (`OSMIUM_BINARY`) |

## Installation

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
# edit .env: database, Redis, mail and the API keys below
php artisan migrate
npm run build
```

On Linux, `scripts/setup-osm-linux.sh` installs osmium-tool if missing, runs the migrations,
imports the Romania OSM extract (`osm:sync-romania`) and then keeps the scheduler running.

### API keys (`.env`)

| Variable | Used for |
|----------|----------|
| `GEOAPIFY_KEY` | geocoding, routing, Google Maps link import |
| `TOMTOM_API_KEY` | real-time traffic on the navigation page |
| `WAZE_API_KEY` / `WAZE_API_URL` | Waze police alerts |
| `FLIGHTAWARE_API_KEY` | airport arrivals and departures |
| `PETROLEUM_CHECK` | path to the intermediate certificate missing from the fuel price host's chain (default `resources/certs/sectigo-dv-r36.pem`) |

Without its key, the matching feature reports an error; the rest of the app is not affected.

## Processes that must run

```bash
php artisan queue:work      # GTFS imports and other queued jobs (QUEUE_CONNECTION=database)
php artisan schedule:work   # or a cron entry running `php artisan schedule:run` every minute
```

Scheduled tasks (`routes/console.php`):

| Command | When |
|---------|------|
| `osm:sync-romania` | daily 02:30 (needs osmium) |
| `transit:sync-osm-counties` | Mondays 03:00 |
| `transit:sync-catalog` | daily 03:30 |
| `map:prune-tiles` | daily 04:15 (map tile cache older than 30 days) |
| `sanctum:prune-expired --hours=24` | daily 04:30 |
| `queue:prune-failed --hours=168` | daily 04:45 |
| `transit:poll-realtime` | every 15 seconds |

`transit:poll-realtime` runs every 15 seconds, so `schedule:work` (or a supervisor-managed process) is preferred over a plain cron.

## User approval

New accounts are created unapproved and only see a "pending approval" page.
An approved administrator approves them under **Admin → Users** (`/admin/users`).

The first administrator has to be set by hand, for example:

```bash
php artisan tinker --execute="App\Models\User::where('email', 'you@example.com')->update(['is_admin' => true, 'approved_at' => now()]);"
```

Security-relevant events are written to the `audit` log channel (`storage/logs/audit.log`).

## Development

```bash
npm run dev          # Vite dev server; HTTPS only if the local certificate in vite.config.js exists
php artisan test     # PHP tests (SQLite in memory, no MariaDB needed)
npm test             # JS tests (node --test)
```

The PHP tests render pages, so build the assets (`npx vite build`) before running them on a fresh checkout.

## Continuous integration

`.github/workflows/tests.yml` runs on every push and pull request to `main`:
PHP 8.5 with Redis → `composer install`, `.env` + `key:generate`, `vite build`, `php artisan test`;
Node 24 → `npm test`.

## Translations

UI strings use English keys in `lang/en.json`, `lang/ro.json` and `lang/it.json`, for both Blade (`__()`)
and JavaScript (`t()` from `resources/js/i18n.js`). `TranslationKeysTest` fails when a key is missing in any language.
