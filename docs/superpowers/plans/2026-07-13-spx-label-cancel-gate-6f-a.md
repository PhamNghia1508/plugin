# SPX Label + Cancel Gate 6F-A Implementation Plan

> **For agentic workers:** Execute inline. Do not commit, push, call production, Create, or real Cancel. Gate 6F-B requires separate user approval.

**Goal:** Add safe sandbox label retrieval and a complete, simulated cancel workflow without changing WooCommerce order state or canceling order 167.

**Architecture:** API services parse SPX batch responses only. Shipment managers enforce order/environment/idempotency/URL rules. Admin POST handlers own capability and nonce checks. Cancel always performs canonical Search first and uses an injected cancel service so Gate 6F-A can prove behavior without a real cancel request.

**Tech Stack:** PHP 7.4, WordPress 6.9.4, WooCommerce 10.9.4/HPOS, SPX sandbox, Playwright.

## Global Constraints

- Sandbox Label and Search endpoints only.
- Never call `batch_create_order`, `batch_cancel_order`, production, account/create, or address download.
- Order 167 may be labeled and searched, never canceled or recreated.
- No Cart/Checkout, Woo status, refund, order deletion, commit, or push.

---

### Task 1: Official-contract record and RED tests

**Files:** `spx-api-analysis.md`, `tests/test-spx-label.php`, `tests/test-spx-cancel.php`, `tests/test-spx-admin-label-cancel.php`

- [ ] Record endpoint schemas, label TTL/open limit, fail-list behavior, cancel eligibility and every unknown.
- [ ] Add failing tests for label mapping/chunking/partial results/URL safety/cache policy.
- [ ] Add failing tests for canonical eligibility, cancel states, lock, unknown result and reconciliation.
- [ ] Add failing source/security tests for POST, capability, nonce, WC_Order tracking source and no customer exposure.
- [ ] Run each new test and confirm RED because production classes are absent.

### Task 2: Label API and manager

**Files:** `includes/api/class-spx-label-service.php`, `includes/shipment/class-spx-label-manager.php`

- [ ] Implement `get_shipping_labels(array $tracking_numbers)` with validation, dedupe, chunks of 30, per-item success/failure, and no raw data.
- [ ] Implement HTTPS/exact-host/private-IP URL validation without fetching or following redirects.
- [ ] Implement WC_Order manager for `environment=test`, short transient bounded to 30 minutes, and safe non-URL order metadata.
- [ ] Run label tests GREEN.

### Task 3: Cancel API and manager

**Files:** `includes/api/class-spx-cancel-service.php`, `includes/shipment/class-spx-cancel-manager.php`

- [ ] Implement one-shot cancel service parsing successful tracking list and fail-list; temporary/transport outcomes become unknown and are never retried.
- [ ] Implement manager canonical Search gate: only status 1001 is eligible; terminal/delivering/return states fail closed.
- [ ] Implement atomic per-order lock, persisted state machine, one success note, reconciliation through Search/updater, and protected Woo fields.
- [ ] Keep the service injectable and ensure Gate 6F-A tests use only fakes.
- [ ] Run cancel tests GREEN.

### Task 4: Admin POST actions and wiring

**Files:** `includes/admin/class-spx-admin-label.php`, `includes/admin/class-spx-admin-cancel.php`, `includes/class-spx-plugin.php`, `assets/css/admin.css`

- [ ] Add HPOS order UI with masked tracking, explicit cancel warning, and POST forms.
- [ ] Enforce `manage_woocommerce`, nonce, method POST and order reload; never accept tracking/label URL from request.
- [ ] Label result renders a safe expiring link with `noopener noreferrer`; no automatic open/download.
- [ ] Gate real cancel behind a disabled option/constant in Gate 6F-A; simulation calls manager directly with fake service.
- [ ] Run admin security tests GREEN.

### Task 5: Runtime integrations

**Files:** `tests/runtime/spx-label-integration.php`, `tests/runtime/spx-cancel-simulation.php`

- [ ] Label integration calls `batch_get_shipping_label` exactly once for order 167 and never downloads the URL.
- [ ] Verify scheme/host, transient policy, totals/status and absence of Create/Cancel endpoints.
- [ ] Cancel simulation covers eligible success, ineligible, timeout unknown, duplicate lock/state, reconciliation, timeline and HPOS using fake transport only.
- [ ] Classify results exactly; never claim label PASS if sandbox returns no label.

### Task 6: Browser, docs and full verification

**Files:** `tests/e2e/label-cancel.mjs`, `README.md`

- [ ] Browser-check admin controls, confirmation copy, label POST, Sync Now enqueue notice, customer/guest non-exposure and three viewports.
- [ ] Update README with TTL, sharing warning, cancel semantics, unknown reconciliation, sandbox/webhook limitations.
- [ ] Run PHP 7.4 lint, all old/new suites and integrations, HPOS sync=0, security/log/network scans.
- [ ] Report Gate 6F-A and stop; only describe Gate 6F-B.
