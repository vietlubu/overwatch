# Getting Started - Nightwatch Ingest Server

## 📦 Project Overview

Root repo này là Laravel ingest server chính để nhận monitoring data từ các project Laravel khác đang dùng Nightwatch.

Thư mục [`poc/`](./poc/README.md) là prototype/reference để debug protocol hoặc đối chiếu behavior khi cần. Nó không phải onboarding path mặc định.

## 🚀 Quick Start

### 1. Khởi động ingest server

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Server chạy tại: `http://localhost:8000`

### 2. Cấu hình project Laravel khác gửi data vào đây

Trong project được monitor:

```bash
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:8000
NIGHTWATCH_SERVER=my-laravel-app
```

```bash
php artisan config:clear
php artisan serve --port=8001
```

### 3. Verify ingest flow

```bash
curl http://127.0.0.1:8001
php artisan inspire
php artisan migrate:status
```

## 📁 Project Structure

```text
overwatch/
├── README.md             # Overview và định vị repo
├── GETTING-STARTED.md    # Hướng dẫn bắt đầu nhanh
├── LARAVEL-SETUP.md      # Setup chi tiết cho ingest server
├── app/                  # Laravel ingest server code
├── config/               # App configuration
├── database/             # Migrations
├── routes/               # API routes
├── poc/                  # Prototype/reference cho debug
└── nightwatch/           # Source package để tham chiếu
```

## 🔧 Configuration

### Ingest server environment

```env
DB_CONNECTION=sqlite

# Switch to PostgreSQL for shared/prod environments:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=overwatch
# DB_USERNAME=postgres
# DB_PASSWORD=
# DB_SCHEMA=public

NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_TCP_HOST=127.0.0.1
NIGHTWATCH_TCP_PORT=2407
NIGHTWATCH_STORAGE_DRIVER=database
NIGHTWATCH_RETENTION_DAYS=30
```

### Monitored app environment

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:8000
```

## 🎯 Use Cases

Repo root này dùng khi bạn cần:

- nhận Nightwatch events từ nhiều Laravel projects khác
- lưu events vào database để query và phân tích
- phát triển ingest API, metrics aggregation, hoặc dashboard
- chuẩn bị một ingest endpoint dùng chung cho team hoặc môi trường shared

`poc/` chỉ nên dùng khi bạn cần:

- debug protocol nhanh
- verify payload format độc lập với Laravel app chính
- so sánh behavior với prototype Node.js

## 📊 Development Workflow

### Luồng phát triển khuyến nghị

```bash
# Terminal 1: Start ingest server ở repo này
php artisan serve --port=8000

# Terminal 2: Start monitored Laravel app ở repo khác
cd /path/to/laravel/app
php artisan serve --port=8001

# Terminal 3: Trigger events từ monitored app
curl http://127.0.0.1:8001
php artisan inspire
```

### Khi cần debug protocol

```bash
cd /path/to/overwatch/poc
npm install
npm run dev
npm test
```

Chỉ dùng luồng này để tham chiếu/debug, không phải onboarding mặc định của repo.

## 🧪 Testing

### Test ingest server

```bash
php artisan test
```

### Manual TCP test

```bash
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
# Expected: 2:OK
```

### Reference test với `poc/`

```bash
cd poc
npm test
```

## 📖 Next Steps

1. Đọc [README.md](./README.md) để nắm vai trò của repo.
2. Làm theo [LARAVEL-SETUP.md](./LARAVEL-SETUP.md) để setup ingest server chi tiết hơn.
3. Kết nối một project Laravel khác vào ingest endpoint này.
4. Dùng [`poc/README.md`](./poc/README.md) chỉ khi cần debug/reference.

## 🐛 Troubleshooting

### Monitored app không gửi events

```bash
php artisan config:show nightwatch
php artisan config:clear
grep NIGHTWATCH_TOKEN .env
```

Đảm bảo:

- token giống nhau giữa monitored app và ingest server
- `NIGHTWATCH_INGEST_URI=127.0.0.1:2407`
- monitored app đang thực sự phát sinh request / query / command

### `poc/` không kết nối được

```bash
lsof -i :2407
lsof -i :3000
cd poc
npm run dev
```

Nếu bạn chỉ muốn chạy ingest server chính, có thể bỏ qua hoàn toàn phần `poc/`.

## 📚 Documentation

- [README.md](./README.md) - Overview và role của repo
- [LARAVEL-SETUP.md](./LARAVEL-SETUP.md) - Setup chi tiết cho ingest server
- [poc/README.md](./poc/README.md) - Prototype/reference docs
- [Nightwatch Package](https://github.com/laravel/nightwatch) - Official package

## 🎉 Success Indicators

Luồng chính được coi là đúng khi:

- ingest server ở repo này khởi động được
- monitored Laravel app khác gửi events được vào endpoint này
- dữ liệu ingest có thể được lưu, query, hoặc debug theo mục tiêu hiện tại

`poc/` chỉ là công cụ hỗ trợ khi cần kiểm tra protocol nhanh.
