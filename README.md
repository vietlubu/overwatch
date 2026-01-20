# Nightwatch POC Ingest Server

Mock server giả lập Nightwatch backend để test Laravel Nightwatch package locally.

## 🎯 Tính năng

- ✅ **TCP Socket Server** (port 2407) - Nhận data từ Laravel Nightwatch package
- ✅ **HTTP Authentication** (`/api/agent-auth`) - Xác thực agent token
- ✅ **HTTP Ingest Endpoint** (`/api/ingest`) - Nhận data qua HTTP
- ✅ **Protocol Parser** - Parse Nightwatch TCP protocol (`LENGTH:VERSION:TOKEN:DATA`)
- ✅ **Acknowledgment Response** - Gửi `2:OK` cho Laravel sau khi nhận data
- ✅ **Event logging** - Log tất cả events nhận được

## 🏗️ Kiến trúc hoạt động

```
┌─────────────────┐
│  Laravel App    │
│                 │
│  Nightwatch     │
│  Package        │
└────────┬────────┘
         │ TCP Socket
         │ (port 2407)
         │ Protocol: LENGTH:VERSION:TOKEN:DATA
         ▼
┌─────────────────┐
│ poc-ingest.js   │
│                 │
│ TCP Server      │◄──── Nhận events từ Laravel
│ (127.0.0.1:2407)│      Parse & log data
│                 │      Gửi "2:OK" acknowledgment
└─────────────────┘
```

## 🚀 Quick Start

### 1. Cài đặt dependencies

```bash
npm install
```

### 2. Khởi động server

```bash
# Sử dụng default token (dev-token)
npm run dev

# Hoặc custom token
NIGHTWATCH_TOKEN=my-secret-token node poc-ingest.js
```

Server sẽ lắng nghe trên:

- **TCP Socket**: `127.0.0.1:2407` (nhận data từ Laravel)
- **HTTP**: `http://localhost:3000` (authentication & ingest)

### 3. Test server

```bash
npm test
```

Expected output:

```
✅ Authentication successful
✅ HTTP ingest successful
✅ TCP connection established
✅ Received acknowledgment from server
✅ TCP message sent successfully
```

## 📋 Environment Variables

```bash
NIGHTWATCH_TOKEN=dev-token    # Token để authenticate (phải khớp với Laravel)
HTTP_PORT=3000                # HTTP server port
TCP_PORT=2407                 # TCP socket port
```

## 🔗 Kết nối với Laravel

### 1. Cài đặt Nightwatch package

```bash
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Cấu hình `.env` trong Laravel project

**Cấu hình tối thiểu:**

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:3000
```

**Cấu hình đầy đủ:**

```env
# Enable Nightwatch
NIGHTWATCH_ENABLED=true

# Token - PHẢI KHỚP với token của poc-ingest.js
NIGHTWATCH_TOKEN=dev-token

# TCP socket (127.0.0.1, KHÔNG PHẢI localhost)
NIGHTWATCH_INGEST_URI=127.0.0.1:2407

# HTTP base URL
NIGHTWATCH_BASE_URL=http://localhost:3000

# Server identifier (optional)
NIGHTWATCH_SERVER=my-laravel-app
NIGHTWATCH_DEPLOY=local

# Sampling rates (1.0 = capture 100%)
NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0
NIGHTWATCH_COMMAND_SAMPLE_RATE=1.0
NIGHTWATCH_EXCEPTION_SAMPLE_RATE=1.0

# Capture settings
NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD=true
NIGHTWATCH_INGEST_EVENT_BUFFER=50

# Filtering (set to true to ignore)
NIGHTWATCH_IGNORE_CACHE_EVENTS=false
NIGHTWATCH_IGNORE_QUERIES=false
```

### 3. Khởi động Laravel

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
php artisan route:list

# Database queries
php artisan migrate:status
```

### 5. Sử dụng start script (tự động start cả 2 services)

```bash
# Start cả poc-ingest.js và Laravel
./start-dev.sh /path/to/laravel/project
```

## 📊 Event Types

Server sẽ nhận và log các event types từ Nightwatch:

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

## 🔍 Example Output

Khi Laravel gửi data, bạn sẽ thấy:

```
TCP client connected from 127.0.0.1:52431

=== TCP Message ===
Version: v1
Token hash: a1b2c3d
Payload: 3 record(s)
[
  {
    "type": "request_started",
    "uuid": "9d123456-7890-...",
    "timestamp": 1705123456,
    "method": "GET",
    "uri": "/api/users",
    "hostname": "my-laravel-app"
  },
  {
    "type": "query",
    "connection_name": "mysql",
    "sql": "select * from users",
    "duration": 12.34
  },
  {
    "type": "request_finished",
    "duration": 156.78,
    "status": 200,
    "memory": 2048000
  }
]
Received 3 records via TCP
===================

TCP client disconnected
```

## 🛠️ NPM Scripts

```bash
npm start           # Khởi động server (cần set NIGHTWATCH_TOKEN)
npm run dev         # Khởi động với default token (dev-token)
npm test            # Test connection với server
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

### Các thành phần:

- **LENGTH**: Độ dài của phần còn lại (integer as string)
- **PAYLOAD_VERSION**: `"v1"` (version hiện tại)
- **TOKEN_HASH**: 7 ký tự đầu của xxh128 hash của token
- **DATA**: JSON array hoặc text (như "PING")

### Response flow:

1. Laravel kết nối TCP socket
2. Laravel gửi: `LENGTH:VERSION:TOKEN:DATA`
3. Server parse và process
4. Server phải gửi: `2:OK`
5. Laravel đóng connection

## 📝 API Endpoints

### POST `/api/agent-auth`

Authenticate agent và nhận access token.

**Request:**

```bash
curl -X POST http://localhost:3000/api/agent-auth \
  -H "Authorization: Bearer dev-token" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**Response:**

```json
{
  "token": "ingest-access-token",
  "expires_in": 3600,
  "refresh_in": 300,
  "ingest_url": "http://localhost:3000/api/ingest"
}
```

### POST `/api/ingest`

Nhận monitoring data từ agent (HTTP alternative).

**Request:**

```bash
curl -X POST http://localhost:3000/api/ingest \
  -H "Authorization: Bearer ingest-access-token" \
  -H "Content-Encoding: gzip" \
  -H "Content-Type: application/json" \
  --data-binary @payload.json.gz
```

**Response:**

```json
{
  "message": "ok"
}
```

## 🐛 Troubleshooting

### Vấn đề 1: TCP client kết nối và ngắt ngay lập tức

**Triệu chứng:**

```
TCP client connected from 127.0.0.1:63140
TCP client disconnected
TCP client connected from 127.0.0.1:63141
TCP client disconnected
```

**Nguyên nhân:** Server không gửi acknowledgment `2:OK`

**Giải pháp:**

1. Đảm bảo sử dụng phiên bản mới nhất của `poc-ingest.js`
2. Restart server: `npm run dev`
3. Test: `npm test`

### Vấn đề 2: Server không khởi động được

```bash
# Kiểm tra port đã bị chiếm chưa
lsof -i :2407
lsof -i :3000

# Kill process nếu cần
kill -9 <PID>

# Restart
npm run dev
```

### Vấn đề 3: Laravel không kết nối được

**Checklist:**

- [ ] poc-ingest.js đang chạy
- [ ] `NIGHTWATCH_TOKEN` khớp giữa Laravel và server
- [ ] `NIGHTWATCH_INGEST_URI=127.0.0.1:2407` (KHÔNG PHẢI localhost)
- [ ] `NIGHTWATCH_ENABLED=true`
- [ ] Đã clear cache: `php artisan config:clear`

**Debug steps:**

```bash
# 1. Check Laravel config
php artisan config:show nightwatch

# 2. Check token
grep NIGHTWATCH_TOKEN .env

# 3. Test connection
cd /path/to/poc-ingest
npm test

# 4. Restart everything
pkill -f "node poc-ingest.js"
pkill -f "php artisan serve"
npm run dev &
cd /path/to/laravel && php artisan serve
```

### Vấn đề 4: Không thấy events

**Nguyên nhân thường gặp:**

- Buffer size quá lớn (events chưa được gửi)
- Sampling rate < 1.0
- Event types bị ignore

**Giải pháp:**

```env
# Giảm buffer để gửi nhanh hơn
NIGHTWATCH_INGEST_EVENT_BUFFER=10

# Capture 100% events
NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0
NIGHTWATCH_COMMAND_SAMPLE_RATE=1.0

# Đừng ignore event types
NIGHTWATCH_IGNORE_QUERIES=false
NIGHTWATCH_IGNORE_CACHE_EVENTS=false
```

### Vấn đề 5: Authentication failed

**Error:** `403 [Invalid refresh token]`

**Giải pháp:**

```bash
# Token phải giống nhau
# Laravel .env:
NIGHTWATCH_TOKEN=dev-token

# Start server:
NIGHTWATCH_TOKEN=dev-token node poc-ingest.js
```

## 🎯 Tips & Best Practices

### Development

- ✅ Dùng `127.0.0.1` thay vì `localhost` cho `NIGHTWATCH_INGEST_URI`
- ✅ Token phải giống nhau ở Laravel và poc-ingest.js
- ✅ Chạy `php artisan config:clear` sau khi thay đổi .env
- ✅ Set buffer = 10-50 để thấy events nhanh hơn khi dev
- ✅ Set sampling rate = 1.0 khi testing
- ✅ Dùng `./start-dev.sh` để start cả 2 services

### Testing

```bash
# Test 1: Server connection
npm test

# Test 2: Manual TCP test
echo -n '15:v1:abc1234:PING' | nc 127.0.0.1 2407
# Expected: 2:OK

# Test 3: Laravel events
curl http://127.0.0.1:8000
php artisan inspire
```

### One-liner restart

```bash
pkill -f "node poc-ingest.js"; pkill -f "php artisan serve"; \
cd /path/to/poc && npm run dev & \
cd /path/to/laravel && php artisan config:clear && php artisan serve &
```

## 📚 Resources

- [Laravel Nightwatch Documentation](https://nightwatch.laravel.com/docs)
- [Laravel Nightwatch GitHub](https://github.com/laravel/nightwatch)

## 📄 Files

- `poc-ingest.js` - Main server
- `test-connection.js` - Connection test script
- `start-dev.sh` - Auto start both services
- `package.json` - NPM configuration

## 📄 License

MIT
