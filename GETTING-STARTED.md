# Getting Started - Nightwatch Ingest Server

## 📦 Project Overview

Dự án này bao gồm 2 implementations để nhận và xử lý monitoring data từ Laravel Nightwatch:

1. **POC Server** (`poc/`) - Node.js prototype
2. **Laravel Ingest Server** (root directory) - Production implementation

## 🚀 Quick Start

### Option 1: POC Server (Testing & Development)

```bash
# 1. Install dependencies
npm install

# 2. Start server
npm run dev

# 3. Test connection
npm test
```

Server chạy tại:

- TCP: `127.0.0.1:2407`
- HTTP: `http://localhost:3000`

### Option 2: Laravel Ingest Server (Production)

```bash
# 1. Install dependencies
composer install

# 2. Setup environment
cp .env.example .env
php artisan key:generate

# 3. Run migrations
php artisan migrate

# 4. Start server
php artisan serve
```

Server chạy tại: `http://localhost:8000`

## 📁 Project Structure

```
overwatch/
├── README.md                    # Main documentation
├── GETTING-STARTED.md          # This file
├── LARAVEL-SETUP.md            # Laravel implementation guide
│
├── Laravel Ingest Server (Root)
├── app/                        # Application code
├── config/
│   └── nightwatch.php         # Nightwatch config
├── database/                  # Migrations
├── routes/                    # API routes
├── composer.json              # PHP dependencies
├── artisan                    # Laravel CLI
│
├── POC Server
├── poc/                       # Node.js POC server
│   ├── poc-ingest.js         # POC implementation
│   ├── test-connection.js    # Connection test script
│   ├── start-dev.sh          # Auto-start script
│   ├── package.json          # NPM config
│   └── README.md             # POC documentation
│
└── Reference
    └── nightwatch/           # Laravel Nightwatch package source
```

## 🔗 Kết nối với Laravel App

### 1. Cài đặt Nightwatch package vào Laravel app

```bash
cd /path/to/your/laravel/app
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Cấu hình `.env`

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:8000
```

### 3. Clear cache và start

```bash
php artisan config:clear
php artisan serve
```

### 4. Trigger events

```bash
# HTTP requests
curl http://127.0.0.1:8000

# Artisan commands
php artisan inspire

# Queries
php artisan migrate:status
```

## 🎯 Use Cases

### POC Server - Dùng khi:

✅ Testing Nightwatch integration
✅ Quick prototyping
✅ Understanding protocol format
✅ Development environment
✅ Debugging issues

### Laravel Server - Dùng khi:

✅ Production deployment
✅ Cần lưu trữ data trong database
✅ Analytics & reporting
✅ Multiple Laravel apps monitoring
✅ Team collaboration

## 📊 Development Workflow

### Testing với POC Server

```bash
# Terminal 1: Start POC server
npm run dev

# Terminal 2: Start Laravel app
cd /path/to/laravel/app
php artisan serve

# Terminal 3: Trigger events
curl http://127.0.0.1:8000
php artisan inspire
```

### Development với Laravel Server

```bash
# Terminal 1: Start Laravel ingest server
php artisan serve --port=8000

# Terminal 2: Start monitored Laravel app
cd /path/to/laravel/app
php artisan serve --port=8001

# Configure app to send to localhost:8000
# Then trigger events
curl http://127.0.0.1:8001
```

## 🔧 Configuration

### POC Server Environment

```bash
NIGHTWATCH_TOKEN=dev-token
HTTP_PORT=3000
TCP_PORT=2407
```

### Laravel Server Environment

```env
# Database
DB_CONNECTION=sqlite

# Switch to PostgreSQL for shared/prod environments:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=overwatch
# DB_USERNAME=postgres
# DB_PASSWORD=
# DB_SCHEMA=public

# Nightwatch
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_TCP_HOST=127.0.0.1
NIGHTWATCH_TCP_PORT=2407
NIGHTWATCH_STORAGE_DRIVER=database
NIGHTWATCH_RETENTION_DAYS=30
```

### Monitored App Environment

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:3000  # POC
# or
NIGHTWATCH_BASE_URL=http://localhost:8000  # Laravel
```

## 🧪 Testing

### Test POC Server

```bash
npm test
```

Expected output:

```
✅ Authentication successful
✅ HTTP ingest successful
✅ TCP connection established
✅ Received acknowledgment from server
```

### Test Laravel Server

```bash
php artisan test
```

### Manual TCP Test

```bash
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
# Expected: 2:OK
```

## 📖 Next Steps

### Với POC Server

1. ✅ Đọc [Main README](./README.md)
2. ✅ Test connection: `npm test`
3. ✅ Kết nối Laravel app
4. ✅ Verify events được nhận

### Với Laravel Server

1. ✅ Đọc [Laravel Setup Guide](./LARAVEL-SETUP.md)
2. 🚧 Implement TCP server command
3. 🚧 Create database migrations
4. 🚧 Build API endpoints
5. 🚧 Create dashboard UI
6. 🚧 Deploy to production

## 🐛 Troubleshooting

### POC Server không kết nối được

```bash
# Check port availability
lsof -i :2407
lsof -i :3000

# Restart POC server
cd poc
pkill -f "node poc-ingest.js"
npm run dev
```

### Laravel app không gửi events

```bash
# Check config
php artisan config:show nightwatch

# Clear cache
php artisan config:clear

# Verify token matches
grep NIGHTWATCH_TOKEN .env
```

### Token mismatch

Đảm bảo token giống nhau:

```bash
# Monitored app .env
NIGHTWATCH_TOKEN=dev-token

# POC server (in poc/ directory)
cd poc
NIGHTWATCH_TOKEN=dev-token npm run dev

# Laravel ingest server .env (in root)
NIGHTWATCH_TOKEN=dev-token
```

## 📚 Documentation

- [Main README](./README.md) - Overview và project structure
- [Laravel Setup Guide](./LARAVEL-SETUP.md) - Laravel implementation docs
- [POC README](./poc/README.md) - POC server docs
- [Nightwatch Package](https://github.com/laravel/nightwatch) - Official package

## 🎉 Success Indicators

### POC Server working:

```
TCP client connected from 127.0.0.1:52431

=== TCP Message ===
Version: v1
Token hash: a1b2c3d
Payload: 3 record(s)
[...]
Received 3 records via TCP
===================
```

### Laravel Server working:

- ✅ Dashboard accessible at `/nightwatch`
- ✅ Events stored in database
- ✅ API returns data
- ✅ Metrics calculated correctly

## 🚀 Ready to Start!

Choose your path:

**Quick Testing?** → Start with POC Server
**Production Ready?** → Use Laravel Ingest Server
**Both?** → Run POC for testing, build Laravel for production
