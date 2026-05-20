# Overwatch Nightwatch Ingest Server

Repo Laravel ở root này là ingest server cho `laravel/nightwatch`. Nó nhận payload TCP từ các Laravel app khác, xác thực token theo project/environment, và lưu dữ liệu vào database để phục vụ phân tích, rollup, API, và dashboard sau này.

Thư mục [`poc/`](./poc/README.md) vẫn được giữ lại như prototype/reference để debug nhanh. Luồng chính của repo hiện tại là listener TCP + database ingest trong Laravel app này.

## Quick Start

### 1. Setup repo này

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### 2. Start ingest services

Chạy listener TCP và HTTP app ở hai terminal riêng:

```bash
php artisan nightwatch:listen
php artisan serve
```

Mặc định:

- HTTP app: `http://127.0.0.1:8000`
- TCP listener: `127.0.0.1:2407`

### 3. Tạo project + ingest key cho app được monitor

```bash
php artisan nightwatch:project:create demo-app --name="Demo App"
php artisan nightwatch:key:create demo-app --environment=local
```

`nightwatch:key:create` sẽ in ra env snippet để copy sang app Laravel được monitor.

### 4. Cấu hình app Laravel được monitor

Trong app kia:

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

`NIGHTWATCH_BASE_URL` khong nam trong flow TCP ingest hien tai cua repo nay.

## Config Split

- `config/overwatch.php`: config cho ingest server va self-test (`OVERWATCH_TCP_*`, retention, rollup, self-test ports/prefix).
- `config/nightwatch.php`: config cho Nightwatch client (`NIGHTWATCH_*`) dung khi repo nay tu dong phat event trong self-test hoac khi ban co chu y monitor chinh app nay.

Mau `.env.example` de `NIGHTWATCH_ENABLED=false` theo mac dinh de ingest server khong tu monitor chinh no trong luong van hanh thuong.

## Local Self-Test Harness

Repo nay co bo self-test end-to-end dung chinh Overwatch nhu mot monitored Laravel app cuc bo:

```bash
php artisan nightwatch:test-events --timeout=25
```

Command nay se:

1. Tao project/key tam thoi cho self-test.
2. Spawn listener TCP, web server, queue worker, va helper subprocesses.
3. Trigger request, command, scheduler, queue job, mail, notification, cache, query, outgoing request, va exception.
4. Doi ingest xong va verify rows trong database.

Ket qua da duoc verify end-to-end voi summary:

- `cache-event` 6
- `command` 4
- `exception` 3
- `job-attempt` 4
- `log` 1
- `mail` 1
- `notification` 1
- `outgoing-request` 1
- `query` 1
- `queued-job` 3
- `request` 6
- `scheduled-task` 3
- `user` 1

## Selective Capture Cho Self-Test

Nightwatch hien khong co config whitelist kieu "chi nghe nhung route/command nay". Overwatch emulate dieu nay bang cach:

- dat sample rate mac dinh ve `0` trong helper subprocesses
- opt-in request test bang `Laravel\\Nightwatch\\Http\\Middleware\\Sample::always()`
- opt-in scheduled task bang `Laravel\\Nightwatch\\Console\\Sample::always()`
- opt-in command test bang `Nightwatch::sample(1)`
- dung reject hooks de bo qua query/cache/outgoing/mail/notification/job khong mang marker self-test

Muc tieu la chi ghi nhan cac surface test duoc tao rieng, tranh noise tu chinh listener hoac request phu.

## Event Coverage

Self-test song hanh hien bao phu cac record type Nightwatch sau:

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

Trong do request payload states duoc verify cho `present`, `absent`, `not_enabled`, va `unsupported_content_type`; cache-event bao gom `hit`, `miss`, `write`, `delete`, `write-failure`, va `delete-failure`.

## Useful Commands

```bash
php artisan nightwatch:listen
php artisan nightwatch:test-events
php artisan nightwatch:project:create {slug} --name="Project Name"
php artisan nightwatch:key:create {project} --environment=local
php artisan nightwatch:rollup
php artisan nightwatch:cleanup
php artisan test
```

## Docs

- [GETTING-STARTED.md](./GETTING-STARTED.md): luong setup nhanh
- [LARAVEL-SETUP.md](./LARAVEL-SETUP.md): chi tiet kien truc va van hanh
- [poc/README.md](./poc/README.md): Node.js prototype/reference
