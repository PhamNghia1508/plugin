# Changelog — SPX Express for WooCommerce

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
