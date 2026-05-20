# Laravel Nightwatch Ingest Setup

Tai lieu nay mo ta chi tiet architecture hien tai cua Overwatch va cach van hanh no nhu ingest server cho `laravel/nightwatch`.

## Vai Tro Cua Repo

Repo nay la:

- TCP listener nhan framed payload Nightwatch
- parser + ingestor luu vao database
- noi quan ly `project`, `environment`, `ingest token`
- noi chay local self-test harness de verify end-to-end

Repo nay khong con dua tren flow HTTP auth/ingest cu. `poc/` van ton tai chi de debug/reference.

## Architecture

```text
External Laravel Apps
        |
        | TCP (LENGTH:VERSION:TOKEN:DATA)
        v
Overwatch Laravel App
  - nightwatch:listen
  - NightwatchEventIngestor
  - nw_* tables
  - rollups / cleanup commands
```

## Config Split

### `config/overwatch.php`

Dung cho ingest server:

- `OVERWATCH_TCP_HOST`
- `OVERWATCH_TCP_PORT`
- `OVERWATCH_TCP_BACKLOG`
- `OVERWATCH_TCP_ACCEPT_TIMEOUT`
- `OVERWATCH_TCP_READ_TIMEOUT`
- `OVERWATCH_TCP_MAX_FRAME_BYTES`
- `OVERWATCH_RETENTION_DAYS`
- `OVERWATCH_ROLLUP_RETENTION_DAYS`
- `OVERWATCH_PARTITION_PRECREATE_MONTHS`
- `OVERWATCH_SELF_TEST_*`

### `config/nightwatch.php`

Dung cho Nightwatch client:

- `NIGHTWATCH_ENABLED`
- `NIGHTWATCH_TOKEN`
- `NIGHTWATCH_INGEST_URI`
- `NIGHTWATCH_DEPLOY`
- `NIGHTWATCH_SERVER`
- sampling va filtering options

Trong repo nay, phan config client chu yeu phuc vu self-test subprocesses. Listener va orchestrator command tu tat Nightwatch de tranh self-ingest loop.

## Boot Quy Trinh Co Ban

### 1. Setup app

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### 2. Start listener va web app

```bash
php artisan nightwatch:listen
php artisan serve
```

### 3. Tao tenant va ingest key

```bash
php artisan nightwatch:project:create demo-app --name="Demo App"
php artisan nightwatch:key:create demo-app --environment=local
```

Command tao key se output `NIGHTWATCH_TOKEN` va `NIGHTWATCH_INGEST_URI` de copy sang monitored app.

## Monitored App Setup

Trong app Laravel duoc monitor:

```bash
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=...secret from overwatch...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=local
NIGHTWATCH_SERVER=demo-app-web-1
```

Luu y:

- khong can `NIGHTWATCH_BASE_URL` cho flow nay
- token la secret theo `project/environment`
- Overwatch validate token truoc khi ingest

## Local Self-Test Harness

Command:

```bash
php artisan nightwatch:test-events --timeout=25
```

Harness se:

1. Tao key tam cho environment `self-test-{run-id}`.
2. Spawn `nightwatch:listen` voi `NIGHTWATCH_ENABLED=false`.
3. Spawn 2 HTTP helper servers va queue workers voi env Nightwatch rieng.
4. Trigger matrix request / command / schedule / queue / mail / notification / cache / outgoing request / query / exception.
5. Verify summary row counts trong `nw_raw_events` va detail tables.

### Tai sao self-test chi track surface test?

Nightwatch khong co whitelist config chinh thuc cho "chi route/command/task nay". Overwatch giai quyet nhu sau:

- helper subprocesses dat tat ca sample rate mac dinh ve `0`
- route test opt-in bang `Sample::always()`
- outgoing stub route dung `Sample::never()` de khong tao request noise
- command test goi `Nightwatch::sample(1)`
- scheduled task test dung `ConsoleSample::always()`
- reject hooks trong `AppServiceProvider` bo qua query/cache/outgoing/mail/notification/queued-job khong co marker self-test

Ket qua la Overwatch chi nhan cac event do bo self-test chu dong tao.

## Coverage Matrix

### Live end-to-end

- `user`
- `request`
- `command`
- `scheduled-task`
- `queued-job`
- `job-attempt`
- `exception`
- `query`
- `outgoing-request`
- `log`
- `mail`
- `notification`
- `cache-event`

### Chi tiet dang verify

- request payload states: `present`, `absent`, `not_enabled`, `unsupported_content_type`
- scheduled-task statuses: `processed`, `skipped`, `failed`
- job-attempt statuses: `processed`, `released`, `failed`
- cache-event types: `hit`, `miss`, `write`, `delete`, `write-failure`, `delete-failure`

## Van Hanh

### Manual TCP ping

```bash
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
```

Expected response:

```text
2:OK
```

### Focused verification

```bash
php artisan test --filter 'Nightwatch(Ingestor|ProjectCommands|ListenCommand|SelfTestHarness)Test'
php artisan nightwatch:test-events --timeout=25
```

### Maintenance

```bash
php artisan nightwatch:rollup
php artisan nightwatch:cleanup
```

## Database Notes

Nightwatch data duoc luu vao cac bang `nw_*`, gom:

- dimensions: `nw_projects`, `nw_ingest_tokens`, `nw_servers`, `nw_deployments`, `nw_users`
- raw/fact: `nw_raw_events`, `nw_executions`, `nw_request_details`, `nw_command_details`, `nw_job_attempt_details`, `nw_scheduled_task_details`, `nw_exceptions`, `nw_queries`, `nw_outgoing_requests`, `nw_queued_jobs`, `nw_logs`, `nw_mail_events`, `nw_notification_events`, `nw_cache_events`, `nw_jobs`
- rollups: `nw_request_route_1m`, `nw_exception_group_1m`, `nw_query_group_1m`, `nw_outgoing_host_1m`, `nw_job_queue_1m`, `nw_command_1m`, `nw_schedule_1m`, `nw_log_level_1m`

## POC Reference

Neu can doi chieu voi prototype Node.js, xem [`poc/README.md`](./poc/README.md). Tai lieu do chi de debug/reference, khong phai source of truth cho luong ingest hien tai.
