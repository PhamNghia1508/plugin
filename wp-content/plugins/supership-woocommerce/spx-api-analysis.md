# SPX Open API — Integration Analysis

Source of truth: **SPX Express Open API Platform**, official docs at
`https://spx.vn/en/integration/en/guide/...` (Guide + API sections), region VN.
Captured 2026-07-13. This document records only what the official documentation
states; nothing here is inferred from the `SPXMOCK-...` mock provider or reverse
engineered. `SPXMOCK-...` codes are local placeholders, **not** SPX waybills.

---

## 1. Environments (base URL)

API host is `{{host}}`, differentiated by region CID and live/non-live:

| Environment | Base URL (VN) |
|---|---|
| Non-live (sandbox) | `https://test-stable.spx.vn/` |
| Live (production) | `https://spx.vn/` |

Other CIDs follow the same pattern (`test-stable.spx.co.th`, `.spx.sg`, `.spx.co.id`).
All endpoint paths below are appended to `{{host}}`.

---

## 2. Authentication & signature (VERIFIED)

### Credentials
Two credential pairs, both issued by SPX (not self-service):

- **app-id / app-secret** — per integrating app, obtained by contacting SPX staff.
  Used to sign every request. Example doc values: `app-id=100000`,
  `app-secret=H25HY53GO4BA2GQ`.
- **user-id / user-secret** — per shipping account. **Self-service**: generated via
  `3.1 Create Account` (which needs only app-level credentials, see §4), or provided by SPX.
  Not gated behind SPX approval per the endpoint docs. Sent in the request **body** of every
  order/fee call.

### Required request headers (every API call)
All endpoints: `POST`, `Content-Type: application/json`, UTF-8, JSON body.

| Header | Type | Description |
|---|---|---|
| `app-id` | integer | Issued by SPX, unique per app |
| `check-sign` | string | HMAC-SHA256 signature (see below) |
| `timestamp` | integer | Unix time in **seconds** (not ms) |
| `random-num` | integer | signed 64-bit random integer |

### check-sign algorithm (HMAC-SHA256)
```
message   = "<app-id>_<timestamp>_<random-num>_<payload>"   // format "%d_%d_%d_%s"
check-sign = lowercase_hex( HMAC_SHA256(key = app-secret, msg = message) )
```
- `payload` is the **exact JSON body string** that is sent as the request body —
  byte-for-byte. Any difference in whitespace / newlines changes the signature.
  Sign the encoded string once, then send that same string as the body; do not
  re-encode.
- `timestamp` unit is seconds. Requests too far from server time are rejected
  (`1009 RequestExpiredCode`).
- Windows vs Unix newline (`\r\n` vs `\n`) inside a payload changes bytes and thus
  the signature; live env accepts only the canonical form.

### Verification performed (Phase step 4 — PASS)
Official test vector reproduced in **PHP 7.4** (plugin's minimum) with
`json_encode(..., JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)` +
`hash_hmac('sha256', $msg, $secret)`:

| Field | Value |
|---|---|
| app-id | `100000` |
| app-secret | `H25HY53GO4BA2GQ` |
| timestamp | `1677918414` |
| random-num | `926611981` |
| payload | `{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}` |
| **expected check-sign** | `97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d` |
| **computed (PHP 7.4)** | `97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d` ✅ |

PHP `json_encode` output is byte-identical to the documented payload, so the
provider can safely sign its own encoded body. Signature scheme is **confirmed
implementable in the plugin's runtime.**

---

## 3. Endpoint catalogue

### Order API
| # | Purpose | Path (`{{host}}` + ) | Batch limit |
|---|---|---|---|
| 1.1 | Get pickup timeslot | `/open/api/v1/order/get_pickup_time` | — |
| 1.2 | Create Order | `/open/api/v1/order/batch_create_order` | 100 |
| 1.3 | Track Order | `/open/api/v1/order/batch_search_order` | 100 |
| 1.4 | Cancel Order | `/open/api/v1/order/batch_cancel_order` | 100 |
| 1.5 | Get AWB (label) | `/open/api/v1/order/batch_get_shipping_label` | 30 (doc note); page also says 100 |
| 1.6–1.12 | Create Order V2 / Get Create Result V2 / Get AWB V2 / Update Order / List Voucher / Update Order V2 / Confirm Order | (async V2 flow, not required for MVP) | |

### Shipping Fee API
| # | Purpose | Path |
|---|---|---|
| 2.1 | Check Shipping Fee (pre-order estimate) | `/open/api/v1/order/batch_check_order` |
| 2.2 | Get Order Fee (actual, post-create) | (Shipping Fee API) |
| 2.3 | Estimate Address Adjustment Fee | (Shipping Fee API) |

### Account API
| # | Purpose | Path |
|---|---|---|
| 3.1 | Create Account | `/open/api/v1/account/create` |
| 3.2 | Check Account Credentials | `/open/api/v1/account/verify` |

### Web Hook (inbound to us; requires public endpoint + signature verify)
4.1 Tracking, 4.2 Order Create, 4.3 Order Create Feedback, 4.4 Reverse Order
Create Feedback, 4.5 Ticket, 4.6 Shipping Fee, 4.7 Evidence Proofs.

### Address API
5.1 Get Address file download link (province/district/ward code lists).

---

## 4. Core request/response schemas (VN)

### 3.1 Create Account — `/open/api/v1/account/create`  (self-service)
Headers: app-level only (`app-id`, `check-sign`, `timestamp`, `random-num`) — **no
pre-existing user credential required.**
Body:
| Field | Type | Mandatory | Notes |
|---|---|---|---|
| `phone` | string | **Y** | ≤32; account is keyed by this |
| `email` | string | N | ≤64 |
> **No arbitrary merchant identifier field exists** in the schema — only `phone`/`email`.
Response: `{ "ret_code":0, "data": { "user_id": <int>, "user_secret": "<uuid>" } }`
- **Creates a new** `user_id`/`user_secret` pair. Side effect: a persistent account.
- **Duplicate prevention** is by phone/email uniqueness: re-using an existing phone/email
  returns `12003 PhoneExistErrorCode` / `12002 EmailExistErrorCode` (it does **not** return
  the existing credentials). So a create call is not idempotent for retrieval — store the
  returned pair; you cannot re-fetch it by re-calling create.
- Conclusion (resolves the earlier contradiction): **user-id/user-secret ARE self-service**
  via this endpoint using the current app-level credentials; SPX pre-approval is **not**
  required per the docs. The previous "must request from SPX" blocker was incorrect.

### 3.2 Check Account Credentials — `/open/api/v1/account/verify`
Body: `{ "user_id": <int>, "user_secret": "<str>" }`
Response: `{ "ret_code":0, "message":"success", "data": { "match_result": true|false } }`
> Read-only, no side effects. **This is the safe connectivity/auth probe endpoint.**

### 2.1 Check Shipping Fee — `/open/api/v1/order/batch_check_order`
Body: `user_id`, `user_secret`, `orders[]` where each order has:
- `base_info.service_type` (1 standard / 2 instant) — **Y**
- `sender_info`: `sender_state`(Y), `sender_city`(Y), `sender_district`(**Y for VN**),
  `sender_post_code`(N for VN), lat/long(N), `sender_detail_address`(N)
- `deliver_info`: `deliver_state`(Y), `deliver_city`(Y), `deliver_district`(**Y for VN**),
  `deliver_post_code`(N for VN), lat/long(N), `deliver_detail_address`(N)
- `fulfillment_info`: `cod_collection`(N), `cod_amount`(cond), `high_value_processing_collection`
  (**VN required as 1**), `collect_type`(N), `voucher_code`(N), `allow_partial_delivery`(N)
- `parcel_info`: `parcel_weight` kg (**Y**, VN 0<w≤15), `parcel_length/width/height`(N),
  `parcel_item_name`(N), `parcel_item_quantity`(N), `express_insured_value`(cond)

Response `data.orders[]`: `estimated_shipping_fee`, `basic_shipping_fee`,
`cod_service_fee`, `high_value_processing_fee`, `vat_fee`, `voucher_shipping_fee`,
`edt_min`, `edt_max`, plus `fail_list[]` (`ret_code`,`message`,`debug_msg`,`order_id`).

### 1.2 Create Order — `/open/api/v1/order/batch_create_order`
Body: `user_id`, `user_secret`, `orders[]` (max 100). Each order:
- `order_id` (N, ≤32, your system id — used for idempotency/dedupe)
- `base_info.service_type` (Y)
- `sender_info`: `sender_state`(Y), `sender_city`(Y), `sender_district`(**Y VN**),
  `sender_post_code`(N VN), `sender_name`(Y), `sender_phone`(Y), `sender_detail_address`(Y)
- `fulfillment_info`: `payment_role`(Y, 1 sender/2 receiver), `cod_collection`(Y 0/1),
  `cod_amount`(cond; **VN integer, ≤20,000,000 VND**), `high_value_processing_collection`
  (Y; **VN=1 when express_insured_value ≥ 3,000,000**), `collect_type`(Y 1 pickup/2 dropoff),
  `pickup_time`/`pickup_time_range_id`/`pickup_time_range` (cond, only when collect_type=1),
  `allow_mutual_check`/`allow_try_on`/`allow_partial_delivery`(N VN), `voucher_code`(N)
- `deliver_info`: `deliver_state`(Y svc=1), `deliver_city`(Y svc=1), `deliver_district`(**Y VN**),
  `deliver_post_code`(N VN), `deliver_name`(Y), `deliver_phone`(Y), `deliver_detail_address`(Y),
  `deliver_instruction`(N)
- `parcel_info`: `parcel_weight` kg (**Y**, VN 0<w≤17), dims(N, VN each ≤60cm, L+W+H≤180),
  `parcel_item_name`(Y), `parcel_item_quantity`(N), `express_insured_value`(N),
  `item_list[]` (N: item_name/item_weight g/item_price/item_quantity)
- `vas_info`: `vas_types`(N), `collect_fee_amount`(N)

Response `data.orders[]`: `order_id`, `tracking_no` (**SPX waybill, e.g. `SPXVN...`**),
`tracking_link`, `order_id_link`, sort codes, and full fee breakdown.
`data.fail_list[]`: `order_id`, `message`, `debug_msg`, `ret_code`.

### 1.3 Track Order — `/open/api/v1/order/batch_search_order`
Body: `user_id`, `user_secret`, one of `tracking_no_list[]` / `order_id_list[]` / `batch_no`.
Response `data.orders[]`: `tracking_no`, `status`, `status_code`, base/sender/deliver/parcel
info, `routes[]` (`status`,`status_code`,`message`,`timestamp`), `edd_info`.
`fail_list[]` with `ret_code` (e.g. `13251` order/tracking not found).
> Eventual consistency: querying within ~1s of create may return empty/`13251`.
> Retry with 1–3s backoff. Do not use as strong-consistency check right after create.

### 1.4 Cancel Order — `/open/api/v1/order/batch_cancel_order`
Body: `user_id`, `user_secret`, `tracking_no_list[]` (Y).
Response `data.tracking_no_list[]` (cancelled) + `data.fail_list[]`.
> Only orders in **Pending Pickup / drop-off** status can be cancelled
> (`13002 OrderUnableCancelCode`).

### 1.5 Get AWB — `/open/api/v1/order/batch_get_shipping_label`
Body: `user_id`, `user_secret`, `tracking_no_list[]` (Y).
Response `data.awb_link` (label URL) + `data.fail_list[]`.
> Cannot label a cancelled order. Link valid **30 minutes**, openable **≤10 times**.

---

## Shipping Label

- **Endpoint:** `POST /open/api/v1/order/batch_get_shipping_label` on the sandbox host
  `https://test-stable.spx.vn` for Gate 6F-A.
- **Request:** authenticated body fields `user_id`, `user_secret`, and required
  `tracking_no_list[]`. The plugin accepts only deduplicated real `SPXVN...` tracking
  numbers and rejects `SPXMOCK` values.
- **Response:** request success uses `data.awb_link`; per-item failures use
  `data.fail_list[]` with `tracking_no`, `ret_code`, and diagnostic fields. Diagnostic
  fields and raw responses must not be persisted or displayed.
- **Lifetime/usage:** the documented link lifetime is 30 minutes and the documented
  open/download limit is at most 10. The plugin caches the verified URL for 25 minutes,
  never probes it, and never stores it in order meta.
- **Format:** the documentation does not state a guaranteed file format. The plugin
  records `pdf` only when the returned URL path explicitly ends in `.pdf`; otherwise it
  records `unknown` and does not infer a format.
- **Maximum batch size:** the captured official material conflicts: the endpoint note
  says 30 while the page also says 100. Fail-safe implementation uses the lower limit,
  30.
- **Safety:** label URLs are untrusted until HTTPS and exact-host allowlist validation;
  IP literals, localhost, credentials in URLs, non-443 ports, and non-HTTPS schemes are
  rejected. Redirects and automatic downloads are disabled.
- **Relevant errors:** `13201` (get label failed), top-level auth/parameter errors, and
  `99xxx` system errors. Partial success is preserved.
- **Unknowns/limitations:** guaranteed MIME type, redirect behavior of the generated
  link, whether the usage limit counts failed opens, and sandbox-specific label
  availability are not documented. The implementation therefore does not fetch or
  follow the returned link.

## Cancel Shipment

- **Endpoint:** `POST /open/api/v1/order/batch_cancel_order`.
- **Request:** authenticated body fields `user_id`, `user_secret`, and required
  `tracking_no_list[]`; documented maximum batch size 100. Gate 6F-A does not make a
  real request to this endpoint.
- **Eligibility:** the official material allows cancellation only while an order is
  **Pending Pickup / drop-off**. The plugin must first obtain the canonical status with
  `batch_search_order` and permits only status code `1001`; local cached status alone is
  never sufficient.
- **Response:** successful tracking numbers appear in `data.tracking_no_list[]`; partial
  failures appear in `data.fail_list[]` with per-item `tracking_no` and `ret_code`.
- **Relevant errors:** `13001` (cancel failed), `13002` (order cannot be cancelled), plus
  auth/parameter and `99xxx` system errors. A business failure is sanitized and is not
  blindly retried.
- **Idempotency:** the documentation does not provide a cancel idempotency key or a
  conclusive replay guarantee. The plugin uses a per-order atomic lock and durable
  `none/processing/succeeded/unknown/failed` state; `succeeded` and `unknown` block a
  second cancel call.
- **Timeout/unknown result:** timeout, reset, HTTP 5xx, malformed response, or interrupted
  execution after dispatch is treated as `unknown`. The plugin never retries cancel
  automatically; it reconciles only through signed `batch_search_order`. Canonical
  status `7001` changes local cancel state to `succeeded` and updates the SPX timeline.
- **WooCommerce boundary:** cancelling an SPX shipment never changes the WooCommerce
  order status, totals, shipping total, refunds, emails, tracking number, or earlier
  timeline events.
- **Unknowns/limitations:** exact server-side idempotency, the point at which a request is
  committed, retry-after semantics, and sandbox cancellation behavior remain
  undocumented. Real cancellation is locked until separately approved Gate 6F-B.

## 4b. Address Dataset (5.1) — investigated 2026-07-13

### Endpoint
`POST {{host}}/open/api/address/get_address_download_url` — app-level headers only,
**empty body**. (Sent `[]`; sandbox returned `ret_code:0`, so an empty array body is
accepted; docs show `{}`.) Response: `data.address_download_url` → an **.xlsx** file URL.

### Observed download link
- Host: **`uat.spx.vn`** (scheme https) — **different host** from the API
  (`test-stable.spx.vn`); path `/downloads/resource/location_list/<hash>.xlsx`.
- Link is generated fresh per call (hash filename); treat as short-lived.

### File format & VN schema (from official "Get available addresses" doc)
Excel (.xlsx), name-only (no codes). VN columns and their create-order field mapping:

| Excel column (VN) | create-order field | Mandatory (VN) |
|---|---|---|
| **State** (e.g. `Nam Định`) | `sender_state` / `deliver_state` | Y |
| **District** (e.g. `Huyện Trực Ninh`) | `sender_city` / `deliver_city` | Y |
| **Ward** (e.g. `Xã Trực Tuấn`) | `sender_district` / `deliver_district` | Y |
| Longitude | `sender_longitude` / `deliver_longitude` | N |
| Latitude | `sender_latitude` / `deliver_latitude` | N |

⚠️ VN "District" maps to the API **`*_city`** field and VN "Ward" maps to the API
**`*_district`** field. There are **no codes** in the dataset — matching is by NAME.

### BLOCKER (environment): dataset download is not reachable here
Downloading the returned `uat.spx.vn` link returns **HTTP 403 (nginx / AWS ALB,
`Status generated by alb`)** — with and without a browser User-Agent. This is
**edge/IP allow-listing** on SPX's UAT download host; this environment's egress IP is
not on SPX's partner allow-list, so the real .xlsx cannot be fetched here. The API step
(getting the URL) works; only the file host blocks the GET. To proceed, the real file
must be provided from an SPX-allow-listed environment, or a sanitized fixture used for
parser development.

### Storage strategy (proposed, pending real row count)
VN is a 3-level name hierarchy (~63 provinces × districts × wards). Recommended:
normalized JSON at `wp-content/uploads/spx-express/spx-vn-addresses.json` +
`.meta.json`, with a metadata WP option (`autoload=false`). A custom table
(`{$wpdb->prefix}spx_locations`) only if lookup performance needs it. Final choice
after inspecting the real file's size/row count.

## 4c. Address Dataset — Phase 6B (imported from real file)

The `uat.spx.vn` download remains IP-blocked here, so the dataset is imported from a
**local official file** the merchant supplies (admin upload). The file used is the SPX
**service-area** export `service_area_YYYYMMDD.xlsx` (distinct from the 3-column
"address library" in §4b, but name-based like it).

**Real file facts (inspected, 10,000 rows):** Sheet1, 29 columns A..AC, inline `t="str"`
cells (no sharedStrings), `dimension` attribute unreliable. Only columns A–H are used;
routing columns I–AC are entirely empty. Required fields have 0 blanks; `Sort Codet ID`
is a unique positive integer (no duplicates); `Status` is uniformly `Available`.

| Column | Meaning | Used as |
|---|---|---|
| `Sort Codet ID` (A) | ward/location id (alias `Sort Code ID` supported) | stable ward code |
| `Sort Code` (B) | **ward NAME** (Phường/Xã/Thị Trấn) — not a code | ward display name |
| `District` (C) | district name | district |
| `Province` (D) | province name | province |
| `Delivery`/`Pick Up`/`COD` (E–G) | Y/N capability | capability flags |
| `Status` (H) | Available/… | availability |

Province and District have **no official code**, so deterministic internal keys are
derived (`p_<md5>` / `d_<md5>` over normalized names) — never presented as SPX codes; the
official display names are preserved. Storage: normalized JSON at
`wp-content/uploads/spx-express/spx-vn-addresses.json` (+ meta option, autoload=false),
written atomically (temp + rename), all-or-nothing (rollback keeps the old dataset).

**VN create-order mapping** (for the future rate/create phase): Province → `*_state`,
District → `*_city`, Ward → `*_district`. A recipient is shipment-eligible only when
`Status=Available` and `Delivery=Y`; sender pickup needs `Pick Up=Y` (MVP uses
collect_type=2 drop-off, so pickup capability is stored but not required); COD needs `COD=Y`.

## 4d. Fee currency/unit — Gate A finding (Phase 6D)

**Conclusion: CASE C — SPX does not document the shipping-fee currency/unit.** Verified
across `2.1 Check Shipping Fee`, `2.2 Get Order Fee` (`batch_get_asf`), `1.2 Create Order`,
and the `Parameter enumeration mapping` appendix: every fee field
(`estimated_shipping_fee`, `basic_shipping_fee`, `cod_service_fee`,
`high_value_processing_fee`, `vat_fee`, `voucher_shipping_fee`, `actual_shipping_fee`) is
type `number` with **no unit annotation**, whereas weight (`Unit: kg`), dimensions
(`Unit: cm`), and `cod_amount` / `express_insured_value` (VN integer VND, e.g. 15000,
limit 20,000,000 VND) **are** unit-annotated. The sandbox returns tiny values
(`estimated_shipping_fee=21`, `vat_fee=1.56`); `1.56` decimal is impossible for raw VND,
so the unit is genuinely unknown — do not infer from the number.

**Safe handling applied:** rate quotes keep fee values RAW (unscaled); `currency` is `''`
and `fee_unit` is `unconfirmed`. The admin panel shows raw numbers with the banner
"SPX raw fee — đơn vị chưa được SPX xác nhận" and **no `wc_price()`/₫**. Fees are never used
for the order total, customer shipping charge, free-shipping, or profit. This does not
block sandbox Create Order (the create request uses `cod_amount`, which is documented VND).

**BLOCKER to raise with SPX:** confirm the currency and unit (raw VND vs thousands vs other)
of the shipping-fee fields, and whether create-response fees use the same unit as the rate
response. Suggested message: *"Đơn vị và tiền tệ của estimated_shipping_fee / basic_shipping_fee
/ vat_fee ... trong batch_check_order và batch_create_order là gì (VND nguyên, nghìn VND,
hay khác)? Create-order fee có cùng đơn vị với rate không?"*

## 5. Order status codes (for Track → local status mapping)

| status_code | status | Bucket |
|---|---|---|
| 1001 | Pending Pickup | created / awaiting pickup |
| 2001 | In Transit | in transit |
| 2006 | Delivering | out for delivery |
| 3001 | On Hold | exception |
| 4001 | Delivered | completed |
| 5001 | Pickup Failed | exception |
| 5002 | Damaged | exception |
| 5003 | Lost | exception |
| 6001 | Returning | returning |
| 6002 | Return Failed | exception |
| 6003 | Returned | returned |
| 7001 | Cancelled | cancelled |

---

## 6. Error codes (selection relevant to integration)

`ret_code = 0` = success. Non-zero at top level = whole-request failure;
per-item failures appear in `fail_list[].ret_code`.

- **Auth / request validity [1000–1999]**: `1001` app status, `1002` repeat request
  (idempotency hit), `1003` bad timestamp, `1004` bad random-num, `1005` invalid app-id,
  `1006` missing check-sign, `1007` check-sign verification failed, `1008` empty body,
  `1009` request expired (timestamp skew).
- **Param check [11000+]**: `11001` param error (e.g. weight null).
- **Account [12000+]**: `12051` verify account failed, `12052` account status abnormal.
- **Order [13000+]**: `13001` cancel failed, `13002` order not cancellable,
  `13051` check order failed, `13101` create order failed, `13103` order_id duplicate
  (**idempotency signal**), `13201` get label failed, `13251` search/track not found,
  `13301` get pickup time failed.
- **Business [10001+]**: weight/COD limits, serviceable-area, address/postcode validity,
  distance limits, timeslot validity, etc. (full table captured in docs).
- **System [99000+]**: `99001` server error, `99004` service busy — retryable.

---

## 7. Mapping SPX ↔ plugin provider interface

`SPX_Shipping_Provider_Interface` has 5 methods. Intended SPX binding:

| Interface method | SPX endpoint | Notes |
|---|---|---|
| `calculate_rate($shipment)` | 2.1 `batch_check_order` | single-order batch; return `estimated_shipping_fee` |
| `create_shipment($shipment)` | 1.2 `batch_create_order` | return `tracking_no`; pass `order_id` for idempotency |
| `cancel_shipment($tracking)` | 1.4 `batch_cancel_order` | only Pending Pickup |
| `get_tracking($tracking)` | 1.3 `batch_search_order` | map `status_code` → local status (§5) |
| `get_label($tracking)` | 1.5 `batch_get_shipping_label` | return `awb_link`; short-lived |

Plus needed for account setup: 3.2 `account/verify` (probe/health-check),
optionally 1.1 `get_pickup_time` when `collect_type = pickup`.

---

## 8. Gap analysis: current plugin vs SPX requirements

Current internal payload (`SPX_Order_Mapper::build`) produces:
`sender/recipient {name, phone, address, province, district, ward}`,
`items[]`, `weight_grams`, `cod_amount` (float), `declared_value`, `note`.

| Area | Current state | SPX requirement | Gap / action |
|---|---|---|---|
| **Credentials storage** | Settings has `environment`; no app-id/app-secret/user-id/user-secret fields verified present | 4 credentials, all in headers/body | **Add 4 credential settings** (password-type), plus base-URL/env switch already present |
| **Signature** | none | HMAC-SHA256 per §2 | **Implement in `SPX_Api_Provider`** (verified feasible) + transport helper |
| **Address model** | province / district / **ward (always empty)** | VN needs `state`=Province, `city`=District (Quận/Huyện), `district`=**Ward (mandatory)** | **Ward is mandatory for VN but never populated.** Need WooCommerce→SPX 3-level mapping; ward is a real blocker |
| **Address code lists** | none | 5.1 Address file / valid state/city/district **name** strings | **Fetch & validate** against SPX names; WC state codes ≠ SPX province names |
| **Weight unit** | grams (int) | `parcel_weight` in **kg**, VN ≤17 (create) / ≤15 (fee) | Convert g→kg; enforce max |
| **COD amount** | float, = order total | **VN integer**, ≤20,000,000 VND | Cast to int; cap; only when `cod_collection=1` |
| **Parcel dims** | not captured | optional, VN each ≤60cm, L+W+H≤180 | Optional; add if available |
| **service_type** | not set | required (1 standard) | Default 1 |
| **collect_type / pickup_time** | not modelled | required; pickup needs timeslot via 1.1 | Decide default (drop-off=2 simplest) |
| **payment_role** | not set | required (1 sender pay) | Default 1 |
| **high_value_processing_collection** | not set | **VN required=1** when insured ≥3,000,000 | Add logic |
| **sender phone** | empty string | required | **Must be configured** (store setting) |
| **item price/weight** | unit_price + weight_grams present | item_price, item_weight(g), item_quantity | Maps cleanly |
| **Rate at checkout** | fixed/mock cost | 2.1 live estimate | Optional live-rate mode; keep fixed as fallback |
| **Tracking status** | local "created" only | 12 status codes + routes | **Implement §5 mapping** + optional 4.1 webhook |
| **Label** | mock: none | 1.5 awb_link (30-min, ≤10 opens) | Fetch on demand, don't cache long |
| **Idempotency** | local dedupe (don't overwrite) | pass `order_id`; `13103`/`1002` signal dupes | Send WC order id as `order_id`; handle `13103` as already-created |
| **Retry/consistency** | n/a | track eventual-consistency, `99xxx` retryable, timestamp skew | Add backoff + clock requirement |
| **Webhooks** | none | 4.1–4.7 inbound, signed | Out of MVP scope; design later |

### Blockers before implementing the API Provider
1. **Credentials.** `app-id`/`app-secret` are SPX-issued; for the **test environment SPX
   publishes public ones** (`app-id=1000490`). `user-id`/`user-secret` are **self-service**
   via `3.1 Create Account` (§4) using the app-level credentials — they do **not** require
   SPX approval. (Corrected: an earlier draft wrongly listed these as SPX-gated.) A sandbox
   `user-id`/`user-secret` still needs to exist (created via the API or supplied) before
   `account/verify` can return `match_result:true`.
2. **VN address mapping** (province/district/**ward**) — ward is mandatory for VN and is
   currently always empty; needs the 5.1 address code list and a WC→SPX resolver.
3. **Store sender profile** — sender name/phone/full address/province/district/ward must be
   configured; sender phone is currently empty.

---

## 9. Phase status (mandatory order)

1. ✅ Read official SPX docs — done (Guide + API + appendices captured above).
2. ✅ `spx-api-analysis.md` — this file.
3. ✅ Gap analysis — §8.
4. ✅ Verify authentication/signature — §2, PASS in PHP 7.4 against official vector.
5. ✅ **Safe connectivity probe — ACCOUNT VERIFY PASS.** A sandbox account was created via
   `3.1 account/create` (public test `app-id=1000490`) and its `user-id`/`user-secret`
   stored in ignored `tests/runtime/.env`. `account/verify` returned HTTP 200, `ret_code=0`,
   `data.match_result=true`. Reachability + app-level HMAC auth + account auth all confirmed
   against the real sandbox. (Helpers: `tests/runtime/spx-create-account.php`,
   `tests/runtime/spx-connectivity-probe.php`.)
5b. ✅ **Phase 6B — Sender profile + VN address dataset/resolver.** Import the real SPX
   service-area .xlsx (10,000 wards / 63 provinces / 670 districts) via a streaming
   dependency-free reader; normalized dataset stored atomically. New classes:
   `SPX_XLSX_Reader`, `SPX_Address_Normalizer`, `SPX_Address_Import_Service`,
   `SPX_Address_Repository`, `SPX_WC_Address_Resolver`, `SPX_Sender_Profile`,
   `SPX_Order_Address`, `SPX_Shipment_Readiness`, `SPX_Admin_Address`; `SPX_Order_Mapper`
   gained `enrich_for_spx()`. Admin: SPX Express settings page (dataset import + sender
   profile + province/district/ward dependent dropdowns), per-order HPOS "Địa chỉ SPX"
   override + readiness panel. Order API still NOT implemented; Checkout unchanged (ward
   captured only via admin override). Verified: 42 address assertions, real-file import +
   resolve + override + readiness PASS, admin UI smoke (dropdowns via AJAX, no console/PHP
   errors).
5c. ✅ **Phase 6C — `calculate_rate()` via `batch_check_order`.** Admin-only manual
   "Kiểm tra phí SPX" order action prices one order against the sandbox and stores a safe
   quote snapshot (`_spx_rate_*` meta, WC_Order CRUD). New classes: `SPX_Rate_Request_Mapper`
   (internal → official schema; VN Province→`*_state`, District→`*_city`, Ward→`*_district`;
   weight g→kg ≤15; COD integer; high-value=1 when insured ≥3,000,000), `SPX_Rate_Service`
   (local service-area validation → map → signed call → parse orders[0]/fail_list),
   `SPX_Http_Client_Interface` (test seam), `SPX_Admin_Rate`. `SPX_Api_Provider::calculate_rate`
   now delegates to `SPX_Rate_Service`; the other four methods remain controlled errors.
   **Sandbox result: SPX RATE PASS** — HTTP 200, ret_code 0, `estimated_shipping_fee=21`
   (basic 21, vat 1.56, edt_max 3), no fail_list, over an intra-HCM route.
   **Finding:** rural province-to-province pairs return `ret_code 13051`
   ("LocationServiceError, route code is not matched by location") — a sandbox **routing
   config gap**, not a mapping error (SPX echoes all fields correctly). Checkout unchanged
   (fixed rate); the SPX quote never affects the order total or the customer shipping charge.
6. ◑ Implement API Provider — **Phase 6A done: tested API foundation only.** New
   production classes under `includes/api/`: `SPX_API_Config`, `SPX_Request_Signer`
   (+`SPX_Signer_Exception`), `SPX_HTTP_Client`, `SPX_API_Response`,
   `SPX_API_Error_Mapper`, `SPX_Account_Service`. `SPX_Api_Provider` now accepts an
   optional `SPX_Account_Service` but its rate/shipment methods remain **unimplemented**
   (controlled error). Account verify **through the new production code** =
   **ACCOUNT VERIFY PASS** (HTTP 200, ret_code 0, match_result true). Order-API methods
   still pending later phases.
7. ✅ Re-run checks (Phase 6A): PHP 7.4 lint (all), `test-core.php`,
   `test-spx-api-foundation.php` (45 assertions), 28 integration assertions, HPOS check,
   production-code account verify, security scan — all PASS. Browser E2E not required
   (no Checkout/UI change).

Until step 6, the plugin stays in **Mock mode**; `SPX_Api_Provider` keeps returning a
controlled unavailable error and makes no HTTP request.

## 10. Phase 6E webhook verification status (2026-07-13)

**WEBHOOK BLOCKED BY DOCUMENTATION.** The publicly reachable official SPX Open API
material identifies Web Hook 4.1 Tracking, but does not provide a complete, verified
inbound authentication contract. The following are unknown and must be confirmed by
SPX before the receiver can be enabled: signature header name and encoding, canonical
byte sequence, signing key selection, timestamp header/unit/tolerance, nonce or replay
rules, delivery identifier, request content type/maximum size, acknowledgement body and
status, redelivery schedule, ordering guarantees, and event identifier semantics.

The outbound `check-sign` algorithm in section 2 applies to calls made **to SPX**. It is
not evidence that inbound webhooks use the same construction. The plugin therefore
does not invent or reuse that scheme. Its REST skeleton is fail-closed and returns a
service-unavailable response without parsing or applying an unsigned event. Automatic
tracking is provided by signed sandbox `batch_search_order` calls through Action
Scheduler (with WP-Cron fallback), and manual synchronization remains available.

## 11. Phase 6M checkout address boundary (2026-07-13)

Phase 6M adds a shipping-only SPX hierarchy selector to Classic Checkout and Checkout
Blocks. It is deliberately a **local data path**: the browser reads only the plugin's
allowlisted WordPress REST routes, those routes read the imported SPX dataset, and no SPX
account, address-download, rate, shipment, tracking, cancel, label, webhook, or production
endpoint is called from Checkout.

The canonical order snapshot stores only province/district/ward IDs and names, dataset
version, source, Delivery/COD capability flags, and validation time via `WC_Order` CRUD.
It does not contain a raw request/response, signature, credential, full phone, or full
address. Shipment mapping precedence is: explicit admin override, then a complete/current/
serviceable checkout snapshot, then the existing WooCommerce name resolver. Checkout does
not create a tracking number and does not change totals, shipping totals, order status,
standard WooCommerce address fields, or Mock Provider behavior.

Classic validation is authoritative on the server. Blocks sends the four canonical IDs
through the official Store API extension-data channel and validates again while updating
the draft order. Missing, stale, tampered, inactive, Delivery-unsupported, or COD-
unsupported selections fail closed. The Blocks inner block is server-registered so
WooCommerce's frontend block map recognizes it; its extension-data effect depends on the
stable setter function to avoid a render/dispatch loop.

## 12. Phase 6I controlled WooCommerce status mapping (2026-07-13)

Phase 6I introduces no SPX endpoint. It consumes only a canonical status transition that
the existing tracking updater has already validated and persisted. Scheduler, manual
sync, and a future verified webhook share `SPX_Woo_Status_Mapping_Service`; the current
webhook remains fail-closed and was not enabled.

The code-defined contract maps `4001` to `completed`; `3001`, `5001`, `5002`, `5003`,
`6001`, `6002`, and `6003` to `on-hold`; and `7001` to `cancelled`. Settings only enable
or disable these immutable targets. `5001`, `6002`, `6003`, and `7001` default OFF, while
`3001`, `4001`, `5002`, `5003`, and `6001` default ON. Codes `1001`, `2001`, and `2006`
are explicit no-actions. No mapping to `refunded` or `failed` exists.

Allowed Woo source statuses prevent terminal/manual business decisions from being
overwritten. A per-order process guard, canonical-code audit metadata, and updater-level
`old_code !== new_code` trigger prevent loops and duplicate transitions. The service uses
`WC_Order::update_status()` and CRUD only, adds one private note for an applied mapping,
sends no custom email, changes no totals/payment data, and never creates a refund. Saving
settings does not scan or remap historical orders. Full design and test boundaries are in
`docs/superpowers/specs/2026-07-13-spx-woo-status-mapping-design.md`.

## 13. Phase 6G-A production hardening boundary (2026-07-14)

This phase performs no SPX request. It establishes a closed environment model with only
`test` (`https://test-stable.spx.vn/`) and `production` (`https://spx.vn/`), exact
relative endpoint allowlisting, HTTPS/443/no-userinfo/no-redirect enforcement, and no
custom host input. The production transport path is additionally blocked before signing
until a later online gate records a valid verification fingerprint and explicitly enables
the environment.

Test and production credentials use separate constant namespaces and encrypted option
records. Constants win; no cross-environment fallback exists. Stored secrets are
authenticated and versioned (`v1:sodium` preferred, `v1:aesgcm` fallback), using a key
derived from WordPress salts and plugin context. Decryption/authentication failure yields
an empty controlled value. Admin rendering exposes only configured/source state, never
plaintext or ciphertext. Changing the normalized credential fingerprint invalidates the
verification record.

Production states are `disabled`, `readiness_pending`, `verified`, and `enabled`, but
6G-A can reach only the first two. Verification and enablement are therefore not claimed.
Readiness requires complete production credentials, matching real verification, sender,
dataset, canonical HTTPS, HPOS, scheduling, and supported PHP/WP/WC versions; missing
webhook and fixed-rate checkout remain warnings. Orders use immutable
`_spx_environment`, with legacy `_spx_shipment_environment` preserved for sandbox
history. Scheduler work is grouped per environment and mixed batches fail before a
service call. Gate 6G-B may begin only with explicit authorization and real production
credentials/account readiness; it must not create a production shipment unless separately
authorized.

## 14. Phase 6J-X experimental dynamic Checkout rate (2026-07-14)

The sandbox-only experiment keeps the API response authoritative as raw audit data and
derives a Checkout candidate with `estimated_shipping_fee * 1000`. Contract version
`6J-X-1` records `fee_unit=unconfirmed`, an empty/unconfirmed currency, multiplier `1000`,
and conversion mode `experimental_thousand_vnd`. `vat_fee` is retained for audit only and
is not added to the candidate because the API does not document whether it is already
included in the estimated fee.

Execution is OFF by default and requires the test environment, sandbox allowlisted host,
complete sender/recipient hierarchy, compatible Delivery/COD capabilities, valid cart
weight/COD, and the explicit experimental setting. Failures return the configured fixed
rate. The short-lived cache uses only a one-way signature of normalized non-PII inputs;
disabling the experiment invalidates the dynamic cache. Classic and Blocks persist only
allowlisted rate audit fields through WooCommerce order CRUD/HPOS.

**FEE UNIT/CURRENCY STILL UNCONFIRMED. MULTIPLIER 1000 IS EXPERIMENTAL. NOT AUTHORIZED FOR
PRODUCTION CUSTOMER CHARGES.** No production rate request is enabled by this contract.
