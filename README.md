# Overwatch

Overwatch là Laravel ingest server cho `laravel/nightwatch`.

Repo này nhận payload TCP từ các Laravel app khác, xác thực ingest key theo `project`, và lưu dữ liệu vào database để phục vụ API, rollup, cleanup, và các bước phân tích tiếp theo.

## Repo này dùng để làm gì

- Chạy TCP listener cho Nightwatch.
- Quản lý project và ingest key.
- Lưu raw events và detail tables vào các bảng `nw_*`.
- Cung cấp API đọc dữ liệu đã ingest.
- Tự verify end-to-end bằng self-test harness.

`poc/` vẫn còn trong repo nhưng chỉ là prototype/debug reference, không phải luồng triển khai chính.

## Yêu cầu

- PHP `^8.2`
- Composer
- Node.js + npm
- SQLite, MySQL, hoặc Postgres

## Cấu hình nhanh Overwatch

### Cách nhanh nhất

```bash
composer setup
```

Lệnh này sẽ:

- cài PHP dependencies
- tạo `.env` nếu chưa có
- generate app key
- tạo `database/database.sqlite` nếu chưa có
- chạy migrate
- cài Node dependencies
- build frontend assets

### Cách setup thủ công

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Nếu dùng SQLite mặc định:

```bash
touch database/database.sqlite
php artisan migrate
```

Nếu dùng MySQL/Postgres, sửa DB config trong `.env` trước khi chạy migrate:

```bash
php artisan migrate
```

Sau khi xong phần database:

```bash
npm install
npm run build
```

## Chạy Overwatch local

### Cách nhanh cho vòng lặp dev

```bash
composer run dev
```

Lệnh này sẽ bật nhanh:

- HTTP app
- queue listener
- logs tail
- Vite dev server

Lưu ý: `composer run dev` không chạy TCP ingest listener, nên bạn vẫn cần thêm một terminal riêng:

```bash
php artisan nightwatch:listen
```

### Chạy thủ công

Mở 2 terminal riêng:

```bash
php artisan nightwatch:listen
php artisan serve
```

Mặc định:

- HTTP app: `http://127.0.0.1:8000`
- TCP ingest listener: `127.0.0.1:2407`

Các biến môi trường chính:

```env
OVERWATCH_TCP_HOST=127.0.0.1
OVERWATCH_TCP_PORT=2407
OVERWATCH_RETENTION_DAYS=30
OVERWATCH_ROLLUP_RETENTION_DAYS=180
OVERWATCH_ROLLUP_SCHEDULE_ENABLED=true
OVERWATCH_ROLLUP_SCHEDULE_EVERY_MINUTES=1
OVERWATCH_CLEANUP_SCHEDULE_ENABLED=true
OVERWATCH_CLEANUP_SCHEDULE_DAILY_AT=02:00
```

## Tạo project và ingest key

Tạo một tenant cho app sẽ được monitor:

```bash
php artisan nightwatch:project:create demo-app --name="Demo App" --tags=internal,local
```

Lệnh này sẽ tạo project và sinh ingest key ngay trong một bước. Secret chỉ được hiển thị đúng một lần, kèm đoạn env như sau:

```env
NIGHTWATCH_TOKEN=...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=your-deploy-name
NIGHTWATCH_SERVER=your-server-name
```

`NIGHTWATCH_TOKEN` là secret, chỉ được hiển thị một lần. Hãy copy ngay sang project cần monitor.

Nếu cần cập nhật metadata nội bộ của project:

```bash
php artisan nightwatch:project:update demo-app --name="Demo App" --tags=internal,staging
```

Nếu cần rotate ingest key:

```bash
php artisan nightwatch:project:rotate-key demo-app
```

## Setup một project Laravel khác dùng Nightwatch với Overwatch

Giả sử bạn có một app Laravel khác tên là `my-app`.

### 1. Cài Nightwatch trong app đó

```bash
composer require laravel/nightwatch
php artisan vendor:publish --tag=nightwatch-config
```

### 2. Cập nhật `.env` của app được monitor

Dán các giá trị lấy từ Overwatch:

```env
NIGHTWATCH_ENABLED=true
NIGHTWATCH_TOKEN=...secret from overwatch...
NIGHTWATCH_INGEST_URI=127.0.0.1:2407
NIGHTWATCH_DEPLOY=local
NIGHTWATCH_SERVER=my-app-web-1
```

Nếu app của bạn chạy trên máy khác, `NIGHTWATCH_INGEST_URI` phải trỏ tới host/port thực tế của Overwatch, ví dụ:

```env
NIGHTWATCH_INGEST_URI=10.0.0.15:2407
```

### 3. Reload config trong app được monitor

```bash
php artisan config:clear
```

### 4. Trigger event để kiểm tra ingest

Ví dụ nếu app monitor đang chạy local:

```bash
php artisan serve --port=8001
```

Rồi trigger một vài event:

```bash
php artisan inspire
curl http://127.0.0.1:8001
```

Chỉ cần Overwatch listener đang chạy, token đúng, và app monitor có thể kết nối tới `NIGHTWATCH_INGEST_URI`, event sẽ được ghi vào các bảng `nw_*`.

## Luồng tích hợp ngắn gọn

1. Chạy Overwatch bằng `php artisan nightwatch:listen`.
2. Tạo `project` trên Overwatch, lệnh create sẽ sinh ingest key luôn.
3. Cài `laravel/nightwatch` ở app khác.
4. Copy `NIGHTWATCH_TOKEN` và `NIGHTWATCH_INGEST_URI` sang app đó.
5. Trigger request/command/job để app gửi event về Overwatch.

## Self-test end-to-end

Repo này có sẵn harness để tự verify toàn bộ ingest pipeline:

```bash
php artisan nightwatch:test-events --timeout=25
php artisan nightwatch:test-events --days-back=30 --concurrent-min=3 --concurrent-max=8
php artisan nightwatch:test-events --days-back=30 --concurrent-min=30 --concurrent-max=80 --users=20
```

Harness sẽ tự:

1. tạo hoặc reuse project self-test và rotate key cho run hiện tại
2. chạy listener và helper processes
3. phát request, command, queue, schedule, notification, mail, cache, query, outgoing request, exception
4. kiểm tra dữ liệu đã được lưu vào database

Nếu cần bơm nhiều dữ liệu hơn để test dashboard / rollup:

- `--days-back=N` sẽ replay lại bộ event chuẩn từ `N` ngày trước đến hiện tại
- `--concurrent-min` / `--concurrent-max` sẽ tạo ngẫu nhiên từ bao nhiêu đến bao nhiêu batch mỗi ngày
- `--users=N` sẽ giới hạn số self-test user được reuse trong phần replay; nếu `nw_users` đã có đủ self-test user thì sẽ không tạo thêm
- batch của ngày hiện tại đã có sẵn từ run gốc, nên replay chỉ cộng thêm phần chênh lệch để tránh đếm trùng logic verify

Đây là cách nhanh nhất để xác nhận repo còn hoạt động tốt trước release.

## Lệnh hữu ích

```bash
php artisan nightwatch:listen
php artisan nightwatch:project:create {slug} --name="Project Name" --tags=internal
php artisan nightwatch:project:update {project} --name="Project Name" --tags=internal,prod
php artisan nightwatch:project:rotate-key {project}
php artisan nightwatch:test-events
php artisan nightwatch:rollup
php artisan nightwatch:cleanup
php artisan test
```

## API hiện có

Repo hiện expose các endpoint đọc dữ liệu như:

- `/api/projects`
- `/api/requests`
- `/api/exceptions`
- `/api/jobs`
- `/api/commands`
- `/api/scheduled-tasks`
- `/api/queries`
- `/api/notifications`
- `/api/mail`
- `/api/cache`
- `/api/outgoing-requests`
- `/api/users`
- `/api/logs`

## Troubleshooting

### Listener không bind được cổng

```bash
lsof -i :2407
```

### App được monitor không gửi event

Kiểm tra lại:

- `NIGHTWATCH_ENABLED=true`
- `NIGHTWATCH_TOKEN` đúng với key được tạo từ Overwatch
- `NIGHTWATCH_INGEST_URI` đúng host và port của listener
- đã chạy `php artisan config:clear` sau khi sửa `.env`
- app monitor có network reachability tới máy chạy Overwatch

### Muốn tắt self-monitoring của chính Overwatch

Repo này đã để mặc định:

```env
NIGHTWATCH_ENABLED=false
```

Giá trị này nên giữ nguyên trong vận hành bình thường. Chỉ self-test harness mới override để phát event kiểm thử.

## Prototype / debug reference

Nếu cần đối chiếu với prototype Node.js để debug protocol, xem [poc/README.md](./poc/README.md).
