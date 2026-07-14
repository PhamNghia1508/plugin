# Hướng dẫn sử dụng — SPX Express for WooCommerce (RC)

Tài liệu này dành cho **chủ shop**. Bản hiện tại là **Release Candidate cho staging**.

## 1. Yêu cầu hệ thống
- WordPress 5.8+; WooCommerce 6.0+; PHP 7.4+.
- Khuyến nghị bật **HPOS** (High-Performance Order Storage). Production yêu cầu site chạy **HTTPS**.

## 2. Cài plugin
Tải file `spx-express-woocommerce-0.9.0-rc.4.zip` lên qua **Plugins → Add New → Upload Plugin**, hoặc giải nén vào `wp-content/plugins/`.

## 3. Kích hoạt
Kích hoạt **SPX Express for WooCommerce**. Nếu WooCommerce chưa bật, plugin chỉ hiển thị thông báo và không tải các lớp phụ thuộc — **không gây lỗi nghiêm trọng**.

## 4. Cấu hình kết nối
**WooCommerce → SPX Express → Kết nối API**. Sandbox dùng để kiểm thử. Giá trị bí mật không bao giờ hiển thị lại; để trống để giữ nguyên. **Production đang khóa** cho tới khi xác minh tài khoản.

## 5. Hồ sơ người gửi
Tab **Hồ sơ người gửi**: tên, số điện thoại, địa chỉ chi tiết, Tỉnh/Huyện/Xã.

## 6. Dữ liệu địa chỉ
Tab **Dữ liệu địa chỉ**: nhập/kiểm tra dataset địa chỉ SPX (`.xlsx`).

## 7. Cân nặng và kích thước sản phẩm
Khai báo cân nặng và kích thước cho từng sản phẩm/biến thể. Plugin đổi về **kg** và **cm** theo đơn vị của cửa hàng. Sản phẩm ảo/tải về không tính vào kiện hàng.

## 8. Kiện hàng mặc định
Tab **Phí vận chuyển → Kiện hàng mặc định**: đặt cân nặng/kích thước mặc định (chỉ dùng cho sản phẩm chưa khai báo) và chọn chính sách khi thiếu dữ liệu:
- **Không cung cấp vận chuyển SPX** (mặc định): ẩn phương thức khi sản phẩm thiếu dữ liệu.
- **Dùng thông số kiện hàng mặc định.**

## 9. Phí cố định
Thêm **SPX Express** vào Shipping Zone (**WooCommerce → Settings → Shipping**), đặt base cost và ngưỡng miễn phí.

## 10. Phí động và giới hạn hiện tại
Phí động chỉ chạy ở **Sandbox/Local** khi bật rõ ràng; dữ liệu UAT là **mock**. **Production dùng phí cố định** trong RC. SPX đã xác nhận phí Production là số VND đầy đủ (multiplier 1) nhưng **chưa bật** — chờ Gate 6G-B.

## 11. Classic Checkout
Khách chọn Tỉnh/Huyện/Xã SPX ở phần giao hàng; validate phía máy chủ.

## 12. Checkout Blocks
Tương tự Classic, tích hợp Store API; đổi số lượng sẽ tính lại phí.

## 13. Tạo vận đơn thủ công
Mở đơn hàng → metabox **SPX Express** → **Tạo vận đơn SPX Sandbox**. **Create là thao tác thủ công**, không tự động. Kiện hàng không hợp lệ sẽ ẩn nút và hiển thị lý do tiếng Việt.

## 14. Tracking
Đồng bộ định kỳ (xem mục 19). Có thể "Đồng bộ ngay" trong tab Tracking hoặc trong đơn hàng. **Trạng thái có độ trễ theo scheduler.**

## 15. Timeline khách hàng
Khách xem hành trình trong trang đơn hàng/tài khoản. **Timeline theo trạng thái vận đơn, không phải định vị GPS trực tiếp.**

## 16. Label
Trong metabox: **Lấy/In nhãn SPX**. Liên kết nhãn có **thời hạn ngắn**; hết hạn thì bấm lại để tạo liên kết mới. Chỉ quản trị viên thấy.

## 17. Cancel policy
Hủy vận đơn **mặc định tắt**. Khi bật, chỉ hủy **vận đơn SPX** và không hủy đơn WooCommerce. Plugin không tự hoàn tiền.

## 18. Mapping trạng thái
Tab **Trạng thái đơn hàng**: bật/tắt ánh xạ SPX → WooCommerce. Hàng hoàn **không** tự động hoàn tiền; "Đã hủy" mặc định tắt.

## 19. Scheduler
- **Chu kỳ mặc định của plugin: 15 phút.** Giá trị **đang cấu hình** có thể khác (xem tab Tracking → Tình trạng đồng bộ → Chu kỳ).
- Thử lại lỗi tạm thời theo lịch **5/15/30 phút** (tối đa 3 lần).
- **Webhook chưa hoạt động**; plugin dựa vào đồng bộ định kỳ.

## 20. Xử lý lỗi
- **Không thấy phương thức SPX:** kiểm tra cân nặng/kích thước sản phẩm và chính sách kiện hàng mặc định.
- **Phí vẫn cố định:** đúng như mặc định; phí động chỉ ở Sandbox/Local.
- **Tracking chưa cập nhật:** chờ chu kỳ đồng bộ hoặc bấm "Đồng bộ ngay".
- **Scheduler không chạy:** kiểm tra WP-Cron/Action Scheduler; site ít truy cập nên cân nhắc cron hệ thống.
- **Nhãn hết hạn:** bấm lấy nhãn lại.
- **Không hủy được vận đơn:** chức năng hủy mặc định tắt, hoặc vận đơn không còn đủ điều kiện hủy.
- **Production bị khóa:** cần HTTPS, credentials, và Account Verify.

## 21. Backup
Sao lưu database, thư mục plugin và cấu hình WooCommerce trước khi nâng cấp. Bản sao lưu phải được lưu ngoài thư mục WordPress đang chạy và được kiểm tra khả năng khôi phục.

## 22. Upgrade
Nâng cấp bằng ZIP phát hành chính thức trong trang Plugins. Plugin giữ credentials đã mã hóa, hồ sơ người gửi, dataset, tracking, timeline, mapping và cấu hình kiện hàng. Sau nâng cấp, kiểm tra lại tab Tổng quan, HPOS, scheduler và một checkout thử trước khi mở staging cho người dùng.

## 23. Rollback
Khôi phục đồng thời thư mục plugin và database từ cùng một mốc backup. Không xóa thủ công metadata SPX trên đơn hàng. Gỡ plugin vẫn giữ metadata đơn hàng để bảo toàn lịch sử, nhưng rollback an toàn cần bản sao database tương ứng.

## 24. Điều kiện Production
Trước khi chạy đơn thật ở giai đoạn sau RC: site phải dùng HTTPS, nhập credentials Production, chạy **Account Verify**, xác nhận scheduler hoạt động và hoàn tất Gate **6G-B**. **Dynamic Production chưa bật trong RC**; UAT fee là dữ liệu mock, còn Production fee là số VND đầy đủ với multiplier 1 nhưng vẫn bị khóa. Webhook chưa kích hoạt, Cancel thật mặc định tắt và chủ shop không cần sửa PHP constant trong luồng vận hành bình thường.
