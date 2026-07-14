# SPX Gate 6F-B Design

## Scope

Create one new disposable WooCommerce shipment on the SPX test host, confirm its canonical state, cancel that exact shipment once when and only when its canonical state is `1001`, then reconcile to canonical `7001`. Order 167 and tracking `SPXVN064181227127` are immutable controls.

## Safety model

- Permit only HTTPS POST requests to `test-stable.spx.vn` for `batch_create_order`, `batch_search_order`, and `batch_cancel_order`.
- Persist a durable Create marker before the one side-effecting Create request. Never retry Create after an uncertain result; recover only by deterministic client order ID.
- Persist a Gate-level Cancel marker before invoking the cancel manager. The manager uses its atomic per-order lock and always releases it. Never retry Cancel after an uncertain result; reconcile only by Search.
- Enable real cancel only through the manager's process-local constructor gate. Do not define or persist `SPX_ENABLE_REAL_CANCEL`, so the plugin-wide gate remains disabled before and after the test process.
- Audit only endpoint, method, ordinal, HTTP status, and SPX ret_code. Never persist request/response bodies, credentials, signatures, phones, or full addresses.
- Use fake sender/recipient data and a real intra-HCM hierarchy selected from the imported SPX dataset. Use `collect_type=2`, valid weight/dimensions, and valid COD capability.

## Flow

1. Create a fresh WooCommerce test order and snapshot WC status, total, and shipping total.
2. Validate readiness, environment, hierarchy/capabilities, deterministic client ID, no tracking/mock, and immutable order 167.
3. Mark Create attempted, call Create once, and persist its result through WC_Order CRUD.
4. Resolve duplicate/unknown Create only through at most three Searches with 0/1/3-second bounded timing.
5. Search the real tracking at most three times. Stop as `BLOCKED BY SANDBOX STATE` unless canonical status is `1001`.
6. Mark Cancel attempted and invoke the cancel manager once using the already-fetched canonical eligibility result plus an audited cancel service.
7. Search at most three times after Cancel. Pass only after canonical `7001` is applied through `SPX_Tracking_Updater`.
8. Verify timeline idempotency, single note, terminal scheduler exclusion, customer/guest rendering, non-delivered progress, HPOS persistence/sync, immutable WC financial/status fields, order 167, logs, and network counts.

## Production seams

Expose `SPX_Admin_Shipment::apply_create_result()` as a public persistence seam and allow an injected tracking service for duplicate recovery. This keeps the normal admin behavior unchanged while allowing the integration harness to use one audited HTTP client. Change the official `7001` customer label to `Đã hủy vận đơn` so the canonical cancelled timeline meets the approved UI wording.

## Terminal outcomes

- PASS only when Create returns/reconciles a real `SPXVN...` tracking number, Cancel was called exactly once, and Search observes canonical `7001`.
- BLOCKED BY SANDBOX STATE when pre-cancel canonical state is not `1001`; no Cancel call.
- UNKNOWN/BLOCKED when side-effect outcome is uncertain and bounded Search cannot establish the required canonical state.

