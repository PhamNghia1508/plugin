# Changelog — SuperShip for WooCommerce

## 1.0.2 — Sửa lỗi vỡ layout checkout trên theme tùy biến

- **Sửa checkout bị đè/vỡ layout trên Flatsome (và các theme có sẵn bố cục checkout riêng):** bản trước ép `display:grid` hai cột + thẻ bọc + sticky lên form checkout, đá nhau với layout riêng của theme khiến các khối chồng lên nhau (đè cả lên header). Nay CSS chỉ tô đẹp **các trường** (ô nhập, nhãn, dropdown, nút Đặt hàng, badge COD) — **không đụng tới bố cục cột**, để theme tự lo. Nhờ vậy checkout hiển thị đúng trên mọi theme.

## 1.0.1 — Sửa lỗi cân nặng + tương thích giao diện theme

- **Sửa lỗi tính cân nặng theo đơn vị site (bug nghiêm trọng):** trước đây code luôn nhân cân nặng với 1000 (giả định đơn vị "kg"). Nếu site cấu hình đơn vị khối lượng là "g" (gram), một sản phẩm nhập 1000 (=1kg) bị tính thành 1.000.000g = 1 tấn → vượt giới hạn 50kg của SuperShip → không tạo được vận đơn ("Trường weight không được lớn hơn 50000"). Nay dùng `wc_get_weight()` của WooCommerce để đổi đúng từ đơn vị site (kg/g/lbs/oz) sang gram. Sửa ở cả 2 chỗ: tính cước checkout và tạo vận đơn.
- **Sửa thẻ (card) checkout không hiện đủ trên theme tùy biến (Flatsome...):** vẽ thẻ lên 2 ID lõi của WooCommerce (`#customer_details`, `#order_review`) thay vì lớp `.col-1`/`.col-2` mà nhiều theme không dựng — nhờ vậy cột trái luôn có thẻ trắng bo góc/đổ bóng đồng nhất với cột phải, trên mọi theme. Toàn bộ thông tin giao hàng gộp gọn trong một thẻ.

## 1.0.0 — Bản phát hành chính thức

Bản hoàn chỉnh sẵn sàng bàn giao, thuần Việt, tối ưu cho người dùng không chuyên kỹ thuật.

**Cài đặt là dùng được ngay**
- Tự động tạo trang **"Tra cứu đơn hàng"** khi kích hoạt plugin (không cần dán shortcode thủ công). Idempotent: khôi phục nếu bị bỏ thùng rác, dùng lại trang có sẵn nếu chủ shop đã tạo.
- Nâng version lên 1.0.0; dọn file ngôn ngữ cũ của SPX, sinh `supership-woocommerce.pot` đúng text-domain (359 chuỗi).
- `uninstall.php` dọn sạch mọi option/transient/cron của plugin khi gỡ, giữ lại order meta để bảo toàn lịch sử đơn.

**Khách hàng (front-end)**
- Trang thanh toán thiết kế lại theo phong cách thẻ hiện đại, thuần Việt, dropdown Tỉnh → Quận/Huyện → Phường/Xã tự động từ dữ liệu SuperShip, thanh toán COD.
- Chế độ "Shop chịu phí ship": khách thấy miễn phí vận chuyển, phí thật lưu ẩn cho admin xem.
- Trang tra cứu đơn bằng số điện thoại: giao diện thẻ, badge trạng thái theo màu, và **timeline hành trình đơn hàng** (đơn đang ở đâu, khi nào).

**Chủ shop (admin)**
- Trang **Vận đơn SuperShip**: 5 nhóm trạng thái lọc nhanh (đếm bằng SQL), tìm kiếm theo mã vận đơn / tên / SĐT, in phiếu từng dòng, phân trang tại SQL cho hiệu năng ổn định.
- Cột "Vận đơn" trong danh sách đơn; ô tạo/in/huỷ/cập nhật vận đơn trong từng đơn.
- Toàn bộ trang cài đặt và thông báo lỗi thuần Việt, chỉ rõ chỗ khắc phục.
- Thông báo dạng toast + hộp xác nhận đẹp thay cho alert()/confirm() của trình duyệt.
- Dọn gọn màn hình sửa đơn: ẩn các ô không liên quan tới giao hàng.

**Sửa lỗi tích hợp**
- Sửa lỗi phí ship luôn rơi về mức cố định 30.000đ do đọc sai tên trường kho (`province_name`/`district_name`).
- Đơn tạo từ checkout tự điền địa chỉ giao hàng (trước đây trống do đã bỏ mục "giao địa chỉ khác").

## 0.9.0-rc.11 — Vietnam Checkout functional fix (SPX Express, lịch sử)

- Loại bỏ nguồn validation Họ tên trùng: first/last name là hidden input thực, non-required; chỉ validator `billing_full_name` phát một lỗi tiếng Việt.
- Không suy đoán cấu trúc tên Việt Nam: lưu nguyên chuỗi chuẩn hóa vào `billing_first_name`, để trống `billing_last_name`, đồng thời lưu `_spx_billing_full_name` bằng WC_Order CRUD.
- SPX recipient name ưu tiên `_spx_billing_full_name` qua resolver dùng chung; checkout rate context cũng ưu tiên full name chính xác.
- Tách callback billing/shipping để không chèn `billing_full_name` trùng vào nhóm shipping.
- Classic Checkout ordering: Họ tên 10, phone 20, email 30, Khu vực 40, địa chỉ 50, ghi chú 70; Khu vực dùng custom WooCommerce field type, không phải native select.
- Phone/email cùng hàng desktop và full width mobile; Khu vực có hành động “Đổi khu vực” gọn, giữ gateway sau `update_checkout`.
- Customer-review theme, offline gateways và preview shipping là staging-only, nằm ngoài plugin ZIP; không gọi SPX Production API.

## 0.9.0-rc.10 — Vietnam Checkout profile

- **`SPX_VN_Checkout_Profile`** (opt-in via filter `spx_vn_checkout_profile_enabled`, default true): tối ưu Classic Checkout cho khách Việt Nam.
  - Thay 2 ô "Tên / Họ" bằng **một field "Họ tên *"** (billing_full_name); tự split thành first_name/last_name khi persist vào WC_Order để tương thích admin/email/label. Family-name-first split (chuẩn tên Việt).
  - Ẩn khỏi UI: **Country / Company / City / State / Postcode / Address 2** (fields vẫn có trong form, giá trị vẫn submit — chỉ UI được ẩn qua `.form-row.spx-vn-hidden-field`).
  - Country **auto-VN** qua `default_checkout_billing_country`.
  - Locale VN: **phone required**, postcode/city/state không required — fix nhãn "Số điện thoại (tuỳ chọn)" → "Số điện thoại *" và bỏ lỗi "Mã bưu điện không hợp lệ".
  - Email **optional** cho guest; label + placeholder tiếng Việt.
  - Địa chỉ (`billing_address_1`) relabel "Địa chỉ" với placeholder "Số nhà, tên đường, thôn/xóm…".
  - Order comments relabel "Ghi chú đơn hàng".
- CSS: 1 dòng `!important` scoped duy nhất trên `.form-row.spx-vn-hidden-field` (justification: WooCommerce/theme cascade override — chỉ match wrapper mang class marker riêng của plugin).
- Test: 31-assertion `test-spx-vn-checkout-profile.php` (RED→GREEN). Full suite **61/61 PASS** trên PHP 7.4 + 8.2.
- Không thay đổi Enablement Gate, marker, Payment Resolver, atomic Create lock, hoặc safety controls khác. Không có API SPX Production nào được gọi trong bản này.

## 0.9.0-rc.9 — Fix: "Khu vực" field visible on first Checkout load

- **Fix (P0)**: field "Khu vực" trước đây bị render `display:none` trên Classic Checkout khi khách chưa chọn phương thức vận chuyển SPX — nhưng phương thức lại chỉ hiện sau khi có địa chỉ SPX. Kết quả: khách không bao giờ thấy được field để chọn Tỉnh/Huyện/Xã, Checkout báo "không có tùy chọn vận chuyển".
  - PHP: tách "render visibility" (hiện khi cart cần shipping AND SPX có mặt trong zone) khỏi "validation requirement" (chỉ bắt buộc khi SPX là phương thức được chọn) qua `SPX_Checkout_Eligibility::should_render_field()` mới. Bỏ inline `style="display:none"` khỏi container.
  - JS: `refreshVisibility()` không còn `$control.toggle()` theo chosen method — PHP đã quyết định visibility ở render-time; JS không được hide lại.
  - Container nhận thêm class `form-row form-row-wide` để theme render đúng như checkout field chuẩn.
- Test: RED-first `test-spx-checkout-eligibility-render.php` với 7 assertions; toàn bộ 60/60 test PASS trên PHP 7.4 + 8.2.
- Không thay đổi Enablement Gate, Verification marker, Payment Resolver, Atomic Create lock, hoặc bất kỳ safety control nào. Không có API SPX Production nào được gọi trong bản này.

## 0.9.0-rc.8 — Final offline handover polish

- **Setup checklist** trên tab Tổng quan: 5 bước được đánh dấu Hoàn tất / Chưa hoàn tất theo dữ liệu thật (kết nối, hồ sơ người gửi, dữ liệu địa chỉ, xác minh SPX, đồng bộ tracking), có nút "Đi tới bước này". Chỉ hướng dẫn, không tự động gọi API.
- **Field Khu vực polish (theme-safe)**: bổ sung CSS variables (`--spx-location-height`, `--spx-location-radius`, `--spx-location-border`, `--spx-location-accent`) để theme override, kế thừa font/color từ theme, styling cho action "Đổi khu vực", chống zoom iOS ở mobile. Vẫn scope 100% dưới `.spx-location-control` / `.spx-checkout-field` / `.spx-checkout-notice` — không selector global, không `!important`.
- Không thay đổi Enablement Gate, Verification marker, Payment Resolver, Atomic Create lock, hoặc bất kỳ safety control nào. Không có API SPX Production nào được gọi trong bản này.

## 0.9.0-rc.7 — Security reset + Vietnamese Admin wording

- **Security reset**: Production verification marker được `invalidate` sau lần Account Verify không thành công. Toàn bộ thao tác Production (Rate/Create/Search/Tracking/Label/Cancel) vẫn ở trạng thái fail-closed cho tới khi chủ shop cập nhật thông tin kết nối mới và bấm "Xác minh kết nối SPX".
- **Admin wording**: block Production readiness đổi sang shop-owner-friendly Vietnamese ("Kết nối SPX chưa được xác minh…").
- Không thay đổi Enablement Gate, Payment Resolver, Atomic Create lock, hoặc bất kỳ safety control nào. Không có API SPX Production nào được gọi trong bản này.
- Password fields trong tab Kết nối vẫn `value=""` (không echo secret).

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
