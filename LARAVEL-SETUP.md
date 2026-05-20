# Laravel Nightwatch Ingest Server

Production-ready Laravel application để nhận và xử lý monitoring data từ Laravel Nightwatch package.

## 🎯 Mục đích

Server này nhận events từ Laravel applications thông qua Nightwatch package và:

- ✅ Parse Nightwatch TCP protocol
- ✅ Store events vào database
- ✅ Cung cấp API để query metrics
- ✅ Build analytics dashboard
- ✅ Alert & notifications

## 🚀 Quick Start

### 1. Cài đặt dependencies

```bash
composer install
```

### 2. Cấu hình environment

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Cấu hình database

**.env** (SQLite - local development):

```env
DB_CONNECTION=sqlite
```

**.env** (PostgreSQL - shared/prod environments):

```bash
createdb overwatch
```

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=overwatch
DB_USERNAME=postgres
DB_PASSWORD=
DB_SCHEMA=public
```

### 4. Run migrations

```bash
php artisan migrate
```

### 5. Start server

```bash
php artisan serve
```

Server sẽ chạy tại: `http://127.0.0.1:8000`

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
│  │ (PostgreSQL)  │  │
│  └───────────────┘  │
│                     │
│  ┌───────────────┐  │
│  │ HTTP API      │  │◄─── Query & Analytics
│  │ Dashboard     │  │
│  └───────────────┘  │
└─────────────────────┘
```

## 📋 Database Schema

### Events Table

Lưu trữ tất cả Nightwatch events:

```sql
CREATE TABLE events (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL,
    type VARCHAR(50) NOT NULL,
    hostname VARCHAR(255),
    "timestamp" BIGINT,
    payload JSONB,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ
);

CREATE INDEX idx_events_type ON events (type);
CREATE INDEX idx_events_hostname ON events (hostname);
CREATE INDEX idx_events_timestamp ON events ("timestamp");
CREATE INDEX idx_events_uuid ON events (uuid);
```

### Request Metrics Table

Aggregated metrics cho HTTP requests:

```sql
CREATE TABLE request_metrics (
    id BIGSERIAL PRIMARY KEY,
    hostname VARCHAR(255),
    date DATE,
    hour INTEGER,
    total_requests INTEGER DEFAULT 0,
    avg_duration DECIMAL(10,2),
    max_duration DECIMAL(10,2),
    status_2xx INTEGER DEFAULT 0,
    status_4xx INTEGER DEFAULT 0,
    status_5xx INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ,
    UNIQUE (hostname, date, hour)
);
```

## 🔌 API Endpoints

### 1. Authentication Endpoint

**POST** `/api/agent-auth`

Nightwatch agent authentication.

**Headers:**

```
Authorization: Bearer {NIGHTWATCH_TOKEN}
Content-Type: application/json
```

**Response:**

```json
{
    "token": "access-token",
    "expires_in": 3600,
    "refresh_in": 300,
    "ingest_url": "http://localhost:8000/api/ingest"
}
```

### 2. HTTP Ingest Endpoint

**POST** `/api/ingest`

Nhận events qua HTTP (alternative to TCP).

**Headers:**

```
Authorization: Bearer {access-token}
Content-Type: application/json
Content-Encoding: gzip (optional)
```

**Body:**

```json
[
    {
        "type": "request_started",
        "uuid": "...",
        "method": "GET",
        "uri": "/api/users"
    }
]
```

### 3. Query Events API

**GET** `/api/events`

Query stored events.

**Parameters:**

- `type` - Event type filter
- `hostname` - Hostname filter
- `from` - Start timestamp
- `to` - End timestamp
- `limit` - Results limit (default: 100)

**Example:**

```bash
curl "http://localhost:8000/api/events?type=request_finished&limit=50"
```

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "type": "request_finished",
            "payload": {...},
            "created_at": "2024-01-20T10:00:00Z"
        }
    ],
    "meta": {
        "total": 1234,
        "per_page": 50,
        "current_page": 1
    }
}
```

### 4. Metrics API

**GET** `/api/metrics/requests`

Get aggregated request metrics.

**Parameters:**

- `hostname` - Hostname filter
- `from` - Start date (Y-m-d)
- `to` - End date (Y-m-d)

**Response:**

```json
{
    "data": [
        {
            "date": "2024-01-20",
            "hour": 10,
            "total_requests": 1500,
            "avg_duration": 123.45,
            "status_2xx": 1400,
            "status_5xx": 5
        }
    ]
}
```

## 🛠️ Commands

### Start TCP Server

```bash
php artisan nightwatch:listen
```

Khởi động TCP socket server để nhận events từ Nightwatch agents.

Options:

- `--host` - Bind address (default: 127.0.0.1)
- `--port` - Port number (default: 2407)

### Process Events Queue

```bash
php artisan queue:work
```

Process queued events (nếu sử dụng queue).

### Aggregate Metrics

```bash
php artisan nightwatch:aggregate
```

Tính toán metrics từ raw events.

## 📊 Dashboard

Access dashboard tại: `http://localhost:8000/dashboard`

Features:

- 📈 Real-time request rate
- ⏱️ Response time distribution
- 🚨 Error rate monitoring
- 💾 Database query performance
- 🔍 Event search & filtering

## 🔧 Configuration

### .env Variables

```env
# Application
APP_NAME="Nightwatch Ingest"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite

# Switch to PostgreSQL when moving beyond local development:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=overwatch
# DB_USERNAME=postgres
# DB_PASSWORD=
# DB_SCHEMA=public

# Nightwatch Configuration
NIGHTWATCH_TOKEN=your-secret-token
NIGHTWATCH_TCP_HOST=127.0.0.1
NIGHTWATCH_TCP_PORT=2407
NIGHTWATCH_HTTP_PORT=8000

# Event Processing
NIGHTWATCH_USE_QUEUE=false
NIGHTWATCH_RETENTION_DAYS=30

# Queue (if enabled)
QUEUE_CONNECTION=database
```

### config/nightwatch.php

```php
return [
    'token' => env('NIGHTWATCH_TOKEN'),

    'tcp' => [
        'host' => env('NIGHTWATCH_TCP_HOST', '127.0.0.1'),
        'port' => env('NIGHTWATCH_TCP_PORT', 2407),
    ],

    'storage' => [
        'driver' => 'database', // database, elasticsearch
        'retention_days' => env('NIGHTWATCH_RETENTION_DAYS', 30),
    ],

    'processing' => [
        'use_queue' => env('NIGHTWATCH_USE_QUEUE', false),
        'batch_size' => 1000,
    ],
];
```

## 🧪 Testing

### Run tests

```bash
php artisan test
```

### Feature tests

```bash
php artisan test --filter=NightwatchIngestTest
```

### Test với POC client

```bash
cd ..
npm test
```

## 📦 Deployment

### Production setup

```bash
# 1. Clone và install
git clone <repo>
cd ingest
composer install --no-dev --optimize-autoloader

# 2. Configure
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate --force

# 4. Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Start services
# Use supervisor for TCP server
supervisorctl start nightwatch-tcp

# Use nginx/apache for HTTP
# Point to /public directory
```

### Supervisor configuration

`/etc/supervisor/conf.d/nightwatch-tcp.conf`:

```ini
[program:nightwatch-tcp]
process_name=%(program_name)s
command=php /path/to/ingest/artisan nightwatch:listen
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/nightwatch-tcp.log
```

### Nginx configuration

```nginx
server {
    listen 80;
    server_name nightwatch.yourdomain.com;
    root /path/to/ingest/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## 🔐 Security

### Authentication

- Token-based authentication cho agents
- API tokens cho dashboard access
- Rate limiting trên các endpoints

### Best practices

- ✅ Validate tất cả input data
- ✅ Sanitize event payloads trước khi lưu
- ✅ Use prepared statements (Eloquent tự động)
- ✅ Rate limit API endpoints
- ✅ HTTPS trong production
- ✅ Regular security updates

## 🚀 Performance

### Optimization tips

1. **Database indexing**: Đã có indexes trên các columns thường query
2. **Caching**: Sử dụng Redis cho session và cache
3. **Queue processing**: Enable queue cho event processing
4. **Database**: Tối ưu PostgreSQL với index, partitioning, và retention jobs cho time-series data
5. **Horizontal scaling**: Multiple workers cho TCP server

### Monitoring

- Laravel Telescope (development)
- Laravel Horizon (queue monitoring)
- Custom metrics dashboard

## 📚 Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Nightwatch Package](https://github.com/laravel/nightwatch)
- [Parent README](../README.md)

## 🤝 Contributing

1. Fork the repository
2. Create feature branch
3. Commit changes
4. Push to branch
5. Create Pull Request

## 📄 License

MIT
