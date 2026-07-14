# PHASE 6I — Controlled SPX → WooCommerce Status Mapping

Date: 2026-07-13  
Scope: local/offline implementation only; no SPX HTTP request, no production, no retroactive migration.

## Objective

Apply an allowlisted WooCommerce order-status transition only after `SPX_Tracking_Updater` has persisted a genuinely new canonical SPX status code. Scheduler, manual sync, and a future verified webhook must share one policy and one service.

## Root flow

1. A previously obtained tracking result enters `SPX_Tracking_Updater`.
2. The updater validates the result and selects the current canonical event.
3. It rejects conflicts, stale events, and non-advancing terminal history.
4. It persists SPX status/timeline through `WC_Order` CRUD and the existing event repository.
5. Only when the persisted canonical code changes (`old_code !== new_code`), it calls `SPX_Woo_Status_Mapping_Service`.
6. The service normalizes the source, evaluates immutable policy plus sanitized settings, enforces idempotency and allowed source states, and calls `$order->update_status()` at most once.
7. It persists allowlisted audit metadata and, only for an applied transition, one private order note.
8. Mapping failures are safely logged and recorded as `error`; they never roll back the already-persisted SPX tracking state.

No WooCommerce order-status hook calls SPX, and this phase adds no scheduler, webhook route, external request, refund, payment mutation, stock action, or historical scan.

## Components

### `SPX_Woo_Status_Mapping_Policy`

- Owns the canonical code → target/allowed-source contract.
- Reads `spx_woo_status_mapping_settings` and version `spx_status_mapping_settings_version=1`.
- Defaults master ON; 3001/4001/5002/5003/6001 ON; 5001/6002/6003/7001 OFF.
- Treats 1001/2001/2006 as `no_action`.
- Accepts only `yes`/`no` toggles for known keys. Targets never come from request data, and `refunded`/`failed` are impossible targets.

Canonical transitions:

| SPX | Target | Default | Allowed current Woo status |
|---|---|---:|---|
| 1001, 2001, 2006 | no action | — | — |
| 3001 | on-hold | ON | pending, processing |
| 4001 | completed | ON | pending, on-hold, processing |
| 5001 | on-hold | OFF | pending, processing |
| 5002 | on-hold | ON | pending, processing |
| 5003 | on-hold | ON | pending, processing |
| 6001 | on-hold | ON | pending, processing |
| 6002 | on-hold | OFF | pending, processing |
| 6003 | on-hold | OFF | pending, processing |
| 7001 | cancelled | OFF | pending, on-hold, processing |

### `SPX_Woo_Status_Mapping_Service`

- Receives the order, old/new canonical codes, source, and event timestamp.
- Normalizes source to `scheduler`, `manual`, `webhook`, or `unknown`. Scheduler retries are translated by the updater to `scheduler`.
- Uses a process-local, per-order guard released in `finally`; no persistent lock can become stale.
- Does not evaluate identical codes. A previously evaluated new code is not applied again, even after settings change or a manual Woo status override.
- Records: `_spx_last_mapped_status_code`, `_spx_last_mapped_woo_status`, `_spx_last_mapping_at`, `_spx_last_mapping_source`, `_spx_last_mapping_result`, `_spx_last_mapping_settings_version`.
- Results: `applied`, `no_action`, `disabled`, `already_applied`, `protected_status`, `manual_override_preserved`, and `error`.
- Calls `update_status()` only when target differs and current state is allowlisted. It never calls refund/payment APIs or changes totals.
- Adds one sanitized private plugin note only after an applied change. WooCommerce owns its normal transition/email behavior; the plugin sends no custom email.

### `SPX_Admin_Status_Mapping`

- Adds a settings section to the existing SPX admin page.
- Requires `manage_woocommerce`, a nonce, POST, and an allowlisted sanitizer.
- Displays immutable targets, defaults, Cancelled/Returned warnings, and a restore-defaults action.
- Adds an admin-only per-order audit panel; no force-map action and no customer/guest output.

## Idempotency and manual overrides

The audit code is written for every evaluated canonical transition, including disabled/no-action/protected results. Replaying the same SPX status therefore performs no second status update, email transition, or note. If an administrator changes the Woo status after an applied mapping, replaying that same SPX code returns `manual_override_preserved`; only a later genuine SPX code transition can be evaluated.

## Persistence and compatibility

All production order mutations use `WC_Order` CRUD. No direct order-table/post-status SQL is introduced. Settings changes do not clear audit metadata and do not trigger retroactive mapping. HPOS reload and unsynced counts are runtime-gate requirements.

## Security and privacy

Settings are capability/nonce protected and targets are code-defined. UI and logs contain only status codes, allowlisted result/source labels, order ID, and fixed messages—never credentials, signatures, raw API payloads, phone numbers, or addresses. The existing network-deny harness remains mandatory for every runtime test.

## TDD and verification plan

1. Add policy, service, admin, and updater-integration tests before production classes; capture RED output proving the classes/behavior are absent.
2. Implement policy and service, then integrate the updater after SPX persistence.
3. Implement secure settings and admin audit UI.
4. Add offline WooCommerce fixtures for Delivered, Lost/Damaged/Returning, Cancelled OFF/ON, Returned OFF/no-refund, duplicate replay, manual override, protected states, and error isolation.
5. Snapshot protected orders #167/#208/#239/#244/#291/#292 before and after.
6. Run PHP 7.4 lint, all existing/new suites, offline scheduler/manual/timeline/checkout/address/label/cancel regressions, HPOS checks, browser settings/order/customer smoke, security/log scans, and a zero-SPX-network audit.

No commit or push is part of this phase.
