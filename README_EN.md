# Overwatch

Tiếng Việt: [README.md](./README.md)

Overwatch is a Laravel ingest server for `laravel/nightwatch`.

This repository receives TCP payloads from other Laravel applications, validates project ingest keys, and stores data in the database for APIs, rollups, cleanup jobs, and downstream analysis.

## Status & Roadmap

### Completed

- TCP ingest listener for Nightwatch.
- Ingested-data read APIs (`/api/*`).
- End-to-end self-test harness.

### Future TODO

- Login and authentication.
- Project management (create, update, rotate ingest key).
- Discord/Slack issue notification.
- Rule-based alerting for critical failures.

## What This Repository Is For

- Run a TCP listener for Nightwatch.
- Manage projects and ingest keys.
- Store raw events and detail tables in `nw_*` tables.
- Expose APIs to read ingested data.
- Verify the full ingest pipeline with the self-test harness.

`poc/` is still in this repository, but it is only a prototype/debug reference and not the main deployment path.

## Requirements

- PHP `^8.2`
- Composer
- Node.js + npm
- SQLite, MySQL, or Postgres

## Quick Overwatch Setup

### Fastest Way

```bash
composer setup
```

This command will:

- install PHP dependencies
- create `.env` if missing
- generate app key
- create `database/database.sqlite` if missing
- run migrations
- install Node dependencies
- build frontend assets

### Manual Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

If you use default SQLite:

```bash
touch database/database.sqlite
php artisan migrate
```

If you use MySQL/Postgres, update DB config in `.env` before running migration:

```bash
php artisan migrate
```

Then install/build frontend assets:

```bash
npm install
npm run build
```

## Run Overwatch Locally

### Fast Dev Loop

```bash
composer run dev
```

This starts:

- HTTP app
- queue listener
- log tailing
- Vite dev server

Note: `composer run dev` does not start the TCP ingest listener. Run this in another terminal:

```bash
php artisan nightwatch:listen
```

### Manual Run

Open 2 separate terminals:

```bash
php artisan nightwatch:listen
php artisan serve
```

Default addresses:

- HTTP app: `http://127.0.0.1:8000`
- TCP ingest listener: `127.0.0.1:2407`

Key environment variables:

```env
OVERWATCH_TCP_HOST=127.0.0.1
OVERWATCH_TCP_PORT=2407
OVERWATCH_RETENTION_DAYS=30
OVERWATCH_ROLLUP_RETENTION_DAYS=180
OVERWATCH_ROLLUP_SCHEDULE_ENABLED=true
OVERWATCH_ROLLUP_SCHEDULE_EVERY_MINUTES=1
OVERWATCH_CLEANUP_SCHEDULE_ENABLED=true
OVERWATCH_CLEANUP_SCHEDULE_DAILY_AT=02:00
```

## Create Project and Ingest Key

Create a tenant for a monitored app:

```bash
php artisan nightwatch:project:create demo-app --name="Demo App" --tags=internal,local
```

This creates a project and generates an ingest key in one step. The secret is shown only once, along with this env snippet:

```env
NIGHTWATCH_TOKEN=...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=your-deploy-name
NIGHTWATCH_SERVER=your-server-name
```

`NIGHTWATCH_TOKEN` is a secret and only shown once. Copy it immediately to the app being monitored.

Update project metadata:

```bash
php artisan nightwatch:project:update demo-app --name="Demo App" --tags=internal,staging
```

Rotate ingest key:

```bash
php artisan nightwatch:project:rotate-key demo-app
```

## Connect Another Laravel Project to Overwatch

Assume you have another Laravel app called `my-app`.

### 1. Install Nightwatch in that app

```bash
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Update monitored app `.env`

Paste values from Overwatch:

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=...secret from overwatch...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=local
NIGHTWATCH_SERVER=my-app-web-1
```

If the monitored app runs on another machine, point `NIGHTWATCH_INGEST_URI` to Overwatch's real host/port:

```env
NIGHTWATCH_INGEST_URI=10.0.0.15:2407
```

### 3. Reload config in monitored app

```bash
php artisan config:clear
```

### 4. Trigger events for ingest check

If monitored app runs locally:

```bash
php artisan serve --port=8001
```

Then trigger a few events:

```bash
php artisan inspire
curl http://127.0.0.1:8001
```

As long as Overwatch listener is running, token is correct, and monitored app can reach `NIGHTWATCH_INGEST_URI`, events are stored in `nw_*` tables.

## Short Integration Flow

1. Run Overwatch with `php artisan nightwatch:listen`.
2. Create a project in Overwatch (create command also generates ingest key).
3. Install `laravel/nightwatch` in another app.
4. Copy `NIGHTWATCH_TOKEN` and `NIGHTWATCH_INGEST_URI` into that app.
5. Trigger request/command/job so events are sent to Overwatch.

## End-to-End Self-Test

This repo includes a harness to verify the full ingest pipeline:

```bash
php artisan nightwatch:test-events --timeout=25
php artisan nightwatch:test-events --days-back=30 --concurrent-min=3 --concurrent-max=8
php artisan nightwatch:test-events --days-back=30 --concurrent-min=30 --concurrent-max=80 --users=20
```

The harness will:

1. create or reuse a self-test project and rotate key for the current run
2. run listener and helper processes
3. emit request, command, queue, schedule, notification, mail, cache, query, outgoing request, exception
4. verify data was written to the database

For larger datasets (dashboard / rollup testing):

- `--days-back=N`: replay baseline events from `N` days ago until now
- `--concurrent-min` / `--concurrent-max`: randomize number of batches per day
- `--users=N`: cap number of self-test users reused during replay; if enough users already exist in `nw_users`, no extra users are created
- current-day batch already exists from the base run, so replay only adds the delta to avoid duplicate verify logic counting

This is the fastest way to verify the repository is healthy before release.

## Useful Commands

```bash
php artisan nightwatch:listen
php artisan nightwatch:project:create {slug} --name="Project Name" --tags=internal
php artisan nightwatch:project:update {project} --name="Project Name" --tags=internal,prod
php artisan nightwatch:project:rotate-key {project}
php artisan nightwatch:test-events
php artisan nightwatch:rollup
php artisan nightwatch:cleanup
php artisan test
```

## Current API Endpoints

- `/api/projects`
- `/api/requests`
- `/api/exceptions`
- `/api/jobs`
- `/api/commands`
- `/api/scheduled-tasks`
- `/api/queries`
- `/api/notifications`
- `/api/mail`
- `/api/cache`
- `/api/outgoing-requests`
- `/api/users`
- `/api/logs`

## Troubleshooting

### Listener cannot bind to port

```bash
lsof -i :2407
```

### Monitored app does not send events

Check:

- `NIGHTWATCH_ENABLED=true`
- `NIGHTWATCH_TOKEN` matches key generated from Overwatch
- `NIGHTWATCH_INGEST_URI` has correct Overwatch listener host and port
- `php artisan config:clear` was run after `.env` updates
- monitored app has network reachability to the Overwatch machine

### Disable self-monitoring for Overwatch itself

This repo defaults to:

```env
NIGHTWATCH_ENABLED=false
```

Keep this value for normal operation. Only the self-test harness overrides it to emit test events.

## Prototype / Debug Reference

To compare with the Node.js prototype for protocol debugging, see [poc/README.md](./poc/README.md).
