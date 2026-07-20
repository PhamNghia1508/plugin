# SuperShip API — Tài Liệu Tham Chiếu Tích Hợp

> Nguồn: https://docs.developers.supership.vn (crawl toàn bộ ngày 2026-07-19 qua Firecrawl MCP).
> Mục đích: dùng làm tài liệu bám sát khi triển khai tích hợp SuperShip cho plugin (thay thế/song song SPX Express), tránh việc tự đoán field/endpoint.
> Không tự suy diễn field ngoài danh sách dưới đây — nếu thiếu, quay lại docs gốc hoặc hỏi SuperShip qua kênh hỗ trợ.

---

## 0. Tổng Quan

- **Base URL Production:** `https://api.mysupership.vn`
- **Base URL Sandbox:** hiện đang bảo trì — dùng tài khoản Test do SuperShip cấp để test trực tiếp trên Production. Liên hệ nhóm hỗ trợ Telegram để được cấp.
- **Print URL (in nhãn):** `https://khachhang.supership.vn/orders/awb`
- **Request format:** header `Accept: application/json`, `Content-Type: application/json`
- **Response format (thành công):**
  ```json
  { "status": "Success", "message": "<...>", "results": "<...>" }
  ```
- **Response format (thất bại):**
  ```json
  { "status": "Error", "message": "<...>", "errors": "<...>" }
  ```
- **Liên hệ hỗ trợ:** Telegram [SuperShip Support APIs](https://t.me/+Ul3BoTm5jmUzZjQ1) · Email `supertek@supership.vn`
- **Tên thương hiệu đúng chính tả:** `SuperShip` / `SUPERSHIP` (2 chữ S viết hoa) — không viết `Supership`, `suppership`, `shupership`, `supersip`.

### Danh sách nhóm API

| Nhóm         | Mô tả                                                               |
| ------------ | ------------------------------------------------------------------- |
| Areas        | Lấy danh sách Tỉnh/Thành, Quận/Huyện, Phường/Xã SuperShip hỗ trợ    |
| Auth (Users) | Đăng ký tài khoản, lấy Access Token                                 |
| Orders       | Tính cước, tạo đơn, xem đơn, danh sách trạng thái, in nhãn, hủy đơn |
| Warehouses   | Tạo/sửa/xem kho hàng (điểm lấy hàng)                                |
| Webhooks     | Đăng ký/xem webhook nhận cập nhật trạng thái đơn hàng               |

---

## 1. Xác Thực (Authentication)

Mọi request cần xác thực phải có header:

| Key           | Value                   |
| ------------- | ----------------------- |
| Accept        | `application/json`      |
| Authorization | `Bearer <Access-Token>` |

Có 2 cách lấy `Access-Token`:

### 1.1 Personal Access Tokens

Lấy trực tiếp tại mục **API** trên https://khachhang.supership.vn/apis (dashboard SuperShip).

- Dùng cho: cá nhân, đơn vị chỉ có 1 Shop cần kết nối.
- Đây là cách phần lớn khách hàng/đối tác dùng.

### 1.2 Password Grant Tokens

Dùng cho: **Sàn TMĐT, đơn vị phần mềm có nhiều khách hàng bên trong cần kết nối SuperShip** (trường hợp plugin đa khách hàng như WooCommerce plugin nếu bán cho nhiều shop).

- **Endpoint:** `POST /v1/partner/auth/login`
- **Request body:**

| Field         | Bắt buộc | Kiểu   | Mô tả                                               |
| ------------- | -------- | ------ | --------------------------------------------------- |
| client_id     | Có       | String | Client ID cấp cho Đối Tác                           |
| client_secret | Có       | String | Client Secret cấp cho Đối Tác                       |
| username      | Có       | String | Email của Shop/Công ty                              |
| password      | Có       | String | Mật khẩu                                            |
| partner       | Có       | String | Mã Bí Mật — dành cho Đối Tác TMĐT lớn với SuperShip |

```bash
curl --request POST \
     --url https://api.mysupership.vn/v1/partner/auth/login \
     --header 'Accept: application/json' \
     --header 'Content-Type: application/json' \
     --data '{
    "client_id": "AZN6QUo40w",
    "client_secret": "C4fFVeFPkISEDQ8acNo9oSHUd8yIGuvoLWJdX9zY",
    "username": "hmn.store@gmail.com",
    "password": "323423",
    "partner": "lPxLuxfiTotCyZ1ZnQjMepUL24HLd05ybNBhVGFN"
}'
```

- **Response `results`:**

| Field        | Kiểu    | Mô tả                                         |
| ------------ | ------- | --------------------------------------------- |
| token_type   | String  | `Bearer`                                      |
| expires_in   | Integer | Giây đến khi hết hạn. VD: `31536000` (~1 năm) |
| access_token | String  | Access Token                                  |

---

## 2. Người Dùng (Users)

### 2.1 Đăng Ký Tài Khoản

- **Endpoint:** `POST /v1/partner/auth/register`
- **Request body:**

| Field    | Bắt buộc | Kiểu   | Mô tả                        |
| -------- | -------- | ------ | ---------------------------- |
| project  | Có       | String | Tên Shop/Công Ty             |
| name     | Có       | String | Họ tên người đại diện        |
| phone    | Có       | String | SĐT Shop/Công ty             |
| email    | Có       | String | Email Shop/Công ty           |
| password | Có       | String | Mật khẩu                     |
| partner  | Có       | String | Mã Bí Mật (Đối Tác TMĐT lớn) |

- **Response `results`:** `code`, `project`, `name`, `phone`, `email`, `referral`, `token_type`, `expires_in`, `access_token`

---

## 3. Khu Vực (Areas)

Dùng để lấy mã (`code`) Tỉnh/Quận/Phường hợp lệ trước khi gọi Orders/Warehouses — **quan trọng**: API tạo đơn/kho dùng **tên (String)**, không phải code, nhưng phải khớp chính xác với tên SuperShip hỗ trợ (lấy qua các API dưới để validate/autocomplete).

### 3.1 Tỉnh/Thành Phố

- **Endpoint:** `GET /v1/partner/areas/province` — không cần tham số, không cần auth (theo ví dụ docs).
- **Response mỗi phần tử:** `code` (String, VD `79`), `name` (String, VD `Thành phố Hồ Chí Minh`)

### 3.2 Quận/Huyện

- **Endpoint:** `GET /v1/partner/areas/district`
- **Tham số:** `province` (Có, String) — mã Tỉnh/Thành Phố, VD `79`
- **Response mỗi phần tử:** `code`, `name`, `province` (tên tỉnh)

### 3.3 Phường/Xã

- **Endpoint:** `GET /v1/partner/areas/commune`
- **Tham số:** `district` (Có, String) — mã Quận/Huyện, VD `767`
- **Response mỗi phần tử:** `code`, `name`, `district` (tên quận), `province` (tên tỉnh)

> Ghi chú tích hợp: quy trình chuẩn là Province → District (theo province code) → Commune (theo district code), lấy về **tên** để điền vào các API Orders/Warehouses (các API đó nhận tên tỉnh/huyện/xã dạng String, không phải code).

---

## 4. Đơn Hàng (Orders)

### 4.1 Tính Cước Phí

- **Endpoint:** `GET /v1/partner/orders/price`
- **Headers:** `Accept: application/json`, `Authorization: Bearer <Access-Token>` (**yêu cầu xác thực** — cước phí tính riêng theo từng khách hàng)
- **Tham số (query):**

| Field             | Bắt buộc | Kiểu    | Mô tả                                            |
| ----------------- | -------- | ------- | ------------------------------------------------ |
| sender_province   | Có       | String  | Tên Tỉnh/TP người gửi                            |
| sender_district   | Có       | String  | Tên Quận/Huyện người gửi                         |
| receiver_province | Có       | String  | Tên Tỉnh/TP người nhận                           |
| receiver_district | Có       | String  | Tên Quận/Huyện người nhận                        |
| weight            | Có       | Integer | Khối lượng, đơn vị **gram**                      |
| value             | Không    | Integer | Trị giá đơn hàng (đồng) — dùng tính phí bảo hiểm |

- **Response `results`:** mảng object gồm `service` (String, VD `Tốc Hành`), `fee` (Integer), `insurance` (Integer), `pickup` (Object `{name}`), `delivery` (Object `{name}`)

### 4.2 Tạo Đơn Hàng

- **Endpoint:** `POST /v1/partner/orders/add`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`, `Content-Type: application/json`
- **Tham số:**

| Field           | Bắt buộc | Kiểu    | Mô tả                                                                                        |
| --------------- | -------- | ------- | -------------------------------------------------------------------------------------------- |
| pickup_code     | Không    | String  | Mã kho/điểm lấy hàng — nếu có giá trị thì ưu tiên dùng field này thay vì pickup\_\* chi tiết |
| pickup_phone    | Có\*     | String  | SĐT điểm lấy hàng (\*bắt buộc nếu pickup_code rỗng)                                          |
| pickup_address  | Có\*     | String  | Địa chỉ lấy hàng                                                                             |
| pickup_province | Có\*     | String  | Tỉnh/TP người gửi                                                                            |
| pickup_district | Có\*     | String  | Quận/Huyện người gửi                                                                         |
| pickup_commune  | Có\*     | String  | Phường/Xã người gửi (bắt buộc từ bản cập nhật)                                               |
| pickup_name     | Không    | String  | Tên kho/điểm lấy hàng                                                                        |
| pickup_contact  | Không    | String  | Tên người liên hệ lấy hàng                                                                   |
| name            | Có       | String  | Tên người nhận                                                                               |
| phone           | Có       | String  | SĐT người nhận                                                                               |
| address         | Có       | String  | Địa chỉ người nhận                                                                           |
| province        | Có       | String  | Tỉnh/TP người nhận                                                                           |
| district        | Có       | String  | Quận/Huyện người nhận                                                                        |
| commune         | Có       | String  | Phường/Xã người nhận (bắt buộc)                                                              |
| amount          | Có       | Integer | Số tiền thu hộ (COD), đơn vị đồng                                                            |
| value           | Có       | Integer | Trị giá đơn hàng, đơn vị đồng (bắt buộc từ v1.0.14, dùng tính bảo hiểm)                      |
| weight          | Có       | Integer | Khối lượng, đơn vị gram                                                                      |
| soc             | Không    | String  | Mã đơn riêng của người gửi (Sender's Order Code)                                             |
| note            | Không    | String  | Ghi chú                                                                                      |
| service         | Có       | String  | Mã gói dịch vụ. Giá trị hiện có: `1` = Tốc Hành                                              |
| config          | Có       | String  | Quyền xem/thử hàng: `1`=Cho xem không cho thử, `2`=Cho thử, `3`=Không cho xem                |
| payer           | Có       | String  | Người trả phí: `1`=Người gửi, `2`=Người nhận                                                 |
| product_type    | Có       | String  | `1`=Dạng chuỗi (dùng field `product`), `2`=Dạng mảng (dùng field `products`)                 |
| product         | Không\*  | String  | Bắt buộc khi product_type=1. VD: "Quần áo"                                                   |
| products        | Không\*  | Array   | Bắt buộc khi product_type=2. Mỗi phần tử: `sku`, `name`, `price`, `weight`, `quantity`       |
| barter          | Không    | String  | `1` = có yêu cầu đổi/lấy hàng về                                                             |
| partner         | Không    | String  | Mã Bí Mật (Đối Tác TMĐT lớn)                                                                 |

- **Response `results`:** `code` (Mã đơn SuperShip, dạng ghép `SGNS...NM.810000026`), `sorting` (Mã phân loại), `shortcode` (Mã đơn ngắn — **dùng cho barcode**, không dùng `code`), `soc`, `phone`, `amount`, `collection`, `value`, `weight`, `fee`, `insurance`, `status`, `status_name`

### 4.3 Trạng Thái Đơn Hàng (danh sách mã)

- **Endpoint:** `GET /v1/partner/orders/status` — không cần auth
- **Response `results`:** mảng `{key, value}`. Bảng đầy đủ mã trạng thái:

| key | value                 |
| --- | --------------------- |
| 0   | Huỷ                   |
| 1   | Chờ Duyệt             |
| 2   | Chờ Lấy Hàng          |
| 3   | Đang Lấy Hàng         |
| 4   | Đã Lấy Hàng           |
| 5   | Hoãn Lấy Hàng         |
| 6   | Không Lấy Được        |
| 7   | Đang Nhập Kho         |
| 8   | Đã Nhập Kho           |
| 9   | Đang Chuyển Kho Giao  |
| 10  | Đã Chuyển Kho Giao    |
| 11  | Đang Giao Hàng        |
| 12  | Đã Giao Hàng Toàn Bộ  |
| 13  | Đã Giao Hàng Một Phần |
| 14  | Hoãn Giao Hàng        |
| 15  | Không Giao Được       |
| 16  | Đã Đối Soát Giao Hàng |
| 17  | Đã Đối Soát Trả Hàng  |
| 18  | Đang Chuyển Kho Trả   |
| 19  | Đã Chuyển Kho Trả     |
| 20  | Đang Trả Hàng         |
| 21  | Đã Trả Hàng           |
| 22  | Hoãn Trả Hàng         |
| 23  | Đang Vận Chuyển       |
| 24  | Xác Nhận Hoàn         |
| 25  | Hàng Thất Lạc         |
| 26  | Không Trả Được        |
| 27  | Đã Bồi Hoàn           |

> Dùng bảng này để build status-mapper WooCommerce ↔ SuperShip (tương đương `class-spx-tracking-status-mapper.php` bên SPX).

### 4.4 Lấy Thông Tin Đơn Hàng

- **Endpoint:** `GET /v1/partner/orders/info`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`
- **Tham số:**

| Field | Bắt buộc | Kiểu   | Mô tả                                                               |
| ----- | -------- | ------ | ------------------------------------------------------------------- |
| code  | Có       | String | Mã đơn hàng                                                         |
| type  | Không    | String | Loại mã đơn. Mặc định `1`. `1`=Mã SuperShip, `2`=Mã người gửi (soc) |

- **Response `results`:** `code`, `soc`, `status`, `status_name`, `receiver` (Object: `name`,`phone`,`address`,`formatted_address`), `amount`, `value`, `weight`, `fee` (Object: `shipment`,`insurance`,`return`,`barter`,`address`), `payer`, `config`, `journeys` (Array timeline: `time`,`status`,`province`,`district`,`note`), `notes` (Array SuperShip ghi chú), `calllogs`, `last_sman`, `created_at`, `updated_at`

### 4.5 Tạo Token In Nhãn Giao Hàng

- **Endpoint:** `POST /v1/partner/orders/token`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`, `Content-Type: application/json`
- **Tham số:** `code` (Có, Array) — mảng Mã Đơn Hàng SuperShip
- **Response `results`:** `token` (String, dùng cho bước in nhãn)

### 4.6 In Đơn Hàng (Print URL)

- **URL:** `GET https://khachhang.supership.vn/orders/awb`
- **Tham số (query):**

| Field | Bắt buộc | Kiểu   | Mô tả                                                        |
| ----- | -------- | ------ | ------------------------------------------------------------ |
| token | Có       | String | Token lấy từ API 4.5                                         |
| size  | Có       | String | Khổ giấy: `A5`, `K46`, `T2`, `K50`, `K75`, `K80`, `S8`–`S14` |

- Response: trả về trang/ảnh nhãn in trực tiếp trên trình duyệt (không phải JSON).

### 4.7 Hủy Đơn Hàng

- **Endpoint:** `POST /v1/partner/orders/cancel`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`, `Content-Type: application/json`
- **Tham số:** `code` (Có, String) — Mã Đơn Hàng SuperShip
- **Response `results`:** `code`, `soc`, `address`, `status` (`0`), `status_name` (`Hủy`)

---

## 5. Kho Hàng (Warehouses)

### 5.1 Lấy Thông Tin Kho Hàng

- **Endpoint:** `GET /v1/partner/warehouses`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`
- **Response `results`:** mảng object: `code`, `name`, `address`, `formatted_address`, `status` (`1`=Đang hoạt động, `2`=Dừng hoạt động), `status_name`, `primary` (`1`=Kho mặc định, `2`=Kho thường), `primary_name`, `created_at`, `updated_at`

### 5.2 Tạo Kho Hàng

- **Endpoint:** `POST /v1/partner/warehouses/create`
- **Tham số:**

| Field    | Bắt buộc | Kiểu   | Mô tả                            |
| -------- | -------- | ------ | -------------------------------- |
| name     | Có       | String | Tên kho                          |
| phone    | Có       | String | SĐT người liên lạc               |
| contact  | Có       | String | Tên người liên lạc               |
| address  | Có       | String | Địa chỉ kho                      |
| province | Có       | String | Tỉnh/TP                          |
| district | Có       | String | Quận/Huyện                       |
| commune  | Có       | String | Phường/Xã                        |
| primary  | Có       | String | `1`=Kho mặc định, `2`=Kho thường |
| partner  | Không    | String | Mã Bí Mật (Đối Tác TMĐT lớn)     |

- **Response `results`:** giống 5.1 (single object)

### 5.3 Sửa Kho Hàng

- **Endpoint:** `POST /v1/partner/warehouses/update`
- **Lưu ý:** để đổi Địa chỉ/Phường/Quận/Tỉnh thì phải **tạo kho mới**, API này chỉ sửa được `name`, `phone`, `contact`.
- **Tham số:** `code` (Có), `name` (Có), `phone` (Có), `contact` (Có)
- **Response `results`:** `code`, `diff` (Object các field đã đổi), `updated_at`

---

## 6. Webhooks

### 6.1 Cập Nhật Mẫu (payload SuperShip gửi tới Callback URL của đối tác)

> **Auto-áp dụng** cho đơn hàng có điền `partner` (Mã Đối Tác) khi tạo đơn qua API — không cần gọi API tạo webhook thủ công cho từng user trong trường hợp này (Đối Tác TMĐT/Phần mềm/Lớn).

- Payload POST tới Callback URL đã đăng ký, `Content-Type: application/json`:

| Field       | Kiểu    | Mô tả                                                          |
| ----------- | ------- | -------------------------------------------------------------- |
| type        | String  | `update_status` (Trạng thái) hoặc `update_weight` (Khối lượng) |
| code        | String  | Mã đơn SuperShip                                               |
| shortcode   | String  | Mã đơn ngắn                                                    |
| soc         | String  | Mã đơn người gửi                                               |
| phone       | String  | SĐT người nhận                                                 |
| address     | String  | Địa chỉ người nhận                                             |
| amount      | Integer | Số tiền thu hộ                                                 |
| weight      | Integer | Khối lượng                                                     |
| fshipment   | Integer | Cước phí giao hàng                                             |
| status      | String  | Mã trạng thái (xem bảng 4.3)                                   |
| status_name | String  | Tên trạng thái                                                 |
| partial     | String  | `1` nếu giao một phần                                          |
| barter      | String  | `1` nếu có đổi/trả hàng                                        |
| reason_code | String  | Mã lý do (VD `304`)                                            |
| reason_text | String  | Chi tiết lý do                                                 |
| created_at  | String  | ISO 8601                                                       |
| updated_at  | String  | ISO 8601                                                       |
| pushed_at   | String  | ISO 8601                                                       |

- **Xác nhận nhận thành công:** trả về HTTP status **200** — SuperShip coi đó là push thành công. (Không có cơ chế ký/verify signature được nêu trong docs — cần hỏi SuperShip support nếu cần xác thực nguồn gửi webhook, tránh giả định.)

### 6.2 Lấy Thông Tin Webhook

- **Endpoint:** `GET /v1/partner/webhooks`
- **Headers:** `Accept`, `Authorization: Bearer <Access-Token>`
- **Response `results`:** `url`, `created_at`, `updated_at`

### 6.3 Tạo/Cập Nhật Webhook

- **Endpoint:** `POST /v1/partner/webhooks/create`
- Gọi lại API này với `url` mới để **thay đổi** webhook hiện tại (không có API riêng update). Chỉ đơn hàng tạo **sau** thời điểm đổi mới áp dụng webhook mới.
- **Tham số:** `url` (Có, String) — URL nhận callback
- **Response `results`:** `url`, `created_at`, `updated_at`

---

## 7. Changelog quan trọng (đã áp dụng vào docs này)

- **v1.0.14 (03/06/2026):** `value` là bắt buộc trong API Tạo Đơn Hàng.
- **v1.0.13 (27/04/2025):** thêm khổ giấy mới cho API in nhãn; đổi Print URL.
- **v1.0.12 (06/05/2023):** thêm trạng thái đơn hàng mới; thêm loại cập nhật `update_weight` cho Webhooks.
- **v1.0.7 (21/11/2020):** `pickup_commune` và `commune` trở thành bắt buộc trong API tạo đơn; API tính cước yêu cầu xác thực.
- **v1.0.8 (10/03/2021):** API lấy thông tin đơn hàng yêu cầu xác thực; hỗ trợ tra theo `soc`; xóa API lấy hành trình đơn hàng riêng (đã gộp vào `orders/info` → `journeys`).

---

## 8. Ánh xạ khái niệm sang kiến trúc plugin hiện tại (SPX → SuperShip)

Tham chiếu nhanh khi implement provider mới theo `SPX_Shipping_Provider_Interface`:

| Hàm interface             | Endpoint SuperShip tương ứng                                                                    |
| ------------------------- | ----------------------------------------------------------------------------------------------- |
| `calculate_rate`          | `GET /v1/partner/orders/price` (§4.1)                                                           |
| `create_shipment`         | `POST /v1/partner/orders/add` (§4.2)                                                            |
| `cancel_shipment`         | `POST /v1/partner/orders/cancel` (§4.7)                                                         |
| `get_tracking`            | `GET /v1/partner/orders/info` (§4.4, dùng `journeys`)                                           |
| `get_label`               | `POST /v1/partner/orders/token` (§4.5) + `GET https://khachhang.supership.vn/orders/awb` (§4.6) |
| Webhook nhận trạng thái   | POST callback theo §6.1 (mã trạng thái §4.3)                                                    |
| Địa chỉ hành chính        | `GET /v1/partner/areas/{province,district,commune}` (§3)                                        |
| Quản lý kho/điểm lấy hàng | `GET/POST /v1/partner/warehouses*` (§5)                                                         |
| Auth                      | `POST /v1/partner/auth/login` (Password Grant, §1.2) hoặc Personal Access Token thủ công (§1.1) |

**Khác biệt cấu trúc quan trọng cần lưu ý khi code mapper:**

- SuperShip dùng **tên hành chính dạng String** (không phải code) cho `province`/`district`/`commune` trong Orders & Warehouses — khác SPX (thường dùng ID số). Cần bước resolve tên chuẩn qua Areas API trước khi gửi.
- SuperShip **không có sẵn API riêng cho tracking route** kể từ v1.0.8 — lịch trình nằm trong `journeys` của `orders/info`.
- Auth theo kiểu OAuth-ish Bearer token có `expires_in` — cần cơ chế refresh/re-login định kỳ (khác SPX nếu SPX dùng app_id/app_secret ký từng request không hết hạn).
- Trường `partner` (Mã Bí Mật) xuất hiện ở Register, Password Grant Login, Tạo Đơn, Tạo Kho — đây là điều kiện để Webhook tự động áp dụng; cần xác nhận với SuperShip xem field này có bắt buộc cho plugin đa khách hàng (multi-tenant) hay không trước khi thiết kế Admin Settings.
