# Nightwatch Ingest Server

Production-ready Laravel application để nhận và xử lý monitoring data từ Laravel Nightwatch package.

## 📦 Project Structure

```
overwatch/
├── app/                    # Laravel application
├── config/                 # Configuration files
│   └── nightwatch.php     # Nightwatch ingest config
├── database/              # Migrations & seeders
├── routes/                # API routes
├── public/                # Public assets
├── resources/             # Views & frontend
│
├── poc/                   # POC Server (Node.js)
│   ├── poc-ingest.js     # POC implementation
│   ├── test-connection.js # Connection test
│   └── README.md         # POC documentation
│
├── nightwatch/           # Laravel Nightwatch package (reference)
│
├── README.md             # This file
├── LARAVEL-SETUP.md      # Laravel implementation guide
├── GETTING-STARTED.md    # Quick start guide
├── composer.json         # PHP dependencies
└── artisan              # Laravel CLI
```

## 🎯 Mục đích

Server này nhận events từ Laravel applications thông qua Nightwatch package và:

- ✅ Parse Nightwatch TCP protocol
- ✅ Store events vào database
- ✅ Cung cấp API để query metrics
- ✅ Build analytics dashboard
- ✅ Alert & notifications

## 🚀 Quick Start

### Option 1: Laravel Ingest Server (Production)

```bash
# 1. Install dependencies
composer install

# 2. Setup environment
cp .env.example .env
php artisan key:generate

# 3. Configure database (SQLite default)
# Already configured in .env

# 4. Run migrations
php artisan migrate

# 5. Start server
php artisan serve
```

Server chạy tại: `http://localhost:8000`

### Option 2: POC Server (Quick Testing)

```bash
# 1. Navigate to POC directory
cd poc

# 2. Install dependencies
npm install

# 3. Start POC server
npm run dev

# 4. Test connection
npm test
```

POC server chạy tại:

- TCP: `127.0.0.1:2407`
- HTTP: `http://localhost:3000`

## 🏗️ Architecture

```
┌─────────────────────┐
│  Laravel Apps       │
│  (with Nightwatch)  │
└──────────┬──────────┘
           │ TCP Socket
           │ Protocol: LENGTH:VERSION:TOKEN:DATA
           ▼
┌─────────────────────┐
│  Ingest Server      │
│  (This Laravel App) │
│                     │
│  ┌───────────────┐  │
│  │ TCP Server    │  │◄─── Nhận events
│  │ (Port 2407)   │  │
│  └───────┬───────┘  │
│          │          │
│  ┌───────▼───────┐  │
│  │ Event Parser  │  │◄─── Parse protocol
│  └───────┬───────┘  │
│          │          │
│  ┌───────▼───────┐  │
│  │ Database      │  │◄─── Store events
│  │ (SQLite/MySQL)│  │
│  └───────────────┘  │
│                     │
│  ┌───────────────┐  │
│  │ HTTP API      │  │◄─── Query & Analytics
│  │ Dashboard     │  │
│  └───────────────┘  │
└─────────────────────┘
```

## 🔗 Kết nối với Laravel App

### 1. Cài đặt Nightwatch package vào Laravel app

```bash
cd /path/to/your/laravel/app
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Cấu hình `.env` trong Laravel app

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:8000
NIGHTWATCH_SERVER=my-laravel-app
```

### 3. Clear cache và start

```bash
php artisan config:clear
php artisan serve --port=8001
```

### 4. Trigger events

```bash
# HTTP requests
curl http://127.0.0.1:8001

# Artisan commands
php artisan inspire

# Database queries
php artisan migrate:status
```

## 📋 Configuration

### Laravel Ingest Server (.env)

```env
# Application
APP_NAME="Nightwatch Ingest"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite

# Nightwatch Configuration
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_TCP_HOST=127.0.0.1
NIGHTWATCH_TCP_PORT=2407

# Event Processing
NIGHTWATCH_USE_QUEUE=false
NIGHTWATCH_RETENTION_DAYS=30
```

Full configuration: `config/nightwatch.php`

## 🛠️ Laravel Commands

### Start Development Server

```bash
php artisan serve
```

### Run Migrations

```bash
php artisan migrate
```

### Run Tests

```bash
php artisan test
```

### Start TCP Server (coming soon)

```bash
php artisan nightwatch:listen
```

## 📊 Event Types

Server sẽ nhận và store các event types từ Nightwatch:

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

### Test với POC Server

```bash
cd poc
npm test
```

### Test Laravel Application

```bash
php artisan test
```

### Manual TCP Test

```bash
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
# Expected: 2:OK
```

## 📡 Protocol Format

Nightwatch sử dụng TCP protocol với format:

```
LENGTH:PAYLOAD_VERSION:TOKEN_HASH:DATA
```

**Ví dụ PING:**

```
15:v1:abc1234:PING
```

**Ví dụ JSON:**

```
87:v1:abc1234:[{"type":"request_started","uuid":"123","method":"GET"}]
```

**Server response (bắt buộc):**

```
2:OK
```

## 🐛 Troubleshooting

### Laravel app không kết nối được

**Checklist:**

- [ ] Ingest server đang chạy (`php artisan serve`)
- [ ] `NIGHTWATCH_TOKEN` khớp giữa Laravel app và ingest server
- [ ] `NIGHTWATCH_INGEST_URI=127.0.0.1:2407` (KHÔNG PHẢI localhost)
- [ ] `NIGHTWATCH_ENABLED=true` trong Laravel app
- [ ] Đã clear cache: `php artisan config:clear`

**Debug steps:**

```bash
# 1. Check Laravel app config
php artisan config:show nightwatch

# 2. Check token
grep NIGHTWATCH_TOKEN .env

# 3. Test with POC server first
cd poc && npm test

# 4. Restart everything
php artisan config:clear
php artisan serve
```

### Server không khởi động được

```bash
# Check port availability
lsof -i :8000

# Install dependencies
composer install

# Re-run migrations
php artisan migrate:fresh
```

## 📚 Documentation

- [LARAVEL-SETUP.md](./LARAVEL-SETUP.md) - Chi tiết implementation Laravel server
- [GETTING-STARTED.md](./GETTING-STARTED.md) - Hướng dẫn setup từng bước
- [poc/README.md](./poc/README.md) - POC server documentation
- [Laravel Nightwatch Docs](https://nightwatch.laravel.com/docs)
- [Laravel Nightwatch GitHub](https://github.com/laravel/nightwatch)

## 🚀 Development Roadmap

### ✅ Completed

- [x] POC server implementation (Node.js)
- [x] TCP protocol parser
- [x] HTTP authentication endpoint
- [x] Connection testing
- [x] Laravel project initialization
- [x] Configuration setup

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
- [ ] ClickHouse integration
- [ ] Grafana/Prometheus exporters

## 🎯 Use Cases

### POC Server

Dùng để:

- ✅ Test Nightwatch integration
- ✅ Quick prototyping
- ✅ Understand protocol format
- ✅ Development environment

### Laravel Server

Dùng để:

- ✅ Production deployment
- ✅ Store data trong database
- ✅ Analytics & reporting
- ✅ Monitor multiple Laravel apps
- ✅ Team collaboration

## 🤝 Contributing

1. Fork the repository
2. Create feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

## 📄 License

MIT
