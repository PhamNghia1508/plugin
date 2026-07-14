# Changelog — SPX Express for WooCommerce

## 0.9.0-rc.6 — Compatibility claim narrowing

- Docs (README, USER-GUIDE-vi) tuyên bố rõ tương thích: **đã xác minh với WooCommerce Classic Checkout và Checkout Blocks chuẩn**; với custom theme hoặc plugin Checkout tùy biến, cần smoke test trên staging trước khi mở bán. Không tuyên bố đã xác minh trên bất kỳ theme khách cụ thể nào.
- Không có thay đổi runtime source (chỉ docs và bump version).
- Không có API SPX Production nào được gọi trong bản này.

## 0.9.0-rc.5 — Production UI polish (Sandbox purge)

- Loại các nhãn "Sandbox" khỏi giao diện vận hành Production: action tạo vận đơn, metabox đơn hàng, tab tracking, notice tab phí, cảnh báo phí thử nghiệm.
- Nhãn "Môi trường" trong metabox và trang vận đơn nay tự động phản ánh env thật (Production/Thử nghiệm/Chưa xác định) theo dữ liệu đơn.
- Cảnh báo "hệ số 1000 thử nghiệm" chỉ hiển thị khi đơn hàng thực sự dùng đường phí thử nghiệm; đường Production (multiplier 1) không hiển thị cảnh báo.
- Thẻ Tổng quan "Phí giao hàng" hiển thị "Phí do SPX tính" khi đã xác minh; "Phí dự phòng của shop" khi chưa. Bỏ nhãn "đang thử nghiệm".
- Không thay đổi Production Enablement Gate, marker, hoặc bất kỳ safety control nào. Không có API Production nào được gọi trong bản này.

## 0.9.0-rc.4 — Non-destructive Checkout Integration & Pilot Readiness

- Thêm **SPX_Checkout_Mode_Detector** tự động phát hiện Classic Checkout, Checkout Blocks hoặc Unsupported mode; mỗi request chỉ chạy 1 adapter phù hợp.
- Tích hợp 1 control **Khu vực** duy nhất (Tỉnh → Huyện → Xã điều hướng bên trong), loại bỏ 3 thẻ select riêng; giữ nguyên 100% giao diện, theme và cổng thanh toán WooCommerce.
- Bổ sung **SPX_Shipping_Destination_Resolver** quy định thứ tự ưu tiên địa chỉ giao hàng chuẩn (Shipping address → Billing address → Blocked).
- Purge toàn bộ giao diện Sandbox trên Admin UI; hiển thị trạng thái Production Readiness và Verification marker chính xác.
- Đã bổ sung bộ kiểm thử 30 test TDD cho Checkout Integration (100% PASS requirement).

## 0.9.0-rc.3 — Production enablement path

- Thêm cơ chế **Production Enablement Gate** theo từng thao tác (account_verify / rate / create / search / tracking / label / cancel), thay ba kill-switch cứng bằng cổng có điều kiện, vẫn fail-closed mặc định.
- Thêm **marker xác minh** (`SPX_Production_Verification_Store`) chỉ lưu fingerprint HMAC + host (không lưu bí mật); tự vô hiệu khi thông tin kết nối hoặc host thay đổi.
- Bootstrap Account Verify chỉ chạy từ thao tác admin qua HTTPS, có nonce/capability/cooldown; **không tự động gọi**. Không có API Production nào được gọi trong bản này.
- Giao diện Kết nối hiển thị trạng thái xác minh; Tổng quan không còn thẻ "Môi trường: Sandbox"; tab Phí đọc đúng instance/zone (không mặc định 30.000đ).
- Đã kiểm thử offline: PHP 7.4/8.2, gate/marker/invalidation, mojibake = 0, external SPX requests = 0. *Account Verify / Rate / Create Production: chưa chạy (UNVERIFIED).*

## 0.9.0-rc.2 — Release integrity

- Chuẩn hóa các thông báo tiếng Việt bị lỗi mã hóa UTF-8 trong luồng kiểm tra thanh toán.
- Giữ nguyên nguồn chuẩn `SPX_Payment_Resolver`, quy tắc COD, fail-closed cho phí vận chuyển bằng 0, chữ ký cache theo instance/zone và khóa Create nguyên tử.
- Đóng gói từ Git tag bất biến; không mở Production API trong release gate này.

## 0.9.0-rc.1 — Release Candidate (staging)

**Đây là bản Release Candidate dành cho nghiệm thu staging, không phải bản Production cuối.**

### Đã có
- **Tích hợp Sandbox SPX:** kiểm tra phí, tạo vận đơn một lần (idempotent), tra cứu tracking, đồng bộ trạng thái định kỳ, in nhãn, và hủy vận đơn (có kiểm soát) — tất cả trên môi trường sandbox.
- **Địa chỉ SPX ở checkout:** Classic Checkout và Checkout Blocks (Store API), chọn Tỉnh/Huyện/Xã, validate phía máy chủ.
- **Phí cố định:** phương thức vận chuyển SPX với base cost + ngưỡng miễn phí.
- **Phí động thử nghiệm (UAT):** chỉ Sandbox/Local, tắt mặc định. Dữ liệu phí UAT là **mock**; hệ số ×1000 là fixture nội bộ.
- **Contract phí Production (đã chuẩn bị, vẫn khóa):** SPX xác nhận `estimated_shipping_fee` Production là **số VND đầy đủ** → multiplier = 1 (21000 → 21.000 VND). Contract chỉ được chọn khi đủ điều kiện Production; **chưa mở trong RC**.
- **Kiện hàng:** mô hình canonical dùng chung cho báo phí và tạo vận đơn; đổi đơn vị cân nặng (kg/g/lbs/oz) và kích thước (cm/mm/m/in/yd); loại sản phẩm ảo; chính sách khi thiếu dữ liệu (mặc định: không cung cấp SPX); giới hạn SPX (≤15/17 kg, mỗi chiều ≤60 cm, tổng ≤180 cm).
- **Vận đơn/Tracking/Timeline/Label/Cancel:** tạo vận đơn **thủ công**; đồng bộ định kỳ (mặc định 15 phút, retry 5/15/30 phút); timeline khách hàng theo trạng thái (**không phải GPS trực tiếp**); nhãn có thời hạn ngắn, chỉ admin; hủy vận đơn **mặc định tắt**, không hủy đơn WooCommerce, không hoàn tiền.
- **Status mapping SPX → WooCommerce:** cấu hình được; hàng hoàn **không** tự động hoàn tiền; "Đã hủy" mặc định tắt.
- **Admin UX:** trang cài đặt 8 tab; metabox SPX riêng trong đơn hàng; chi tiết kỹ thuật thu gọn; không lộ secret/PII/jargon.
- **Checkout Blocks warning remediation:** loại bỏ đăng ký block frontend dư thừa, dùng dependency asset manifest và giữ console SPX sạch trên fresh Checkout Blocks load.
- **Bản địa hóa:** catalog `.pot` / `vi.po` / `vi.mo`, dùng đúng locale WordPress `vi`.

### Giới hạn đã biết
- Production Account Verify **chưa chạy**; production shipment **chưa chạy**.
- Phí động Production **chưa bật** (chờ Gate 6G-B).
- Webhook **chưa kích hoạt** (SPX chưa cung cấp đủ tài liệu xác thực chữ ký).
- Real Cancel **mặc định tắt**.
- Trạng thái tracking có độ trễ theo scheduler.
