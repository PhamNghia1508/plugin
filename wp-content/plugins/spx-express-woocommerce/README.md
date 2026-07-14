# SPX Express for WooCommerce

**Phiên bản:** `0.9.0-rc.4` — Release Candidate dành cho staging, chưa phải bản Production cuối.

Plugin kết nối WooCommerce với SPX Express (Việt Nam). Plugin hỗ trợ:

- **Sandbox (thử nghiệm):** kiểm tra phí, tạo vận đơn một lần, tra cứu tracking, đồng bộ trạng thái định kỳ, in nhãn, và hủy vận đơn (có kiểm soát) trên môi trường sandbox của SPX.
- **Production (thật):** bị khóa cho tới khi hoàn tất xác minh tài khoản; không thể bật từ giao diện.
- **Checkout:** hoạt động với cả Classic Checkout và Checkout Blocks; địa chỉ SPX (Tỉnh/Huyện/Xã) chọn ở phần giao hàng.
- **Phí vận chuyển:** mặc định là **phí cố định**. Có một chế độ **phí động thử nghiệm** (chỉ Sandbox/Local, tắt mặc định); đơn vị phí chưa được SPX xác nhận nên **không dùng để thu tiền khách Production**.
- **Kiện hàng:** cân nặng và kích thước được tính bằng một mô hình kiện hàng chuẩn dùng chung cho cả báo phí và tạo vận đơn.
- **HPOS:** tương thích; mọi thao tác dùng WC_Order CRUD.

Xem "Kiện hàng, cân nặng và kích thước" và "Xử lý sự cố" bên dưới để vận hành.

## Trạng thái nghiệp vụ hiện tại

- **Tạo vận đơn:** thủ công (nút trong đơn hàng), không tự động.
- **Đồng bộ tracking:** định kỳ mặc định **15 phút**; thử lại lỗi tạm thời theo lịch **5/15/30 phút** (tối đa 3 lần). Trạng thái có thể chậm hơn SPX. Timeline hiển thị theo trạng thái vận đơn, **không phải định vị GPS trực tiếp**.
- **Webhook:** chưa kích hoạt (SPX chưa cung cấp đủ tài liệu xác thực chữ ký); plugin dựa vào đồng bộ định kỳ.
- **Nhãn:** liên kết nhãn có thời hạn (khoảng 25 phút), chỉ dành cho quản trị viên.
- **Hủy vận đơn:** mặc định **tắt**; chỉ hủy vận đơn SPX, **không** hủy đơn WooCommerce và **không** tự hoàn tiền.
- **Status mapping SPX → WooCommerce:** cấu hình được; hàng hoàn **không** tự động hoàn tiền; đơn "Đã hủy" mặc định tắt.
- **Production prerequisites:** HTTPS cho site, nhập credentials, chạy Account Verify, và scheduler hoạt động.

## Verification status

- **Verified:** PHP 7.4 syntax, activation with and without WooCommerce, Shipping Zone registration and instance settings, fixed/free rates, `kg`/`g`/`lbs`/`oz` conversion, Classic Cart/Checkout, Cart/Checkout Blocks, guest and customer COD checkout, mock shipment creation through the HPOS admin UI, duplicate prevention, deleted product/variation handling, customer tracking UI, safe production failure, logging redaction, responsive smoke checks, and HPOS order persistence.
- **Runtime environment used:** WordPress 6.9.4, WooCommerce 10.9.4, PHP 8.2.32, MariaDB 10.11, HPOS enabled, Docker Compose.
- **Browser E2E:** verified with local Chrome at 390×844, 768×1024, and 1440×900. The method uses WooCommerce's standard Shipping Method API, which WooCommerce exposes to classic checkout and Cart/Checkout Blocks; no separate Blocks JavaScript integration is required for rate calculation.
- **Sandbox:** admin rate, one-shot Create, Search tracking, scheduler/timeline, Label, and controlled Cancel are implemented and separately gated.
- **Production:** configuration hardening exists, but Account Verify has not been completed and every production shipment action remains blocked.
- **Not implemented:** production execution, signed inbound webhooks, COD reconciliation, and production-authorized dynamic Checkout rates. An experimental sandbox-only dynamic rate exists behind an OFF-by-default gate; see Phase 6J-X below.

## Requirements and installation

- PHP 7.4 or newer, WordPress 5.8 or newer, WooCommerce 6.0 or newer.
- Copy `spx-express-woocommerce` to `wp-content/plugins/` and activate **SPX Express for WooCommerce**.
- If WooCommerce is inactive, the plugin displays an admin notice and does not load its WooCommerce classes.

## Giao diện quản trị SPX Express

Mở **WooCommerce → SPX Express** để sử dụng giao diện cấu hình tập trung. Trang này
có tám tab: **Tổng quan**, **Kết nối API**, **Hồ sơ người gửi**, **Dữ liệu địa chỉ**,
**Phí vận chuyển**, **Tracking**, **Trạng thái đơn hàng** và **Công cụ hệ thống**.
Sau khi lưu, plugin quay lại đúng tab đang cấu hình; tab không hợp lệ luôn trở về
Tổng quan.

- **Tổng quan** hiển thị các card trạng thái và lối tắt tới phần cần cấu hình.
- **Kết nối API** tách Sandbox và Production, chỉ hiển thị trạng thái bí mật
  “Đã cấu hình/Chưa cấu hình”. Giá trị bí mật đã lưu không bao giờ được điền lại
  vào HTML; để trống trường mật khẩu sẽ giữ giá trị cũ, còn xóa cần chọn xác nhận rõ ràng.
- **Hồ sơ người gửi** quản lý thông tin shop và ba cấp Tỉnh/Thành phố,
  Quận/Huyện, Phường/Xã. **Dữ liệu địa chỉ** dùng để nhập dataset `.xlsx` chính thức.
- **Tracking** quản lý đồng bộ định kỳ và thao tác xếp hàng đồng bộ ngay. Webhook
  vẫn chưa bật vì tài liệu xác thực từ SPX chưa đầy đủ.
- **Trạng thái đơn hàng** nhóm các mapping theo mục tự động hoàn tất, đưa vào kiểm
  tra và tùy chọn nâng cao. Hủy vận đơn không đồng nghĩa với hủy đơn bán hàng;
  plugin không tự động hoàn tiền.
- **Công cụ hệ thống** chứa HPOS, scheduler, dataset và readiness diagnostics;
  phần kỹ thuật mặc định được thu gọn.

Tab **Phí vận chuyển** phân biệt phí cố định, phí SPX động thử nghiệm và phí động
Production đang bị khóa. Hệ số `1000` chỉ là candidate thử nghiệm trên
Sandbox/Local; đơn vị phí và tiền tệ vẫn chưa được SPX xác nhận, vì vậy không được
dùng để thu tiền khách Production. Chính sách fallback hiện tại được hiển thị là
phí cố định dự phòng hoặc không cung cấp phương thức SPX khi lỗi.

Trên màn hình sửa đơn hàng, toàn bộ thông tin SPX nằm trong metabox riêng
**SPX Express** ở vùng nội dung chính, tương thích HPOS và legacy order screen.
Metabox gồm Tổng quan vận đơn, Địa chỉ giao hàng SPX, Phí vận chuyển, Trạng thái
và hành trình, cùng nhóm Hành động. Tùy theo môi trường và trạng thái đơn, quản trị
viên có thể tạo vận đơn Sandbox, mở tracking, lấy/in nhãn, đồng bộ hoặc hủy khi
chức năng hủy được phép. Mock Create chỉ nằm trong **Công cụ phát triển → Tạo vận
đơn giả lập** trên môi trường local/development. Production Create vẫn bị khóa cho
đến khi Production được xác minh đầy đủ.

Các mã nội bộ, canonical address IDs, raw fee fields, cache/multiplier và audit
mapping chỉ xuất hiện khi mở **Chi tiết kỹ thuật**. Phần này không hiển thị
credential, signature, raw request/response, số điện thoại hay địa chỉ đầy đủ.
Chỉ mở các trang quản trị hoặc metabox không phát sinh request tới SPX.

## Add SPX Express to a Shipping Zone

Go to **WooCommerce > Settings > Shipping > Shipping zones**, edit a zone, choose **Add shipping method**, select **SPX Express**, and enable it. Configure the checkout title, fixed base cost, optional free-shipping minimum and estimated delivery text. Products without weight use the configurable default item weight.

The free-shipping minimum compares against WooCommerce's package contents cost. An empty or zero threshold disables free shipping. Missing or invalid cost values safely resolve to zero.

## Create a mock shipment (local/development only)

Open a WooCommerce order with a recipient address, billing phone, at least one product and valid/default weight. In the **SPX Express** metabox, expand **Công cụ phát triển** and choose **Tạo vận đơn giả lập**. Mock mode generates `SPXMOCK-YYYYMMDD-XXXXXX`, stores it through `WC_Order` CRUD, and adds an order note. Running the action again does not replace an existing tracking number.

Tracking number, status and timestamps appear in the admin order detail. Customers see only carrier, tracking number and status in their order detail—never the internal response.

## Mock versus sandbox versus production

Mock mode remains available and unchanged for local shipment creation, fixed Checkout
rates, free-shipping thresholds, and mock tracking/cancellation behavior. Separate
admin-only sandbox workflows now support rate checks, one-shot shipment creation with
Search recovery, signed tracking synchronization, and on-demand shipping labels.
Production remains disabled. Checkout uses the configured fixed rate unless the separate
Phase 6J-X experimental sandbox gate is explicitly enabled.

### API foundation (Phase 6A)

A tested, reusable API foundation exists under `includes/api/` and is used only for
credential verification so far:

- `SPX_API_Config` — reads config from WordPress constants first, then environment variables
  (`SPX_TEST_BASE_URL`, `SPX_TEST_APP_ID`, `SPX_TEST_APP_SECRET`, `SPX_TEST_USER_ID`,
  `SPX_TEST_USER_SECRET`). It never exposes secrets via `__toString()`, `__debugInfo()`, or
  its safe array. **Credentials are intentionally not stored in Shipping Zone instance
  settings** — they belong to the SPX app/account, a zone can have many instances, and a
  password field only masks the admin UI without encrypting the database value.
- `SPX_Request_Signer` — implements the official HMAC-SHA256 `check-sign` scheme.
- `SPX_HTTP_Client` — signs and POSTs via the WordPress HTTP API, with a strict host/scheme
  allow-list (test host only), TLS verification, no redirects, no auto-retry, and no
  body/header logging.
- `SPX_API_Response` / `SPX_API_Error_Mapper` — normalise responses and classify `ret_code`s.
- `SPX_Account_Service` — verifies credentials via `POST /open/api/v1/account/verify`.

Test credentials are read from the environment (see `tests/runtime/.env`, git-ignored) and
never committed. No credential form is added to the admin in this phase, and Production mode
is not enabled. `docs`: see `spx-api-analysis.md` for the full endpoint/signature analysis.

Production integration requires SPX to provide:

- Official base URL and sandbox/production environments.
- Authentication and signature specification, credential lifecycle and clock requirements.
- Request/response schemas and official endpoints for rates, create/cancel shipment, tracking and labels.
- Province/district/ward code lists and validation rules.
- Weight, dimension, value, currency, COD and service constraints.
- Error codes, retry/idempotency rules, rate limits and timeouts.
- Webhook events, signature verification, replay protection and IP guidance.

When those documents exist, implement endpoint/authentication translation only inside `SPX_Api_Provider` (and dedicated transport helpers if needed). Keep `SPX_Order_Mapper`'s internal payload independent of SPX's wire format.

## SPX addresses and sender profile (Phase 6B)

Go to **WooCommerce → SPX Express** to:

- **Import the SPX address dataset.** Upload the official SPX service-area `.xlsx`
  (`service_area_YYYYMMDD.xlsx`). Import is manual and admin-only (capability +
  nonce); it never runs on activation, checkout, cron or frontend. The file is parsed
  and validated in full and only replaces the previous dataset on success (atomic;
  rollback on any error). The dataset is stored under `wp-content/uploads/spx-express/`.
  The API download path (`get_address_download_url`) is retained for the future, but the
  UAT download host is IP-restricted, so this phase imports a **local** file.
- **Configure the sender profile** (name, phone, detail address, and SPX
  province/district/ward via dependent dropdowns). This is plugin-level configuration —
  not a Shipping Zone setting and not stored per order. Business defaults: service type 1,
  payment role 1 (sender pay), collect type 2 (drop-off).

**Orders without a ward.** WooCommerce core has no ward/commune field, so the recipient
ward usually cannot be auto-resolved. On the order screen, the **"Địa chỉ SPX"** panel lets
an admin pick the SPX province/district/ward for that order (stored via `WC_Order` meta,
HPOS-safe, without changing the customer's WooCommerce address). The panel also shows a
**shipment readiness** checklist ("Sẵn sàng tạo vận đơn SPX" or the list of missing fields).

Classic Checkout and Checkout Blocks now include one **shipping-only SPX address
selector** when the selected rate is `spx_express`. Province, district, and ward options
come only from the locally imported SPX dataset; Checkout never calls an SPX endpoint.
The validated hierarchy, service capabilities, dataset version, source, and validation
time are stored with `WC_Order` CRUD and are separate from the customer's standard
WooCommerce address fields. Billing and My Account address forms are unchanged.

## Check SPX shipping fee (Phase 6C, admin-only)

On the order screen, the **"Kiểm tra phí SPX"** order action asks the SPX **sandbox** to
price the order (`batch_check_order`) and shows the result in the **"Báo phí SPX"** panel
(estimated / basic / COD / high-value / VAT fees, plus EDT and the check time). **SPX has
not documented the fee currency/unit** (Gate A, Case C), so fees are shown as **raw
numbers with an "đơn vị chưa được SPX xác nhận" warning — never with `wc_price()`/₫** and
never assumed to be VND. It runs only when the environment is test, sandbox credentials are configured, the
sender profile is complete, and the recipient SPX address is resolved. The action is
manual (core WooCommerce order-action, capability + nonce); it never runs automatically,
on the frontend, or during Cart/Checkout.

The quote is a **reference for admins only**: it is stored as a snapshot on the order
(`_spx_rate_*` meta) but is **never added to the order total** and **never changes the
customer's shipping charge** — Checkout still uses the fixed/configured rate. Credentials,
signatures, request bodies and raw responses are never stored in order meta or logs. This
is **test mode only**; no shipment is created and production is never called.

> Note: the SPX sandbox only has delivery routes configured for some areas. A rate check
> for an unconfigured route returns a "route code is not matched by location" error — this
> is a sandbox limitation, not a plugin error.

## Shipping-only checkout address (Phase 6M)

When a physical cart uses the SPX Express shipping method, Checkout requires a valid
SPX province/district/ward hierarchy. Classic Checkout renders the selector beside the
order review. Checkout Blocks uses a locked child of the shipping-address block and the
Store API extension namespace `spx-express`. Both flows share the same local service,
capability checks, validation messages, session/customer prefill, and HPOS-safe order
snapshot.

The snapshot takes precedence for shipment mapping only while it is complete, validates
against the current dataset version, and remains serviceable for Delivery (and COD when
the payment method is COD). An explicit admin order override still has higher precedence.
Neither flow overwrites `address_1`, `address_2`, totals, the WooCommerce order status,
tracking metadata, or Mock Provider state. Virtual carts, local pickup, and non-SPX rates
do not require or persist the selector. The Checkout REST routes are read-only, return
only allowlisted hierarchy/capability fields, and never proxy a request to SPX.

## Experimental dynamic Checkout rate (Phase 6J-X)

Phase 6J-X can call sandbox `batch_check_order` while the customer uses an SPX rate in
Classic Checkout or Checkout Blocks. It is disabled by default, locked to the test
environment and sandbox host, and falls back to the configured fixed rate on any missing
prerequisite, API/business failure, or invalid fee. Turning the gate off immediately
restores fixed-rate behavior; historical orders are not recalculated.

The sandbox fee unit and currency remain unconfirmed. The experiment preserves the raw
fee fields and applies an explicit multiplier of `1000` only to produce an experimental
Checkout candidate. VAT remains audit-only and is not added again. The order stores only
allowlisted audit metadata: raw fee components, candidate, environment, conversion mode,
contract version, cache outcome, and timestamp. It never stores credentials, signatures,
full phone/address data, or raw request/response payloads.

**FEE UNIT/CURRENCY STILL UNCONFIRMED. MULTIPLIER 1000 IS EXPERIMENTAL. NOT AUTHORIZED FOR
PRODUCTION CUSTOMER CHARGES.** Production routing remains blocked.

## Controlled SPX → WooCommerce status mapping (Phase 6I)

Go to **WooCommerce → SPX Express → Tự động cập nhật trạng thái đơn WooCommerce**
to enable or disable the master switch and each approved mapping. The master switch is
ON by default. Delivered (`4001`) maps to Completed; On Hold (`3001`), Damaged (`5002`),
Lost (`5003`), and Returning (`6001`) map to On hold. Pickup Failed (`5001`), Return
Failed (`6002`), Returned (`6003`), and Cancelled (`7001`) are OFF by default. Pending
Pickup (`1001`), In Transit (`2001`), and Delivering (`2006`) never change the Woo status.

Cancelled mapping is intentionally OFF because cancelling a shipment does not always
mean cancelling the sale. Returned, when explicitly enabled, maps only to On hold:
the plugin never creates a refund or calls a payment-gateway refund API. Delivered can
change only Pending/On hold/Processing orders; exception mappings can change only
Pending/Processing orders; Cancelled can change only Pending/On hold/Processing orders.
Completed, Cancelled, Refunded, Failed, and Trash orders are protected as applicable.

Mapping runs only for a newly persisted canonical SPX status transition. Replaying the
same status does not update the order, send another WooCommerce status transition/email,
or add another plugin note. If an administrator manually changes the Woo status, the
same SPX status will not take control again; a later genuine SPX transition is evaluated.
Saving settings is not retroactive and does not scan historical orders. Mapping itself
makes no SPX API call.

The admin order screen shows **Đồng bộ trạng thái WooCommerce** with the current SPX/Woo
statuses, configured mapping, last source/time, and allowlisted result. This audit is not
shown to customers or guests. Use **Khôi phục mặc định** in the settings section to restore
the approved defaults; doing so does not re-map existing orders.

## Security and data retention

Credential settings render as password fields. Logs use WooCommerce's logger with source `spx-express` and contain only event, order ID, tracking number, and redacted error text. They never include full recipient data or credentials. Uninstall intentionally retains settings and shipment order metadata so order history is not silently destroyed.

The plugin declares WooCommerce HPOS compatibility and uses `wc_get_order()`/`WC_Order`; it does not query order tables directly.

## Production readiness hardening (Phase 6G-A)

Phase 6G-A is offline preparation only. The only environments are `test` and
`production`, with fixed origins `https://test-stable.spx.vn/` and
`https://spx.vn/`. There is no configurable API host. Shipping-zone instances remain
Mock / Manual; production cannot be verified or enabled by this phase.

This section supersedes the historical Phase 6A credential/host notes above. Custom
`SPX_TEST_BASE_URL`/production base URL settings are no longer accepted, and option
secrets are now authenticated encryption rather than password-field masking alone.

Server constants have priority over encrypted WordPress option fallback and are isolated
by environment. Test constants are `SPX_TEST_APP_ID`, `SPX_TEST_APP_SECRET`,
`SPX_TEST_USER_ID`, and `SPX_TEST_USER_SECRET`. Production constants are
`SPX_PRODUCTION_APP_ID`, `SPX_PRODUCTION_APP_SECRET`, `SPX_PRODUCTION_USER_ID`,
`SPX_PRODUCTION_USER_SECRET`, and `SPX_PRODUCTION_SHOP_ID`. Test values never fill a
missing production value. Option secrets use authenticated Sodium secretbox encryption,
or AES-256-GCM when Sodium is unavailable, with a key derived from WordPress salts.

On **WooCommerce → SPX Express**, production state may be only `disabled` or
`readiness_pending` during 6G-A. Secret fields never echo a stored value; blank preserves
it and deletion requires an explicit checkbox. Server-managed values are read-only. Any
credential fingerprint change invalidates prior verification. `verified` requires a real
Gate 6G-B account verification for the same fingerprint; `enabled` additionally requires
all readiness checks. Webhook absence and fixed-rate checkout operation remain explicit
warnings.

To rotate credentials, update the server constants or enter replacements on the SPX page;
never copy sandbox keys into production. Rotation automatically blocks production until a
new real verification succeeds. Production also requires HTTPS for both WordPress Home and
Site URLs, a healthy Action Scheduler or WP-Cron fallback, HPOS compatibility, a valid
sender/dataset, and supported PHP/WP/WooCommerce versions. `SPX_PRODUCTION_SHOP_ID` is
treated as required until SPX confirms whether Shop ID is optional and what it represents.
Secret fields are never populated back into HTML. If WordPress salts change, encrypted
option secrets must be entered again; deployments that cannot provide Sodium or
AES-256-GCM must configure secrets only through server constants.

Real SPX shipment metadata is bound immutably to `_spx_environment`; the legacy
`_spx_shipment_environment` remains readable for existing sandbox history. Tracking
batches cannot mix environments. Production operations fail before signing or transport
until the future controlled Gate 6G-B succeeds. No production Create, Search, Rate,
Cancel, Label, webhook, or cron behavior was enabled in this phase.

## Shipping labels and controlled cancellation (Gate 6F-A)

On a sandbox order with a real `SPXVN...` tracking number, an administrator with
`manage_woocommerce` can use **Lấy/In nhãn SPX**. The action is POST-only and nonce
protected. SPX label links are treated as untrusted: only HTTPS links on an exact verified
host are accepted, IP/localhost and unsafe schemes are rejected, redirects are not
followed, and the plugin never downloads the label automatically. The documented link
lifetime is 30 minutes with at most 10 opens; the plugin caches it for only 25 minutes and
does not store the URL in order meta. Do not share a label URL.

The **Hủy vận đơn SPX** control is shown only for sandbox shipments whose locally known
SPX code is Pending Pickup (`1001`). Before any real cancellation, the manager must query
the canonical status with `batch_search_order`; only canonical `1001` is eligible. An
atomic per-order lock and durable `processing/succeeded/unknown/failed` state prevent
duplicate requests. A timeout or indeterminate response becomes `unknown` and is never
blindly retried; reconciliation uses Search only, and canonical `7001` updates the SPX
timeline to Cancelled. Cancelling an SPX shipment does **not** cancel the WooCommerce
order, change its status/totals, or issue a refund.

Gate 6F-A is sandbox-only. The real Cancel endpoint is locked and was not called. The
tracking webhook remains **BLOCKED BY DOCUMENTATION**; the signed Search scheduler and
manual **Đồng bộ ngay** queue action remain the fallback.

## Manual smoke test

1. Activate WooCommerce, then this plugin.
2. Add SPX Express to a matching Shipping Zone in Mock / Manual mode.
3. Set a base cost and free-shipping minimum; test cart totals below and at the threshold.
4. Create an order with address, phone, item quantity and product weight (or default weight).
5. Run **Create SPX shipment**, reload the order, and confirm tracking persists.
6. View the order as its customer and confirm only carrier, tracking and status are visible.

## Kiện hàng, cân nặng và kích thước

Cân nặng và kích thước kiện hàng được tính bằng **một mô hình kiện hàng chuẩn (canonical parcel)** dùng chung cho cả báo phí (checkout) và tạo vận đơn, nên hai luồng luôn khớp nhau.

- **Đổi đơn vị:** cân nặng đổi về **kg** (hỗ trợ kg/g/lbs/oz), kích thước đổi về **cm** (hỗ trợ cm/mm/m/in/yd).
- **Số lượng & biến thể:** tính theo số lượng; biến thể thiếu cân nặng/kích thước sẽ lấy từ sản phẩm cha.
- **Sản phẩm ảo/tải về:** không tính vào kiện hàng.
- **Gộp nhiều sản phẩm (xấp xỉ, không phải tối ưu đóng gói 3D):** cân nặng = tổng theo số lượng; chiều dài = lớn nhất; chiều rộng = lớn nhất; chiều cao = tổng theo số lượng.
- **Giới hạn SPX:** cân nặng ≤ 15 kg (báo phí) / ≤ 17 kg (tạo vận đơn); mỗi chiều ≤ 60 cm; tổng ba chiều ≤ 180 cm.

### Kiện hàng mặc định và chính sách thiếu dữ liệu

Tại **SPX Express → Phí vận chuyển → Kiện hàng mặc định**:

- Nhập cân nặng/kích thước mặc định (chỉ dùng cho sản phẩm chưa khai báo).
- Chọn chính sách khi sản phẩm thiếu dữ liệu:
  - **Không cung cấp vận chuyển SPX** (mặc định, an toàn cho Production): ẩn phương thức/không cho tạo vận đơn và hiển thị lý do tiếng Việt.
  - **Dùng thông số kiện hàng mặc định:** dùng giá trị mặc định đã cấu hình.

Plugin **không bao giờ** tự dùng giá trị 0 cho cân nặng/kích thước bị thiếu.

## Xử lý sự cố (Troubleshooting)

- **Không thấy phương thức SPX ở checkout:** kiểm tra sản phẩm có cân nặng/kích thước; nếu chính sách là "Không cung cấp vận chuyển SPX" và sản phẩm thiếu dữ liệu thì phương thức sẽ bị ẩn. Bổ sung dữ liệu sản phẩm hoặc cấu hình kiện hàng mặc định.
- **Sản phẩm thiếu cân nặng:** khai báo cân nặng cho sản phẩm/biến thể, hoặc đặt cân nặng mặc định.
- **Sản phẩm thiếu kích thước:** khai báo kích thước, hoặc đặt kích thước mặc định, hoặc đổi chính sách sang "Dùng thông số kiện hàng mặc định".
- **Phí vẫn là phí cố định:** đúng như mặc định. Phí động chỉ chạy ở Sandbox/Local khi được bật rõ ràng và có đủ điều kiện; Production luôn dùng phí cố định.
- **Tracking chưa cập nhật:** đồng bộ chạy định kỳ (mặc định 15 phút) và có thể chậm hơn SPX; dùng "Đồng bộ ngay" trong tab Tracking hoặc nút "Đồng bộ ngay" trong đơn hàng.
- **Scheduler không hoạt động:** kiểm tra tab Tracking (Tình trạng đồng bộ). Cần WP-Cron hoặc Action Scheduler hoạt động; nếu site ít truy cập, cân nhắc cron hệ thống.
- **Nhãn hết hạn:** liên kết nhãn có thời hạn ngắn; bấm "Lấy/In nhãn SPX" lại để tạo liên kết mới.
- **Không hủy được vận đơn:** chức năng hủy thật mặc định tắt; nếu đã bật mà vẫn không hủy được thì vận đơn không còn ở trạng thái đủ điều kiện hủy.
- **Production bị khóa:** cần site chạy HTTPS, nhập credentials, và hoàn tất Account Verify; Production không thể bật chỉ từ giao diện.
