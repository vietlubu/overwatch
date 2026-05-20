# Nightwatch Ingest Server

Laravel application ở root repo này là Nightwatch ingest server chính. Nhiệm vụ của nó là nhận events từ các project Laravel khác đang chạy `laravel/nightwatch`, lưu trữ dữ liệu, và cung cấp nền tảng để build API, metrics, và dashboard phân tích.

Thư mục [`poc/`](./poc/README.md) chỉ là Node.js prototype để tham chiếu protocol hoặc debug nhanh khi cần. Nó không phải luồng setup mặc định của repo này.

## 🎯 Mục đích

Repo này được dùng để:

- ✅ Nhận Nightwatch events từ các project Laravel khác
- ✅ Parse Nightwatch TCP protocol
- ✅ Store events vào database
- ✅ Chuẩn bị API để query metrics và raw events
- ✅ Làm nền cho analytics dashboard và alerting

## 📦 Project Structure

```text
overwatch/
├── app/                    # Laravel ingest server application
├── config/                 # Laravel configuration
│   └── nightwatch.php      # Nightwatch ingest config
├── database/               # Migrations & seeders
├── routes/                 # API routes
├── public/                 # Public assets
├── resources/              # Views & frontend
├── poc/                    # Reference/debug prototype (Node.js)
│   └── README.md           # POC usage notes
├── nightwatch/             # Laravel Nightwatch package source (reference)
├── README.md               # Overview
├── GETTING-STARTED.md      # Onboarding từng bước
├── LARAVEL-SETUP.md        # Setup chi tiết cho ingest server
└── artisan                 # Laravel CLI
```

## 🚀 Quick Start

### 1. Setup ingest server này

```bash
# 1. Install dependencies
composer install

# 2. Setup environment
cp .env.example .env
php artisan key:generate

# 3. Configure database
# Local development dùng SQLite sẵn trong .env
# Shared/prod environments có thể chuyển sang PostgreSQL

# 4. Run migrations
php artisan migrate

# 5. Start HTTP server
php artisan serve
```

Ingest server sẽ chạy tại: `http://localhost:8000`

### 2. Cấu hình project Laravel khác gửi Nightwatch data vào đây

Trong project Laravel được monitor:

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

Sau đó clear config và chạy app được monitor:

```bash
php artisan config:clear
php artisan serve --port=8001
```

### 3. Trigger events từ project được monitor

```bash
# HTTP requests
curl http://127.0.0.1:8001

# Artisan commands
php artisan inspire

# Database activity
php artisan migrate:status
```

## 🏗️ Architecture

```text
┌────────────────────────────┐
│ External Laravel Apps      │
│ (with laravel/nightwatch)  │
└─────────────┬──────────────┘
              │ TCP / HTTP ingest
              ▼
┌────────────────────────────┐
│ This Repository            │
│ Laravel Nightwatch Ingest  │
│                            │
│  TCP listener              │
│  Event parser              │
│  Database storage          │
│  Query / metrics APIs      │
│  Dashboard layer           │
└────────────────────────────┘
```

## 📋 Configuration

### Ingest server `.env`

```env
APP_NAME="Nightwatch Ingest"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Local development
DB_CONNECTION=sqlite

# Shared / production environments
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
NIGHTWATCH_USE_QUEUE=false
NIGHTWATCH_RETENTION_DAYS=30
```

### Monitored app `.env`

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:8000
NIGHTWATCH_SERVER=my-laravel-app
```

## 📊 Event Types

Server này hướng tới việc nhận và lưu các event types từ Nightwatch như:

| Event Type                                           | Trigger          | Mô tả                         |
| ---------------------------------------------------- | ---------------- | ----------------------------- |
| `request_started` / `request_finished`               | HTTP request     | Method, URI, status, duration |
| `query`                                              | Database query   | SQL, bindings, duration       |
| `command_started` / `command_finished`               | Artisan command  | Command name, exit code       |
| `exception`                                          | Exception thrown | Class, message, trace         |
| `log`                                                | Log message      | Level, message, context       |
| `scheduled_task_started` / `scheduled_task_finished` | Scheduled task   | Task name, duration           |
| `mail_sending`                                       | Email            | Mailable, recipients          |
| `notification_sending`                               | Notification     | Type, recipients              |
| `outgoing_request`                                   | HTTP client      | URL, method, status           |
| `cache_hit` / `cache_miss`                           | Cache operation  | Key, tags                     |

## 🧪 Testing

### Test Laravel application

```bash
php artisan test
```

### Manual TCP test

```bash
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
# Expected: 2:OK
```

### Reference protocol test với `poc/`

Chỉ dùng khi cần đối chiếu protocol hoặc debug nhanh:

```bash
cd poc
npm test
```

## 🔎 Khi nào dùng `poc/`

Xem [`poc/README.md`](./poc/README.md) khi bạn cần:

- debug nhanh TCP protocol mà chưa muốn chạy full Laravel flow
- đối chiếu behavior giữa ingest server chính và prototype
- test auth / ingest payload bằng Node.js script đơn giản

Nếu mục tiêu là setup ingest server cho nhiều Laravel projects khác gửi dữ liệu vào, hãy đi theo luồng tài liệu ở root repo.

## 🐛 Troubleshooting

### Monitored app không kết nối được

Checklist:

- [ ] Ingest server này đang chạy
- [ ] `NIGHTWATCH_TOKEN` khớp giữa monitored app và ingest server
- [ ] `NIGHTWATCH_INGEST_URI=127.0.0.1:2407`
- [ ] `NIGHTWATCH_ENABLED=true` trong monitored app
- [ ] Đã chạy `php artisan config:clear` ở monitored app sau khi đổi `.env`

### Ingest server không khởi động được

```bash
lsof -i :8000
composer install
php artisan migrate:fresh
```

## 📚 Documentation

- [GETTING-STARTED.md](./GETTING-STARTED.md) - Onboarding từng bước cho ingest server
- [LARAVEL-SETUP.md](./LARAVEL-SETUP.md) - Setup chi tiết và kiến trúc server
- [poc/README.md](./poc/README.md) - Prototype/reference cho debug
- [Laravel Nightwatch Docs](https://nightwatch.laravel.com/docs)
- [Laravel Nightwatch GitHub](https://github.com/laravel/nightwatch)

## 🚀 Development Roadmap

### ✅ Completed

- [x] POC server implementation (reference)
- [x] TCP protocol parser prototype
- [x] HTTP authentication endpoint prototype
- [x] Connection testing scripts
- [x] Laravel project initialization
- [x] Base configuration setup

### 🚧 In Progress

- [ ] TCP server command (`php artisan nightwatch:listen`)
- [ ] Database migrations (events, metrics tables)
- [ ] Event models & repositories
- [ ] API controllers
- [ ] Dashboard UI

### 📋 Planned

- [ ] Real-time metrics aggregation
- [ ] Alert system
- [ ] Multiple server support
- [ ] PostgreSQL analytics optimization
- [ ] Grafana/Prometheus exporters

## 🤝 Contributing

1. Fork the repository
2. Create feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

## 📄 License

MIT
