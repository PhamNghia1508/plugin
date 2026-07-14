# PHASE 6P — SPX Admin UX Consolidation Design

## Scope

Phase 6P is a presentation-only consolidation. It must not change API endpoints,
signing, environment routing, credential keys, address precedence, checkout/rate
calculation, shipment creation, duplicate prevention, tracking, scheduler, labels,
cancel execution, status mapping, order totals/statuses, or HPOS persistence.
Every browser/runtime test runs with outbound SPX traffic denied.

## Current renderer audit

Seven independent renderers currently attach to
`woocommerce_admin_order_data_after_shipping_address`:

| Priority | Renderer | Current content |
|---:|---|---|
| 10 | `SPX_Order_Actions::render_admin_tracking` | tracking summary |
| 10 | `SPX_Admin_Address::render_order_panel` | address override/readiness |
| 30 | `SPX_Admin_Rate::render_quote` | admin/dynamic rate audit |
| 40 | `SPX_Admin_Shipment::render_panel` | create/tracking state and fees |
| 50 | `SPX_Admin_Label::render` | label action |
| 51 | `SPX_Admin_Cancel::render` | cancel action |
| 60 | `SPX_Admin_Status_Mapping::render_order_panel` | mapping audit |

This duplicates tracking/status/timestamps and forces operational content into the
narrow Shipping column. Settings are rendered by `SPX_Admin_Address::render_page` as
one long page separated by `<hr>`, with tracking, mapping and production appended at
the bottom. `admin.css` is loaded only on legacy/HPOS order screens and contains one
small tracking rule.

Backend contracts remain intact:

- Import/sender: `manage_woocommerce`, `spx_address_save`.
- Address AJAX: `manage_woocommerce`, `spx_address_ajax`.
- Sandbox Create/Sync and mock Create: WooCommerce order-action handlers,
  `edit_shop_orders` and the WooCommerce order form nonce.
- Label: `manage_woocommerce`, `spx_get_label_{order_id}`.
- Cancel: `manage_woocommerce`, `spx_cancel_shipment_{order_id}`.
- Tracking settings/sync: `manage_woocommerce`, `spx_tracking_admin`.
- Status mapping: `manage_woocommerce`, `spx_save_status_mapping`.
- Production settings: `manage_woocommerce`, `spx_production_settings`.

The plugin declares HPOS compatibility through `FeaturesUtil`. Existing admin CSS
recognizes the legacy `post.php` hook and HPOS `woocommerce_page_wc-orders` hook, but
there is no feature-detected metabox screen registration yet.

## Architecture

### Settings

Add `SPX_Admin_Settings_Page` as the canonical settings renderer. The existing
submenu/page slug and all existing save handlers remain owned by their current classes.
`SPX_Admin_Address::render_page` delegates presentation to the new renderer.

Eight allowlisted tabs are exposed through `spx_tab`:

`overview`, `connection`, `sender`, `addresses`, `rates`, `tracking`, `statuses`,
and `tools`.

Unknown or non-scalar values resolve to `overview`; no file path or dynamic include is
derived from the query parameter. Each POST handler redirects to its fixed owning tab,
preserving PRG without trusting a posted return URL. The page uses WordPress nav tabs,
cards, notices and native form controls.

The overview reads existing state only and presents friendly status text plus CTAs.
Credential UI renders canonical URLs and configured/source state only. Secret values,
ciphertext and browser-side credential data are forbidden.

### Order screen

Add `SPX_Admin_Order_Metabox` as the only operational order renderer. It registers one
`SPX Express` metabox against both the feature-detected HPOS order screen ID and legacy
`shop_order`. All seven Shipping-column hooks are removed; their handler methods remain
available for regression and backend use.

The metabox resolves either a `WC_Order` or legacy post object and renders five sections:

1. shipment summary/readiness;
2. compact SPX address with a closed native editor/details block;
3. customer-facing rate source/amount plus closed fee diagnostics;
4. SPX/Woo status and mapping summary;
5. context-aware actions.

Tracking number, status, created time and last sync appear only in the shipment summary.
Technical metadata is allowlisted and placed in a closed
`<details class="spx-technical-details">` element.

### Action presentation

Existing handler hooks and nonces do not change. The normal Woo order-action dropdown no
longer receives mock/sandbox Create entries; the canonical metabox submits the same
WooCommerce order-action values. Sandbox Create is the primary action only when credentials,
readiness and duplicate guards permit it. Production Create is explanatory/disabled until
production verification exists. Mock Create is hidden from normal shop operation and may
appear only under a local/development technical section as “Tạo vận đơn giả lập” with a
“Chỉ dùng nội bộ” badge.

Label appears only for an eligible real sandbox shipment. Sync appears only with a real
tracking number or client order ID. Cancel appears only when the existing execution gate
is enabled and canonical status permits it; otherwise the UI states that cancellation is
not activated. No handler eligibility or service behavior changes.

## Presentation rules

- Vietnamese is the default for operational copy; raw mapping results use an explicit
  Vietnamese allowlist.
- Phase/Gate/constant names are absent from rendered HTML.
- Dynamic rate source labels are “SPX động”, “Phí cố định dự phòng”, or “Phí cố định”.
- Raw fees, canonical IDs, create state, cache/multiplier/contract and mapping audit live
  only under technical details.
- The experimental warning remains explicit: fee unit/currency unconfirmed, multiplier
  1000 experimental, not authorized for production customer charges.
- Webhook is described as unavailable pending authentication documentation; periodic
  tracking is the active mechanism.

## Accessibility and responsive behavior

All inputs/selects have unique IDs and associated labels. Checkbox groups use
`fieldset/legend`. Status badges include visible text, never color alone. Native
`details/summary`, buttons and links retain keyboard/focus behavior. Destructive actions
have descriptive text and confirmation.

CSS is scoped below `.spx-admin-page` or `.spx-order-metabox`; no global element,
`.wrap`, `.woocommerce`, table, input, select, or button rules are introduced. Settings
cards use a three/two/one-column grid. The metabox is one column on narrow admin screens;
wide tables use an overflow wrapper.

## Security boundaries

- Trust boundaries: `spx_tab`, existing settings POSTs, order form fields, AJAX address
  selectors and file import. Existing capabilities/nonces remain mandatory.
- `spx_tab` is sanitized and checked against a fixed allowlist.
- Every rendered value is escaped for its HTML context.
- Credentials, signatures, raw requests/responses, encryption payloads, phones and full
  addresses are excluded from the settings dashboard and technical details.
- No new endpoint, AJAX action, database key, external request or dependency is added.
- Browser fixtures must install the existing outbound SPX deny policy before WordPress
  HTTP transport.

## TDD and verification

Five new PHP 7.4 suites define settings tabs, order metabox registration/content,
Vietnamese/encoding, accessibility and action visibility. They must fail against the
pre-6P source before production changes and pass after implementation. Existing tests
then run under `--network none`; WordPress runtime tests use the offline runner.

Browser verification covers all eight settings tabs and representative protected/test
orders at 1280×800, 1440×900 and 1920×1080. It checks no overflow, no duplicated tracking,
closed technical details, action visibility, clean Shipping column, console/log health,
and localhost-only network traffic. Protected-order snapshots compare tracking, SPX/Woo
status, totals, timeline count, address source, mapping audit and rate audit before/after.
