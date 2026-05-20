# Nightwatch POC Ingest Server

Node.js prototype/reference server để test hoặc debug Nightwatch ingest protocol.

Luồng chính của project vẫn là Laravel ingest server ở root repo. Nếu bạn đang setup hệ thống ingest thật để nhận data từ các project Laravel khác, hãy bắt đầu từ [README root](../README.md) hoặc [GETTING-STARTED.md](../GETTING-STARTED.md).

## 🎯 Mục đích

POC server này dùng để:
- ✅ Test Nightwatch protocol
- ✅ Verify integration với Laravel apps
- ✅ Understand data flow
- ✅ Quick development & debugging

Không dùng `poc/` như production path mặc định của repo này.

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

## 📋 Khi nào nên dùng POC này

Dùng `poc/` khi bạn cần:

- debug nhanh TCP protocol
- verify auth / ingest payload độc lập với Laravel server chính
- đối chiếu behavior với implementation Laravel ở root repo

Nếu mục tiêu là triển khai ingest server chính, quay lại [README root](../README.md).

## 📋 Environment Variables

```bash
NIGHTWATCH_TOKEN=dev-token    # Token để authenticate (phải khớp với Laravel)
HTTP_PORT=3000                # HTTP server port
TCP_PORT=2407                 # TCP socket port
```

## 🔗 Kết nối với Laravel App

### 1. Cài đặt Nightwatch package

```bash
cd /path/to/laravel/app
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Cấu hình `.env` trong Laravel project

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=dev-token
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_BASE_URL=http://localhost:3000
NIGHTWATCH_SERVER=my-laravel-app
```

`NIGHTWATCH_BASE_URL` ở đây trỏ vào POC HTTP server để phục vụ flow debug/reference. Với luồng chính của repo, monitored app nên trỏ về Laravel ingest server ở root repo.

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
```

## 📊 Event Types

Server sẽ nhận và log các event types:

| Event Type | Trigger | Mô tả |
|------------|---------|-------|
| `request_started` / `request_finished` | HTTP request | Method, URI, status, duration |
| `query` | Database query | SQL, bindings, duration |
| `command_started` / `command_finished` | Artisan command | Command name, exit code |
| `exception` | Exception thrown | Class, message, trace |
| `log` | Log message | Level, message, context |
| `cache_hit` / `cache_miss` | Cache operation | Key, tags |

## 🔍 Example Output

```
TCP client connected from 127.0.0.1:52431

=== TCP Message ===
Version: v1
Token hash: a1b2c3d
Payload: 3 record(s)
[
  {
    "type": "request_started",
    "uuid": "9d123456-...",
    "method": "GET",
    "uri": "/api/users"
  },
  {
    "type": "query",
    "sql": "select * from users",
    "duration": 12.34
  },
  {
    "type": "request_finished",
    "status": 200,
    "duration": 156.78
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

Nightwatch sử dụng TCP protocol:

```
LENGTH:PAYLOAD_VERSION:TOKEN_HASH:DATA
```

**Ví dụ PING:**
```
15:v1:abc1234:PING
```

**Ví dụ JSON:**
```
87:v1:abc1234:[{"type":"request_started","uuid":"123"}]
```

**Server response (bắt buộc):**
```
2:OK
```

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

Nhận monitoring data qua HTTP.

**Request:**
```bash
curl -X POST http://localhost:3000/api/ingest \
  -H "Authorization: Bearer ingest-access-token" \
  -H "Content-Type: application/json" \
  -d '[{"type":"request_started"}]'
```

## 🐛 Troubleshooting

### Server không khởi động được

```bash
# Kiểm tra port đã bị chiếm chưa
lsof -i :2407
lsof -i :3000

# Kill process nếu cần
kill -9 <PID>

# Restart
npm run dev
```

### Laravel không kết nối được

**Checklist:**
- [ ] POC server đang chạy
- [ ] `NIGHTWATCH_TOKEN` khớp giữa Laravel và server
- [ ] `NIGHTWATCH_INGEST_URI=127.0.0.1:2407` (KHÔNG PHẢI localhost)
- [ ] `NIGHTWATCH_ENABLED=true`
- [ ] Đã clear cache: `php artisan config:clear`

### TCP client kết nối và ngắt ngay

**Nguyên nhân:** Server không gửi acknowledgment `2:OK`

**Giải pháp:**
1. Đảm bảo sử dụng phiên bản mới nhất
2. Restart server: `npm run dev`
3. Test: `npm test`

### Không thấy events

```env
# Giảm buffer để gửi nhanh hơn
NIGHTWATCH_INGEST_EVENT_BUFFER=10

# Capture 100% events
NIGHTWATCH_REQUEST_SAMPLE_RATE=1.0
```

## 🎯 Tips

- ✅ Dùng `127.0.0.1` thay vì `localhost`
- ✅ Token phải giống nhau
- ✅ Chạy `php artisan config:clear` sau khi thay đổi .env
- ✅ Set buffer = 10-50 để thấy events nhanh hơn
- ✅ Dùng `npm test` để verify connection

## 📚 Files

- `poc-ingest.js` - Main server implementation
- `test-connection.js` - Connection test script
- `start-dev.sh` - Auto start both POC and Laravel app
- `package.json` - NPM configuration

## 🔄 Quay lại luồng chính

POC server là prototype/reference. Khi cần ingest server chính cho môi trường thật, dùng Laravel app ở root repo:

```bash
cd ../
php artisan serve
```

## 📄 License

MIT
