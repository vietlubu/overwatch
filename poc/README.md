# POC Reference

`poc/` là prototype Node.js để debug nhanh Nightwatch ingest protocol.

Nếu mục tiêu của bạn là chạy Overwatch thật cho release, hãy dùng tài liệu chính ở [README root](../README.md). Thư mục này chỉ nên dùng khi cần:

- debug TCP framing / ACK
- so sánh hành vi giữa prototype và implementation Laravel
- test nhanh một flow ingest độc lập với app Laravel chính

## Chạy POC

```bash
npm install
npm run dev
```

Mặc định POC mở:

- TCP: `127.0.0.1:2407`
- HTTP: `http://127.0.0.1:3000`

## Test kết nối

```bash
npm test
```

## Env chính

```env
NIGHTWATCH_TOKEN=dev-token
HTTP_PORT=3000
TCP_PORT=2407
```

## Lưu ý

- POC có flow HTTP auth/ingest riêng để phục vụ debug.
- Flow đó không phải source of truth cho Overwatch release.
- Với app Laravel thật gửi event vào Overwatch, làm theo hướng dẫn ở [README root](../README.md).
