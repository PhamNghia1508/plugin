# SPX Shipping Rate Zero/FREE Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure the fixed SPX shipping mode always serializes its configured positive fee across Cart Blocks, Checkout Blocks, and Classic Checkout, while invalid/unavailable rates fail closed and payment/COD remains independent.

**Architecture:** WooCommerce remains authoritative for matching a shipping zone. `SPX_Shipping_Method` must bind its package-cache signature to the matched SPX instance and that instance's settings, while the dynamic orchestrator may only emit a positive fixed fallback. Test fixtures must create one deterministic VN zone in fixed-only mode and clean it up instead of accumulating overlapping instances.

**Tech Stack:** WordPress 6.9, WooCommerce shipping zones and Store API, PHP 7.4/8.2, WooCommerce Blocks, local Docker runtime, in-app browser smoke.

## Global Constraints

- Preserve `SPX_Payment_Resolver` and the accepted payment/COD contract.
- Do not call Account Verify, Production Rate, Create, Search, Label, Cancel, or any other SPX API.
- Never turn an empty, invalid, missing-parcel, or missing-address result into a zero-cost shipping rate.
- Do not change order totals, shipping totals, payment status, or call `payment_complete()`.
- Do not commit or push.

---

### Task 1: Instance-aware cache contract

**Files:**
- Create: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-shipping-instance-cache.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-shipping-method.php`

**Interfaces:**
- Consumes: WooCommerce matched zone and each `SPX_Shipping_Method` instance's `instance_id`, `enabled`, `base_cost`, `free_shipping_min_amount`, and `environment`.
- Produces: `SPX_Shipping_Method::settings_cache_signature(array $settings): string` and a package field `spx_shipping_instance_signature`.

- [ ] Write a failing test proving different instance IDs, fixed fees, thresholds, enabled states, and environments produce different signatures while identical settings are stable.
- [ ] Write a failing test proving `add_package_cache_signature()` uses the matched instance rather than a global/default instance.
- [ ] Run the test on PHP 8.2 with `--network none` and save the expected failures to `tests/artifacts/spx-shipping-rate-zero-red.txt`.
- [ ] Implement the minimal instance-aware signature and keep the existing dynamic experiment signature as a separate input.
- [ ] Run the test on PHP 7.4 and PHP 8.2 until GREEN.

### Task 2: Positive-fee and fail-closed rate contract

**Files:**
- Modify: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-shipping-rate-zero-guard.php`
- Modify: `wp-content/plugins/spx-express-woocommerce/tests/test-spx-dynamic-fallback-zero.php`
- Verify: `wp-content/plugins/spx-express-woocommerce/includes/class-spx-shipping-method.php`
- Verify: `wp-content/plugins/spx-express-woocommerce/includes/rate/class-spx-dynamic-checkout-rate-service.php`

**Interfaces:**
- Consumes: raw instance `base_cost` and dynamic result `{add_rate,cost,source,audit}`.
- Produces: one positive `WC_Shipping_Rate`, an intentional explicit-threshold free rate, or no rate plus a safe notice.

- [ ] Add RED assertions for empty, invalid, zero, dynamic unavailable, missing parcel/address with fail-closed policy, and a configured positive fixed fee.
- [ ] Run the tests before any further production edit and append the failure evidence.
- [ ] Keep `calculate_shipping()` from calling `add_rate()` for non-numeric or non-positive candidates.
- [ ] Keep `SPX_Dynamic_Checkout_Rate_Service::fallback()` from authorizing a zero/invalid fixed fallback.
- [ ] Run targeted tests on PHP 7.4 and PHP 8.2 until GREEN.

### Task 3: Deterministic fixed-mode runtime fixtures

**Files:**
- Modify: `tests/runtime/integration.php`
- Modify: `tests/e2e/setup-e2e.php`
- Modify: `tests/e2e/e2e.mjs`

**Interfaces:**
- Consumes: one local VN test zone and its exact SPX instance.
- Produces: fixed fee `30000`, threshold disabled (`0`), and one matching `spx_express:<instance_id>` rate for all checkout surfaces.

- [ ] Change E2E expectations first so quantity updates must continue showing `30,000` and must not show `FREE`; run once to record RED against the current threshold fixture.
- [ ] Configure the fixture's `free_shipping_min_amount` to `0` and assert the returned instance ID is the one serialized by Store API.
- [ ] Make runtime integration reuse/delete only its named fixture zone and remove expectations that a zero base cost creates a valid free rate.
- [ ] Verify quantity, address, and settings changes invalidate their respective cache keys without adding duplicate zones.

### Task 4: Cross-surface and payment regression

**Files:**
- Modify or create only a focused runtime test under `tests/runtime/` if existing E2E assertions cannot expose the Store API amount directly.

**Interfaces:**
- Consumes: Store API cart rate and Classic shipping rate.
- Produces: the same `30000` amount on Blocks Cart, Blocks Checkout, and Classic Checkout.

- [ ] Run offline runtime integration with the outbound SPX deny policy.
- [ ] Run Cart Blocks, Checkout Blocks, and Classic browser flows; verify rate ID, amount, duplicate count, and console counts without DOM dumps.
- [ ] Re-run payment resolver tests proving paid online has COD `0` while shipping remains `30000`, and unpaid COD uses the order total while shipping remains `30000`.

### Task 5: Final dry gate and security audit

**Files:**
- Reuse: `tests/staging/payment-readiness-audit.php`
- Reuse: `tests/staging/security-clean-window-audit.php`

**Interfaces:**
- Consumes: controlled order `#642` and current payment state.
- Produces: `PAYMENT_METHOD_REQUIRED` if the method remains empty; otherwise the accepted COD/paid-online readiness result.

- [ ] Run full PHP 7.4 and PHP 8.2 suites and lint all PHP files.
- [ ] Verify HPOS enabled and unsynced count `0`.
- [ ] Verify endpoint request counts, SPX host markers, console warning/errors, secrets, signatures, phone, and address matches are all `0`.
- [ ] Run Gate 0 dry only; never call a Production API.
- [ ] Stop before PHASE 6G-B Production verification.
