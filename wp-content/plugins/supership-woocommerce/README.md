# SuperShip for WooCommerce

**Version:** 0.1.0  
**Requires at least:** WordPress 5.8, WooCommerce 6.0  
**Tested up to:** WordPress 6.4, WooCommerce 8.5  
**PHP Version:** 7.4+  
**License:** GPL-3.0  

Tích hợp SuperShip vào WooCommerce để tự động hóa vận chuyển: tính cước tự động, tạo vận đơn, tracking, in tem, webhook.

---

## 🚀 Features

### Core Features
- ✅ **Authentication**: Bearer token với 2 modes (Personal Token / Password Grant)
- ✅ **Real-time Rates**: Tính cước tự động qua SuperShip API
- ✅ **Address System**: API-based với fuzzy matching (province/district/commune)
- ✅ **Automatic Shipment**: Tạo vận đơn tự động từ WooCommerce order
- ✅ **Tracking**: 28 SuperShip statuses → WooCommerce statuses
- ✅ **Webhook**: Real-time status updates
- ✅ **Label Printing**: In tem nhiều khổ giấy (A5, K46, T2, K50, K75, K80, S8-S14)
- ✅ **Warehouse Management**: Quản lý kho lấy hàng
- ✅ **Admin UI**: 5-tab settings, order metabox, bulk actions

### Services Implemented
1. **Rate Service**: `GET /v1/partner/orders/price` - Tính cước (Standard/Express)
2. **Shipment Service**: `POST /v1/partner/orders/add` - Tạo vận đơn (idempotency via `soc`)
3. **Tracking Service**: `GET /v1/partner/orders/info` - Lấy thông tin tracking
4. **Label Service**: 2-step (token + print URL)
5. **Cancel Service**: `POST /v1/partner/orders/cancel` - Hủy vận đơn

---

## 📦 Installation

### Option 1: Manual Install
1. Download plugin ZIP
2. WP Admin → Plugins → Add New → Upload Plugin
3. Activate plugin
4. Navigate to WooCommerce → SuperShip

### Option 2: FTP Upload
1. Extract ZIP to `wp-content/plugins/supership-woocommerce/`
2. Activate via WP Admin → Plugins

---

## ⚙️ Configuration

### Step 1: Authentication (Required)

**Go to:** WooCommerce → SuperShip → Credentials tab

**Choose authentication mode:**

#### Option A: Personal Access Token (Recommended for single shop)
```
1. Login to https://khachhang.supership.vn
2. Go to Settings → API → Generate Personal Token
3. Copy token and paste to plugin
4. Click "Save & Test Connection"
```

#### Option B: Username & Password (For multi-shop)
```
1. Enter your SuperShip account username
2. Enter password
3. Click "Save & Test Connection"
```

**Connection status:**
- ✅ Green banner = Connected (shows token expiry time)
- ⚠️ Yellow banner = Not connected

---

### Step 2: Warehouse (Required)

**Go to:** WooCommerce → SuperShip → Warehouse tab

1. Select default warehouse from dropdown
2. If no warehouses, create one in SuperShip Dashboard first
3. Warehouse used for pickup address in shipments

---

### Step 3: Shipping Settings

**Go to:** WooCommerce → SuperShip → Shipping tab

**Default Service:**
- `Tiêu Chuẩn` (Standard) - Normal delivery speed
- `Tốc Hành` (Express) - Fast delivery

**Default Config:**
- `XFAST` - Express delivery
- `XNORM` - Normal delivery

**Default Payer:**
- `1` = Sender pays shipping fee
- `2` = Receiver pays shipping fee (COD)

**Insurance:**
- Enable to add automatic insurance based on declared value

---

### Step 4: Enable Shipping Method

**Go to:** WooCommerce → Settings → Shipping → Shipping Zones

1. Select your zone (or create new)
2. Click "Add shipping method"
3. Select "SuperShip"
4. Click "Edit" to configure:
   - **Method Title**: Display name in checkout
   - **Show service options**: Yes = show Standard + Express separately, No = show cheapest only
   - **Fallback rate**: Enable flat rate when API fails (default: 30,000 VND)
   - **Free shipping minimum**: Order total for free shipping (0 = disabled)

---

### Step 5: Webhook (Optional but recommended)

**Go to:** WooCommerce → SuperShip → Webhook tab

1. Copy webhook URL (e.g., `https://yoursite.com/wp-json/supership/v1/webhook`)
2. Go to SuperShip Dashboard → Settings → Webhook
3. Paste URL and save
4. Enable "Webhook Status" in plugin

**Webhook benefits:**
- Real-time order status updates
- Automatic WooCommerce status sync
- Customer notifications

---

## 📋 Usage

### Creating Shipments

#### Method 1: Automatic on Order (Recommended)
```
1. Customer places order
2. Order status changes to "Processing"
3. Plugin automatically creates SuperShip shipment
4. Tracking number saved to order
```

#### Method 2: Manual from Order Page
```
1. Go to WooCommerce → Orders
2. Click order to edit
3. Find "SuperShip Shipment" metabox (right sidebar)
4. Click "Create Shipment" button
```

#### Method 3: Bulk Action
```
1. Go to WooCommerce → Orders
2. Select multiple orders
3. Bulk Actions → "Create SuperShip shipment"
4. Click Apply
```

---

### Printing Labels

**From Order Page:**
```
1. Open order with shipment
2. In "SuperShip Shipment" metabox
3. Click "Print Label" button
4. Label opens in new tab
```

**Paper sizes supported:**
- A5 (148×210mm) - Default
- K46 (100×150mm)
- K50, K75, K80 (thermal printer sizes)
- S8-S14 (various thermal sizes)

---

### Tracking Orders

**Automatic tracking:**
- Enable in WooCommerce → SuperShip → Advanced → Auto Tracking
- Set interval (15-1440 minutes)
- Plugin fetches tracking updates via WP Cron

**Manual refresh:**
- Open order page
- Click "Refresh" button in metabox
- Latest status fetched from SuperShip

**Customer tracking:**
- Add `[supership_tracking]` shortcode to any page
- Customers enter tracking number to see status

---

### Cancelling Shipments

**From Order Page:**
```
1. Open order
2. In "SuperShip Shipment" metabox
3. Click "Cancel" button
4. Confirm cancellation
```

**Note:** Only certain statuses are cancellable (pending, picked up, early transit)

---

## 🔧 Architecture

### File Structure
```
supership-woocommerce/
├── supership-woocommerce.php          # Main plugin file
├── includes/
│   ├── class-supership-plugin.php     # Bootstrap class
│   ├── auth/                          # Authentication layer (5 files)
│   │   ├── class-supership-auth-manager.php
│   │   ├── class-supership-auth-config.php
│   │   ├── class-supership-token-store.php
│   │   └── class-supership-secret-storage.php
│   ├── api/                           # API client layer (4 files)
│   │   ├── interface-supership-http-client.php
│   │   ├── class-supership-http-client.php
│   │   ├── class-supership-api-response.php
│   │   └── class-supership-api-error-mapper.php
│   ├── address/                       # Address system (3 files)
│   │   ├── class-supership-address-repository.php
│   │   ├── class-supership-address-cache.php
│   │   └── class-supership-address-normalizer.php
│   ├── services/                      # SuperShip services (5 files)
│   │   ├── class-supership-rate-service.php
│   │   ├── class-supership-shipment-service.php
│   │   ├── class-supership-tracking-service.php
│   │   ├── class-supership-label-service.php
│   │   └── class-supership-cancel-service.php
│   ├── tracking/                      # Tracking & status (1 file)
│   │   └── class-supership-status-mapper.php
│   ├── webhook/                       # Webhook handler (1 file)
│   │   └── class-supership-webhook-handler.php
│   ├── warehouse/                     # Warehouse management (1 file)
│   │   └── class-supership-warehouse-service.php
│   ├── admin/                         # Admin UI (2 files)
│   │   ├── class-supership-admin-settings.php
│   │   └── class-supership-order-actions.php
│   └── shipping/                      # WC Shipping Method (1 file)
│       └── class-supership-shipping-method.php
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

### Design Patterns
- **Service Layer**: Encapsulated business logic
- **Repository Pattern**: Address data access
- **Dependency Injection**: Services accept HTTP client, address repo
- **Factory Pattern**: API response creation
- **Strategy Pattern**: Auth modes (Personal Token / Password Grant)

---

## 🔐 Security

### Credentials Storage
- Tokens encrypted với AES-256-GCM (Sodium or OpenSSL)
- WordPress salts used for key derivation
- Passwords NEVER stored in plaintext
- DPAPI encryption on Windows (optional)

### API Security
- HTTPS only (strict validation)
- Whitelisted hosts: `api.mysupership.vn`
- Whitelisted paths: `/v1/partner/*`
- No path traversal, no redirects
- Bearer token auto-refresh (5min before expiry)

### Webhook Security
- Public endpoint (SuperShip doesn't support HMAC signatures)
- Idempotency via order matching
- Safe order updates only
- Debug logging (WP_DEBUG mode)

---

## 🐛 Debugging

### Enable Debug Mode

**Go to:** WooCommerce → SuperShip → Advanced → Debug Mode

**Check logs:**
```
WooCommerce → Status → Logs
Select: supership-{date}.log
```

**Log contains:**
- API requests/responses (sanitized)
- Authentication flow
- Address resolution
- Webhook payloads
- Error details

### Common Issues

#### "Not connected to SuperShip"
- Check credentials in Credentials tab
- Test connection via "Save & Test Connection"
- Check API status: https://status.mysupership.vn

#### "Province/District not found"
- Address names must match SuperShip database
- Plugin uses fuzzy matching (diacritics, spacing ignored)
- Check WooCommerce → Settings → General → State/Province format

#### "No warehouses found"
- Create warehouse in SuperShip Dashboard first
- Click "Refresh" in Warehouse tab

#### "Webhook not receiving"
- Check URL is public (not localhost)
- Test webhook: `curl -X POST {webhook_url} -d '{"type":"test"}'`
- Check firewall/security plugins

---

## 📊 Status Mapping

### SuperShip → WooCommerce

| SuperShip Status | WC Status | Category |
|-----------------|-----------|----------|
| 0 - Huỷ | Cancelled | Cancelled |
| 1-4 - Chờ/Lấy Hàng | Processing | Pending |
| 7-10 - Kho | Processing | In Transit |
| 11 - Đang Giao | Processing | Delivering |
| 12-13 - Đã Giao | Completed | Delivered |
| 14-15 - Không Giao Được | On Hold | Failed |
| 17-21 - Trả Hàng | Refunded | Returned |
| 25 - Thất Lạc | Failed | Lost |

**Customer notifications sent on:**
- Status 4 (Picked up)
- Status 11 (Out for delivery)
- Status 12/13 (Delivered)
- Status 21 (Returned)

---

## 🧪 Testing

### Test Mode
- Use SuperShip test account
- Test credentials provided by SuperShip support
- Test orders won't affect production

### Test Checklist
- [ ] Authentication (Personal Token + Password Grant)
- [ ] Rate calculation (Standard + Express)
- [ ] Shipment creation
- [ ] Label printing
- [ ] Tracking updates
- [ ] Webhook processing
- [ ] Order status sync
- [ ] Address validation
- [ ] Warehouse management
- [ ] Cancel shipment

---

## 🆘 Support

### Plugin Support
- GitHub Issues: [Your repo URL]
- Email: [Your email]

### SuperShip Support
- Dashboard: https://khachhang.supership.vn
- Hotline: 1900 636 099
- Email: support@supership.vn
- API Docs: https://docs.supership.vn

---

## 📝 Changelog

### 0.1.0 (2026-07-15)
**Initial Release**
- Bearer token authentication (2 modes)
- 5 SuperShip services (Rate, Shipment, Tracking, Label, Cancel)
- 28 status mapping
- Webhook handler
- Warehouse management
- Admin settings (5 tabs)
- WooCommerce shipping method
- Order metabox with actions
- Address validation với fuzzy matching
- Auto tracking (WP Cron)
- Debug logging

---

## 🔗 Links

- SuperShip Website: https://supership.vn
- SuperShip Dashboard: https://khachhang.supership.vn
- SuperShip API Docs: https://docs.supership.vn
- WooCommerce: https://woocommerce.com

---

## 📄 License

GPL-3.0 License. See LICENSE file for details.

---

## 👨‍💻 Credits

**Refactored from:** SPX Express for WooCommerce  
**Author:** [Your Name]  
**Contributors:** [List contributors]

**SuperShip API Integration:** Based on official SuperShip Partner API v1

---

## 🎯 Roadmap

### v0.2.0 (Planned)
- [ ] Multi-warehouse support per product
- [ ] Custom status webhooks
- [ ] Bulk label printing
- [ ] Customer tracking page
- [ ] Email templates
- [ ] SMS notifications
- [ ] Return shipment flow
- [ ] Insurance claim flow

### v0.3.0 (Future)
- [ ] WooCommerce Blocks support
- [ ] Multi-currency support
- [ ] Advanced shipping rules
- [ ] Analytics dashboard
- [ ] Performance optimizations
