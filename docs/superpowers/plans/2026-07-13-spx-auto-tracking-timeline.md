# SPX Auto Tracking + Customer Timeline Implementation Plan

> **For agentic workers:** Execute inline in this workspace. Do not commit, push, create/cancel/label shipments, call production, or use subagents.

**Goal:** Add idempotent SPX tracking history, scheduled batch synchronization, a disabled-until-documented webhook boundary, and safe customer/guest journey UI without changing WooCommerce order status or checkout.

**Architecture:** All inputs (scheduler, manual sync, future verified webhook) converge on `SPX_Tracking_Updater`. A custom event table stores only public-safe normalized events. Action Scheduler batches eligible HPOS orders and uses `batch_search_order`; rendering reads persisted data only.

**Tech Stack:** PHP 7.4, WordPress 6.9.4, WooCommerce 10.9.4/HPOS, MariaDB 10.11, Action Scheduler, WordPress REST API, Playwright.

## Global Constraints

- Sandbox only; order 167 / `SPXVN064181227127` may be searched but never created again.
- Webhook signature verification remains disabled unless official SPX algorithm, headers, canonical bytes, timestamp and ACK contract are documented.
- Never store/log raw webhook/API payloads, headers, signatures, credentials, phones, addresses or payment data.
- Never change WooCommerce order status, order total, shipping total, Cart/Checkout, Mock Provider, label or cancel.
- No API calls while rendering frontend/admin pages.
- No commit or push.

---

### Task 1: Documentation and schema

**Files:**
- Modify: `wp-content/plugins/spx-express-woocommerce/spx-api-analysis.md`
- Create: `wp-content/plugins/spx-express-woocommerce/includes/tracking/class-spx-tracking-event-repository.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/spx-express-woocommerce.php`
- Test: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-tracking-events.php`

- [ ] Document `WEBHOOK BLOCKED BY DOCUMENTATION` and every unknown official contract field.
- [ ] Write failing repository tests for deterministic keys, duplicate ignore, sorting and PII/raw-field exclusion.
- [ ] Run the test and confirm RED because the repository/schema class is absent.
- [ ] Implement versioned `dbDelta` schema and parameterized repository methods.
- [ ] Run repository tests and confirm GREEN.

### Task 2: Route parser and common updater

**Files:**
- Create: `includes/tracking/class-spx-tracking-route-parser.php`
- Create: `includes/tracking/class-spx-tracking-updater.php`
- Modify: `includes/api/class-spx-tracking-service.php`
- Test: `tests/test-spx-tracking-events.php`

- [ ] Write failing tests for unsorted/duplicate/invalid/Unicode routes and stale/current/terminal event behavior.
- [ ] Run RED.
- [ ] Return safe route message and EDD fields from tracking service without returning raw objects.
- [ ] Implement parser and updater; updater writes WC_Order meta/notes only when current status changes.
- [ ] Run tests GREEN and assert Woo status is unchanged.

### Task 3: HPOS order query, batch sync, locking and retry scheduling

**Files:**
- Create: `includes/tracking/class-spx-tracking-order-query.php`
- Create: `includes/tracking/class-spx-tracking-sync-service.php`
- Create: `includes/tracking/class-spx-tracking-scheduler.php`
- Modify: `includes/api/class-spx-tracking-service.php`
- Test: `tests/test-spx-auto-tracking.php`

- [ ] Write failing tests for eligibility/pagination, SPXMOCK/terminal/old exclusion, <=100 batch chunks, partial failure, auth-stop, lock/stale-lock and delayed retry.
- [ ] Run RED.
- [ ] Implement `get_many_by_tracking_numbers()` with validation, dedupe, chunks and per-item results.
- [ ] Implement WC order query, atomic five-minute lock, Action Scheduler recurring action with WP-Cron fallback, and one delayed retry action per retry count.
- [ ] Run tests GREEN.

### Task 4: Webhook disabled skeleton

**Files:**
- Create: `includes/tracking/class-spx-webhook-verifier.php`
- Create: `includes/tracking/class-spx-webhook-controller.php`
- Test: `tests/test-spx-webhook.php`

- [ ] Write failing tests for disabled route, invalid/empty/oversized/JSON bodies, altered raw body and no update on unverified requests.
- [ ] Run RED.
- [ ] Implement POST-only REST route with body/content constraints and verifier that fails closed while official signature contract is unavailable.
- [ ] Run tests GREEN and verify no unsigned update path exists.

### Task 5: Customer and guest timeline

**Files:**
- Create: `includes/tracking/class-spx-customer-tracking.php`
- Create: `assets/css/tracking.css`
- Modify: `includes/class-spx-order-actions.php`
- Test: `tests/test-spx-customer-timeline.php`

- [ ] Write failing tests for progress/exception mapping, sorted timeline, public-safe fields, owner access, valid order-key guest access and generic denial.
- [ ] Run RED.
- [ ] Implement persisted-data-only My Account/order-received renderer and responsive vertical timeline.
- [ ] Run tests GREEN at 390/768/1440 widths without SPX browser requests.

### Task 6: Settings, health UI and plugin wiring

**Files:**
- Create: `includes/admin/class-spx-admin-tracking.php`
- Modify: `includes/class-spx-plugin.php`
- Modify: `spx-express-woocommerce.php`
- Modify: `uninstall.php`
- Test: `tests/test-spx-auto-tracking.php`

- [ ] Write failing tests for settings, schedule-once/update/disable/deactivate, async Sync Now and safe health fields.
- [ ] Run RED.
- [ ] Wire schema upgrades, tracking classes, scheduler hooks, manual updater and admin health UI.
- [ ] Run tests GREEN.

### Task 7: Integration, browser and security verification

**Files:**
- Create: `tests/runtime/spx-auto-tracking-integration.php`
- Create: `tests/e2e/auto-tracking.mjs`

- [ ] Run the existing create/tracking runner in recovery/search-only mode and prove no Create call.
- [ ] Search order 167, insert routes, reload HPOS, sync again and prove no duplicate events/notes.
- [ ] Run webhook REST simulation and classify real delivery NOT RUN/BLOCKED.
- [ ] Run admin/customer/guest browser E2E and responsive screenshots.
- [ ] Run full PHP 7.4 lint, all unit/integration suites, HPOS `unsynced=0`, security/log scans.
- [ ] Report PASS/PASS WITH LIMITATIONS/FAIL/BLOCKED with per-test evidence.
