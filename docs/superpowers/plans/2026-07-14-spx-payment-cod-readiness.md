# SPX Payment/COD Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make WooCommerce payment confirmation the fail-closed source of truth for SPX COD, shipment readiness, admin presentation, and Create validation.

**Architecture:** Add a pure `SPX_Payment_Resolver` that reads `WC_Order` once and returns canonical payment state. Propagate that result through the existing order mapper, readiness evaluator, shipment service, Create mapper, direct action, and metabox without changing WooCommerce totals/status.

**Tech Stack:** PHP 7.4+, WordPress 6.9, WooCommerce 10.9, HPOS, lightweight PHP unit tests, Docker runtime integration, in-app browser smoke.

## Global Constraints

- No Production or Sandbox SPX API requests.
- No Account Verify, Rate, Create, Search, Label, or Cancel.
- Do not call `payment_complete()` or alter WooCommerce order status/totals.
- Do not emit raw orders, PII, secrets, signatures, or raw payloads.
- Do not commit or push.

---

### Task 1: RED resolver and propagation contract

**Files:**
- Create: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-payment-resolver.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-shipment.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-admin-order-metabox.php`

**Interfaces:**
- Produces the wished-for `SPX_Payment_Resolver::resolve( WC_Order $order ): array` contract.
- Requires `SPX_Order_Mapper` output to expose canonical payment fields.
- Requires `SPX_Shipment_Service` to reject unresolved payment before HTTP.

- [ ] Write tests for paid online, unpaid COD, unpaid online, missing method, terminal statuses, invalid totals, unchanged totals, direct Create blocking, and metabox source consistency.
- [ ] Run PHP 8.2 targeted tests before production edits.
- [ ] Record the expected failures caused by the missing resolver/payment enforcement in `tests/artifacts/spx-payment-red.txt` using sanitized assertion names only.

### Task 2: Canonical payment resolver

**Files:**
- Create: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-payment-resolver.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-plugin.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-order-mapper.php`

**Interfaces:**
- `SPX_Payment_Resolver::resolve()` returns `payment_method`, `is_paid`, `needs_payment`, `payment_state`, `cod_amount`, `ready_for_shipment`, and `reason`.
- `SPX_Order_Mapper::build()` copies the canonical result to `payment` and compatibility top-level fields without re-deriving COD.

- [ ] Implement the minimal fail-closed resolver in the approved precedence order.
- [ ] Load it before mapper/readiness classes.
- [ ] Replace mapper COD inference with resolver output.
- [ ] Run the resolver target test until GREEN.

### Task 3: Readiness and Create-path enforcement

**Files:**
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-shipment-readiness.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/admin/class-spx-admin-shipment.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/admin/class-spx-admin-address.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/api/class-spx-shipment-service.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/api/class-spx-create-request-mapper.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-order-actions.php`

**Interfaces:**
- Readiness consumes `payment` and maps canonical reasons to escaped user-facing messages.
- Shipment service rejects `ready_for_shipment !== true`, preserves `cod_amount=null`, and returns a local failure without invoking its HTTP client.
- Create mapper throws on a canonical COD shipment without a valid integer amount; it never supplies a zero fallback.

- [ ] Feed canonical payment into every readiness context.
- [ ] Add local Create validation before request mapping.
- [ ] Block the legacy direct action using the same canonical state.
- [ ] Run shipment/readiness/direct-path target tests until GREEN and confirm fake HTTP call count remains zero for blocked cases.

### Task 4: Canonical admin payment UX

**Files:**
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/admin/class-spx-admin-order-metabox.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-admin-order-metabox.php`

**Interfaces:**
- Metabox renders only resolver output plus WooCommerce shipping total.
- Canonical reasons render the approved Vietnamese block messages.

- [ ] Add payment method, paid state, COD amount, shipping fee, and blocking reason to the canonical metabox.
- [ ] Escape all output and render no raw gateway metadata.
- [ ] Run metabox tests until GREEN.

### Task 5: Runtime order #642 and HPOS verification

**Files:**
- Create: `tests/staging/payment-readiness-audit.php`

**Interfaces:**
- Audit prints only payment code, status, booleans, totals, resolved COD/null, readiness, and reason.

- [ ] Deploy only changed plugin files to the isolated HTTPS staging container after local tests pass.
- [ ] Audit order #642 read-only and assert `payment_method_missing`, COD null, and readiness blocked.
- [ ] Verify HPOS enabled and unsynced count zero without changing order #642.
- [ ] Run an admin metabox browser smoke without DOM dump or screenshots.

### Task 6: Full regression and security clean window

**Files:**
- Modify only test/harness files required to run network-blocked verification.

**Interfaces:**
- All suites execute with SPX network guard active and report counts only.

- [ ] Run targeted resolver, readiness, shipment, Create mapper, and metabox tests.
- [ ] Run every plugin PHP test under PHP 7.4 and PHP 8.2.
- [ ] Run HPOS, Classic Checkout, Blocks, fixed/dynamic shipping, and existing integration regressions offline.
- [ ] Scan plugin source, artifacts, staging/runtime/container/Woo logs, browser console, and temporary files for current-window secrets, PII, raw payloads, and SPX endpoints.
- [ ] Remove temporary deployment/audit artifacts and verify external SPX requests remain zero.
- [ ] Review the final diff manually and report the documented prior browser-output exposure without claiming it never happened.
