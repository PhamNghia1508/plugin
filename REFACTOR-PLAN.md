# SuperShip Refactor Plan - Incremental Strategy

## Philosophy: Giữ Code Quality, Refactor từng Phase

Thay vì rename tất cả 85 files một lúc (rủi ro cao), chúng ta sẽ:
1. **Giữ architecture hiện tại** (đã proven tốt)
2. **Refactor theo layers** từ trong ra ngoài
3. **Test sau mỗi layer**

## Phase 1: Foundation Layer (PRIORITY 1) - 2-3 days

### 1.1 Auth System ✅ CẦN LÀM TRƯỚC
**Mục tiêu**: SuperShip dùng Bearer token thay vì HMAC signing

**Files mới cần tạo**:
```
includes/auth/
├── class-supership-auth-config.php          (Bearer token config)
├── class-supership-auth-manager.php         (Token storage, refresh, expiry)
├── class-supership-token-store.php          (WordPress option storage)
└── class-supership-secret-storage.php       (Reuse SPX encryption logic)
```

**Strategy**:
- Copy `SPX_Secret_Storage` → `SuperShip_Secret_Storage` (giữ nguyên encryption logic)
- Viết mới `SuperShip_Auth_Manager`:
  - Store: `access_token`, `token_type`, `expires_at`
  - Methods: `get_token()`, `refresh_if_needed()`, `is_expired()`
  - Support 2 modes: Personal Access Token (static) vs Password Grant (refresh)

### 1.2 API Response Parser
**Files mới**:
```
includes/api/
├── class-supership-api-response.php         (Parse status/message/results)
└── class-supership-api-error-mapper.php     (Map SuperShip errors)
```

**Changes from SPX**:
- SPX: `ret_code` (int) + `data`
- SuperShip: `status` (Success/Error) + `message` + `results` + `errors`

### 1.3 HTTP Client
**File mới**:
```
includes/api/class-supership-http-client.php
```

**Changes**:
- SPX: HMAC signature trong header (app-id, check-sign, timestamp, random-num)
- SuperShip: `Authorization: Bearer <token>` header
- Base URL: `https://api.mysupership.vn`

## Phase 2: Address System (PRIORITY 2) - 2-3 days

### 2.1 Address Repository - API-based
**Files mới**:
```
includes/address/
├── class-supership-address-repository.php   (API calls thay vì XLSX)
├── class-supership-address-cache.php        (Cache API responses)
└── class-supership-address-normalizer.php   (COPY từ SPX_Address_Normalizer - GIỮ NGUYÊN!)
```

**Strategy**:
- **GIỮ**: `SPX_Address_Normalizer` (Vietnamese diacritic folding logic rất tốt!)
- **Viết mới**: 
  - `GET /v1/partner/areas/province` → cache 1 ngày
  - `GET /v1/partner/areas/district?province={code}` → cache 1 ngày  
  - `GET /v1/partner/areas/commune?district={code}` → cache 1 ngày
- **Fuzzy matching**: Dùng normalizer để match tên từ WC → SuperShip canonical

### 2.2 WC Address Resolver
**Keep & adapt**:
```
includes/address/class-supership-wc-address-resolver.php
```

## Phase 3: Service Layer (PRIORITY 3) - 5-7 days

### 3.1 Rate Service
**File mới**:
```
includes/api/class-supership-rate-service.php
```

**Template từ SPX_Rate_Service** (GIỐNG 80%):
1. Validate local (sender/receiver address, weight, COD)
2. Map request → SuperShip format
3. HTTP call: `GET /v1/partner/orders/price`
4. Parse response → normalized quote

**Khác biệt**:
- SuperShip cần auth (SPX docs nói không cần nhưng SuperShip YÊU CẦU)
- Params: `sender_province`, `sender_district`, `receiver_province`, `receiver_district` (String names!)
- Response: array of `{service, fee, insurance, pickup{name}, delivery{name}}`

### 3.2 Shipment Service
**File mới**:
```
includes/api/class-supership-shipment-service.php
```

**Template từ SPX_Shipment_Service** (3-state machine - GIỮ LOGIC!):
- States: `created`, `duplicate` (nếu soc trùng?), `unknown`
- Idempotency: dùng `soc` field (Sender's Order Code = WC order ID)

**Endpoint**: `POST /v1/partner/orders/add`

**Key fields**:
- `pickup_*`: Có thể dùng `pickup_code` (warehouse) hoặc chi tiết
- `name`, `phone`, `address`, `province`, `district`, `commune` (người nhận)
- `amount` (COD), `value` (trị giá), `weight` (gram)
- `service`: `1` = Tốc Hành
- `config`: `1`=Cho xem không thử, `2`=Cho thử, `3`=Không xem
- `payer`: `1`=Người gửi, `2`=Người nhận

### 3.3 Tracking Service
```
includes/api/class-supership-tracking-service.php
```

**Endpoint**: `GET /v1/partner/orders/info?code={tracking}&type=1`

**Response parsing**:
- `journeys` array → timeline events
- 28 statuses (0-27) → cần mapper

### 3.4 Label Service (2-step!)
```
includes/api/class-supership-label-service.php
```

**Steps**:
1. `POST /v1/partner/orders/token` với `code` array → get `token`
2. Generate URL: `https://khachhang.supership.vn/orders/awb?token={token}&size=A5`

**Khác SPX**: SPX trả PDF trực tiếp, SuperShip cần browser render

### 3.5 Cancel Service
```
includes/api/class-supership-cancel-service.php
```

**Endpoint**: `POST /v1/partner/orders/cancel`

## Phase 4: Status & Webhook (PRIORITY 4) - 2-3 days

### 4.1 Status Mapper - 28 statuses!
```
includes/status/class-supership-status-mapper.php
```

**SuperShip statuses** (key → value):
```
0  → Huỷ
1  → Chờ Duyệt
2  → Chờ Lấy Hàng
4  → Đã Lấy Hàng
8  → Đã Nhập Kho
11 → Đang Giao Hàng
12 → Đã Giao Hàng Toàn Bộ
15 → Không Giao Được
21 → Đã Trả Hàng
... (xem docs/integrations/supership-api-reference.md §4.3)
```

**Mapping logic** (customizable by admin):
```php
12 → wc-completed  (Đã giao)
0  → wc-cancelled  (Huỷ)
21 → wc-refunded   (Đã trả hàng - nếu admin enable)
```

### 4.2 Webhook Handler
```
includes/webhook/class-supership-webhook-controller.php
```

**Payload format**:
```json
{
  "type": "update_status",  // or "update_weight"
  "code": "SGNS...NM.810000026",
  "status": "12",
  "status_name": "Đã Giao Hàng Toàn Bộ",
  "reason_code": "304",
  "reason_text": "...",
  ...
}
```

**⚠️ WARNING**: Docs KHÔNG NÊU signature validation! Cần hỏi SuperShip support.

## Phase 5: WooCommerce Integration (PRIORITY 5) - 2-3 days

### 5.1 Shipping Method
```
includes/class-supership-shipping-method.php
```

**Giữ 95% logic từ `SPX_Shipping_Method`**:
- Extends `WC_Shipping_Method`
- Settings: base cost, free shipping threshold
- `calculate_shipping()` → call `SuperShip_Rate_Service`

### 5.2 Checkout Integration
**Files cần adapt** (ít thay đổi):
```
includes/checkout/
├── class-supership-checkout-address-service.php
├── class-supership-classic-checkout-address.php
└── class-supership-blocks-checkout-address.php
```

**Changes**: Address validation qua `SuperShip_Address_Repository` API

### 5.3 Payment Resolver
```
includes/class-supership-payment-resolver.php
```

**GIỮ NGUYÊN 100%!** Logic COD/payment state không phụ thuộc carrier.

## Phase 6: Admin UI (PRIORITY 6) - 2-3 days

### 6.1 Settings Tabs
**Giữ structure 8 tabs**, chỉ đổi content:

**Tab 1: Kết Nối**
- Personal Access Token (textarea - cho single shop)
- HOẶC Password Grant fields:
  - Client ID
  - Client Secret  
  - Username (email)
  - Password
  - Partner Code (cho multi-tenant)
- Button: "Xác Minh Token" (test API call)
- Status display: Token expires at, verified ✓/✗

**Tab 2: Kho Hàng** (NEW - SuperShip-specific!)
- List warehouses: `GET /v1/partner/warehouses`
- Add warehouse form
- Set default warehouse

**Tab 3-8**: Tương tự SPX (Phí, Đơn Hàng, Tracking, Status Mapping, Label, Cancel)

## Phase 7: Testing (PRIORITY 7) - 5-7 days

### 7.1 Unit Tests
Port 60 SPX tests sang SuperShip:
```
tests/
├── test-supership-auth-manager.php
├── test-supership-address-repository.php
├── test-supership-rate-service.php
├── test-supership-shipment-service.php
...
```

### 7.2 Integration Tests
Cần SuperShip test account:
1. Auth → get token
2. Areas API → load addresses
3. Rate → tính cước
4. Create → tạo đơn test
5. Tracking → check status
6. Label → generate token + URL
7. Cancel → hủy đơn test

## Timeline Summary

| Phase | Days | Status |
|-------|------|--------|
| Phase 1: Auth + API foundation | 2-3 | 🔴 TODO |
| Phase 2: Address system | 2-3 | 🔴 TODO |
| Phase 3: Service layer | 5-7 | 🔴 TODO |
| Phase 4: Status & Webhook | 2-3 | 🔴 TODO |
| Phase 5: WC Integration | 2-3 | 🔴 TODO |
| Phase 6: Admin UI | 2-3 | 🔴 TODO |
| Phase 7: Testing | 5-7 | 🔴 TODO |
| **TOTAL** | **20-29 days** | |

## Next Immediate Steps

1. ✅ Rename plugin identity (DONE)
2. 🔴 Create `SuperShip_Auth_Manager` + token storage
3. 🔴 Create `SuperShip_HTTP_Client` với Bearer auth
4. 🔴 Create `SuperShip_API_Response` parser
5. 🔴 Test auth flow với SuperShip test account
6. Then proceed to Address, then Services...

---

**Strategy Mantra**: 
- **Incremental**: Một layer một lúc
- **Tested**: Test sau mỗi layer trước khi tiếp
- **Safe**: Giữ proven patterns từ SPX
- **Clean**: Không để technical debt
