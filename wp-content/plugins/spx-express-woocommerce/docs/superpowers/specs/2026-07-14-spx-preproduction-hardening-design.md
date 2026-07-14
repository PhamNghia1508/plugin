# SPX Express — Phase 6Q Pre-Production Functional Hardening (Design)

Date: 2026-07-14
Status: IN PROGRESS — design + canonical parcel foundation only. Not a Release Candidate.

> This document is design + audit evidence. It does not authorize production, does not
> enable dynamic-rate customer charging, and does not change the experimental `×1000`
> multiplier. No SPX API is called by any code described here.

## 0. Environment constraint recorded during this phase

The working shell used for 6Q has **no PHP CLI** (`php: command not found`) and must not
restart the Docker container running WordPress. Therefore the mandated runtime steps —
executing the PHPUnit-style scripts under `tests/`, offline fixture runs (A–H), browser
E2E, and protected-order snapshots — **cannot be executed by the author of this document**.
They remain REQUIRED before the Release Candidate and must be run in an environment that
has PHP + the Docker stack. Every "PASS" that depends on execution is therefore marked
NOT VERIFIED here, not asserted.

## 1. Pre-code audit (read-only findings)

### 1.1 Where weight/dimensions come from today

| Path | Source | Weight | Dimensions | Missing-data behavior |
|---|---|---|---|---|
| Rate (checkout) | `SPX_Checkout_Rate_Request_Builder::build` | `wc_get_weight(...,'g')`, qty-aware, variation→parent fallback | Collected per line; policy `length=max`, `width=max`, `height=Σ(h·qty)`; falls back to sender `default_parcel_*_cm`; else omitted | `missing_weight` → `failure()` (fail-closed on weight); dims optional |
| Create (order) | `SPX_Order_Mapper::build` | qty-aware, variation→parent fallback, default 500 g | **NONE** — no `length_cm/width_cm/height_cm` emitted | dims silently absent |
| Wire mapper | `SPX_Create_Request_Mapper::map_order` | `parcel_weight` kg (round 3) | `dimensions()` returns dims only if all >0, each ≤60, sum ≤180; **silently drops** if invalid | dims omitted when invalid |

**Conclusion:** Rate and Create do **not** use the same parcel formula. Rate computes and
sends dimensions (with a deterministic multi-item policy) and applies default-parcel
fallback; Create sends weight only. This is the core 6Q blocker (#1, #2). The fix is a
single shared parcel builder both paths call, so there is exactly one formula.

### 1.2 SPX wire contract (from `spx-api-analysis.md`)

- Create `parcel_info`: `parcel_weight` kg **required**, VN `0 < w ≤ 17`. `parcel_length/width/height` **optional**, VN each `≤ 60 cm`, `L+W+H ≤ 180 cm`. `parcel_item_name` required, `parcel_item_quantity` optional.
- Fee/Rate `parcel_info`: `parcel_weight` kg **required**, VN `0 < w ≤ 15`. dims optional, same limits.
- Dimensions are **optional** at the API. A shop policy that *requires* dimensions is a
  business decision stricter than SPX; it is opt-in via the missing-data policy below.

### 1.3 Existing limits already encoded

`SPX_Create_Request_Mapper`: `MAX_CREATE_WEIGHT_KG=17`, `MAX_DIM_CM=60`, `MAX_DIM_SUM_CM=180`.
Fee weight cap 15 kg is documented but not yet enforced as a distinct constant. The canonical
model centralizes all four so Rate uses 15 and Create uses 17 from one place.

### 1.4 Sender profile already carries default parcel dims

`SPX_Sender_Profile` defaults include `default_parcel_length_cm/width_cm/height_cm` (0 = unset).
There is **no** default weight field yet; 6Q adds `default_parcel_weight_grams` and a
`parcel_missing_policy` (`fail_closed` | `shop_default`).

## 2. Canonical parcel model

New, isolated files (nothing includes them yet — additive, cannot change current behavior):

- `includes/parcel/class-spx-parcel-validation-result.php` — immutable value object.
- `includes/parcel/class-spx-parcel-builder.php` — pure builder + WC adapter.

### 2.1 Result shape

```
weight_kg, length_cm, width_cm, height_cm, item_count,
source ∈ { product, shop_default, mixed, none },
warnings[] (sanitized slugs), errors[] ({ code, message_vi }),
policy_version, valid (bool)
```

### 2.2 Deterministic packaging approximation (documented, NOT "optimization")

Matches the formula already proven on the Rate path so Rate == Create:

- `weight_kg = Σ(item_weight_kg × quantity)`
- `length_cm = max(item_length_cm)`
- `width_cm  = max(item_width_cm)`
- `height_cm = Σ(item_height_cm × quantity)`

This is a conservative single-box approximation, explicitly not 3D bin packing. Semantic
length/width/height are preserved (SPX does not require sorted edges).

### 2.3 Unit conversion

- Weight → kg: `wc_get_weight($v,'kg',$unit)` when available; internal factor table
  (`kg=1, g=0.001, lbs=0.45359237, oz=0.028349523125`) as deterministic fallback for tests.
- Dimension → cm: internal factor table (`cm=1, mm=0.1, m=100, in=2.54, yd=91.44`); prefers
  `wc_get_dimension` when present. No integer casts before conversion (decimal-safe).
- Virtual/downloadable/`!needs_shipping` lines are excluded from the parcel.

### 2.4 Missing-data policy

- `fail_closed` (production default): a physical line missing weight → invalid
  (`Sản phẩm chưa có cân nặng.`). Missing dimensions → invalid
  (`Sản phẩm chưa có kích thước.`) unless the shop has valid default dims. No fabricated 0s.
- `shop_default` (opt-in, local/sandbox): fill missing weight/dims from configured shop
  defaults **only when those defaults are > 0**; otherwise still invalid. `source=shop_default`
  or `mixed`.
- Missing data is never silently coerced to 0.

### 2.5 Limit validation (Vietnamese, no raw slugs surfaced)

- `weight_kg ≤ max_weight_kg` → `Kiện hàng vượt giới hạn cân nặng SPX.`
- each dim `≤ 60` → `Kiện hàng vượt giới hạn kích thước SPX.`
- `L+W+H ≤ 180` → `Tổng ba chiều kiện hàng vượt giới hạn SPX.`
- no physical item → `Đơn hàng không có sản phẩm cần giao.`

`max_weight_kg` is supplied by the caller: 15 for Rate, 17 for Create.

### 2.6 Cache invalidation

`POLICY_VERSION` constant is included in the parcel result and MUST be added to the Rate
cache key (alongside weight, dims, quantity, variation, default-parcel settings) so a change
to product dimensions or shop defaults never serves a stale quote.

## 3. Planned integration (NOT done this turn — requires PHP runtime to verify)

1. `SPX_Order_Mapper::build` → attach `length_cm/width_cm/height_cm` from the shared builder
   (Create side gains dimensions; identical formula to Rate).
2. `SPX_Checkout_Rate_Request_Builder::build` → delegate parcel computation to the shared
   builder (remove the inline duplicate formula) so there is a single source of truth.
3. `SPX_Shipment_Readiness` → consume the parcel result; emit the Vietnamese reasons above.
4. Rate cache key → add `POLICY_VERSION` + default-parcel settings hash.
5. Create → persist `_spx_parcel_weight_kg/length_cm/width_cm/height_cm/source/policy_version`
   snapshot; never rewrite a created shipment's parcel.
6. Settings UI → "Kiện hàng mặc định" section (weight + 3 dims + policy select), validated,
   no negatives/zeros, upper-bounded to SPX limits.
7. Scheduler health UI + hardening audit (§13–15 of the phase brief).
8. Cancel/jargon presenter (map internal slugs → Vietnamese at the view layer only; do not
   change error contracts the tests depend on).
9. README operational rewrite.

Each of steps 1–9 touches files with existing passing tests and therefore MUST be landed
behind a green run of the full `tests/` suite, which this environment cannot execute.

## 4. Test plan (files added; execution pending PHP runtime)

- `tests/test-spx-parcel-builder.php` — unit conversion (kg/g/lbs/oz, cm/mm/m/in/yd),
  quantity, variation/parent fallback, virtual excluded, multi-item deterministic dims,
  shop_default vs fail_closed, missing weight, missing dims.
- `tests/test-spx-parcel-validation.php` — SPX weight cap (15/17), single-dim cap 60,
  total-dim cap 180, no-item, Rate/Create parcel equality on identical input.

RED/GREEN evidence is REQUIRED and currently NOT VERIFIED (no PHP CLI here).

## 5. Explicitly preserved limitations (unchanged by 6Q)

- Fee multiplier stays experimental (`×1000`, `VND_candidate`); no production charging.
- Production Account Verify not run; production shipment not created.
- Webhook remains fail-closed (no signature docs).
- Real Cancel stays default-disabled.
