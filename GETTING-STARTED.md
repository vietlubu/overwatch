# Getting Started

Tai lieu nay la luong ngan nhat de dung repo nay nhu Nightwatch ingest server va verify ingest thanh cong.

## 1. Setup Overwatch

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

## 2. Start listener TCP va HTTP app

Mo 2 terminal:

```bash
php artisan nightwatch:listen
php artisan serve
```

Mac dinh listener bind vao `127.0.0.1:2407` va web app phuc vu tai `http://127.0.0.1:8000`.

Neu can doi host/port, sua:

```env
OVERWATCH_TCP_HOST=127.0.0.1
OVERWATCH_TCP_PORT=2407
```

## 3. Tao project va key cho monitored app

```bash
php artisan nightwatch:project:create demo-app --name="Demo App"
php artisan nightwatch:key:create demo-app --environment=local
```

Command tao key se in ra env snippet dang nay:

```env
NIGHTWATCH_TOKEN=...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=your-deploy-name
NIGHTWATCH_SERVER=your-server-name
```

## 4. Cau hinh monitored Laravel app

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

Sau do:

```bash
php artisan config:clear
php artisan serve --port=8001
```

## 5. Trigger event de test ingest

Tu monitored app:

```bash
curl http://127.0.0.1:8001
php artisan inspire
php artisan migrate:status
```

Neu listener dang chay va token dung, Overwatch se nhan va luu event vao cac bang `nw_*`.

## 6. Chay self-test harness cua chinh repo nay

Neu muon verify full matrix bang chinh repo nay:

```bash
php artisan nightwatch:test-events --timeout=25
```

Harness se tu dong spawn helper processes, phat event test, va verify database persistence. Day la cach nhanh nhat de xac nhan TCP ingest + parser + persistence dang hoat dong end-to-end.

## Troubleshooting

### TCP listener khong bind duoc

```bash
lsof -i :2407
```

### Monitored app khong gui event

Kiem tra:

- `NIGHTWATCH_TOKEN` phai khop voi key ma Overwatch da tao
- `NIGHTWATCH_INGEST_URI` phai tro dung host/port listener
- da chay `php artisan config:clear` sau khi doi `.env`

### Muon chi capture event test

Repo nay khong dua vao config whitelist cua Nightwatch. Self-test dung sample rate `0` + opt-in tung route/command/task test, nen chi cac surface test moi duoc ghi nhan.

Xem them o [LARAVEL-SETUP.md](./LARAVEL-SETUP.md).
