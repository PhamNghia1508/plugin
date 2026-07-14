# SPX Experimental Dynamic Sandbox Rate Implementation Plan

> **For agentic workers:** Execute task-by-task with test-first red/green cycles. This workspace is not a Git repository and the user explicitly prohibited commit/push, so commit steps are intentionally omitted.

**Goal:** Add a default-off, sandbox-only dynamic checkout rate using `estimated_shipping_fee * 1000` with strict fallback, cache, safe audit persistence, and Classic/Blocks verification.

**Architecture:** Four focused rate classes feed the existing WooCommerce shipping method and reuse the existing API provider/rate service. The Phase 6M canonical address remains authoritative; all external and checkout data is revalidated before a single allowlisted sandbox request.

**Tech Stack:** PHP 7.4, WordPress/WooCommerce CRUD and shipping APIs, existing SPX API client, plain PHP test runners, Docker runtime, WooCommerce HPOS, browser DevTools.

## Global constraints

- Dynamic mode is enabled only by exact constants `true` and `1000`; every default is off.
- Only sandbox `POST /open/api/v1/order/batch_check_order` is authorized, and only in the explicitly gated integration.
- Fee unit/currency remain unconfirmed; multiplier 1000 is experimental and not authorized for production charges.
- No Create/Search/Cancel/Label/Account/Address API, production, webhook, cron, Phase 6G-B, or Phase 6H work.
- No raw request/response, credentials, signature, phone, or full address in logs/cache/order metadata.
- No changes to protected orders, Phase 6M address architecture, Phase 6I mapping, mock provider, or historical totals.

### Task 1: Conversion contract

**Files:** create `tests/test-spx-fee-conversion-contract.php` and `includes/rate/class-spx-fee-conversion-contract.php`.

- [ ] Write assertions for all four modes, exact 1000 gate, decimal preservation, valid boundaries, suspicious values, and safe audit fields.
- [ ] Run the new test under PHP 7.4 and confirm RED because the class is absent.
- [ ] Implement only the conversion contract and rerun until GREEN.

### Task 2: Request builder

**Files:** create `tests/test-spx-dynamic-checkout-rate.php` and `includes/rate/class-spx-checkout-rate-request-builder.php`.

- [ ] Write tests for canonical hierarchy/capabilities, variation quantity, virtual/downloadable exclusion, required weight, dimensions, COD, and PII-free failures.
- [ ] Confirm RED, implement the pure builder using existing dataset/sender/session contracts, then confirm GREEN.

### Task 3: Cache and orchestration

**Files:** create `tests/test-spx-checkout-rate-cache.php`, `includes/rate/class-spx-checkout-rate-cache.php`, and `includes/rate/class-spx-dynamic-checkout-rate-service.php`.

- [ ] Test 240-second TTL, PII-free key composition, COD/cart/address/mode invalidation, cache hit, and in-request coalescing.
- [ ] Confirm RED, implement cache, and confirm GREEN.
- [ ] Add service gate tests covering production fail-closed, disabled constants, credentials/sender/address gates, API failure, implausible fee, `fixed_fallback`, and `fail_closed`.
- [ ] Confirm RED, implement orchestration through the existing test provider/rate service, and confirm GREEN.

### Task 4: WooCommerce shipping and audit persistence

**Files:** create `tests/test-spx-shipping-method-dynamic.php`, `tests/test-spx-dynamic-rate-persistence.php`; modify `includes/class-spx-shipping-method.php`, `includes/class-spx-plugin.php`, and the focused admin audit renderer if needed.

- [ ] Test the exact customer label, dynamic amount, fixed rollback, no duplicate rate, no manual order-total mutation, and safe rate metadata.
- [ ] Confirm RED, integrate the shared server-side service, and confirm GREEN.
- [ ] Test shipping-item/order CRUD persistence and historical stability, confirm RED, add approved audit-copy hooks, and confirm GREEN.

### Task 5: Security suite and runtime integration

**Files:** create `tests/test-spx-dynamic-rate-security.php` and `tests/runtime/spx-dynamic-rate-sandbox-integration.php`; modify the test-only network harness only if required.

- [ ] Test endpoint allowlisting, production denial, safe cache/log/meta, no browser endpoint, and absence of release-tree test flags.
- [ ] Confirm RED, add the minimum hardening, and confirm GREEN.
- [ ] Build the gated runtime matrix using production classes, temporary products/orders, safe output, HPOS reload, cleanup, and protected-order rollback checks.

### Task 6: Full verification

- [ ] Run PHP 7.4 lint, JS build, every existing and new unit suite, offline runtime suites, and fallback/rollback tests.
- [ ] Run the sandbox integration only with `SPX_ALLOW_SANDBOX_RATE_CHECK=true`; record request counts and endpoint without secrets/PII.
- [ ] Run Classic and Blocks browser E2E with an offline/fake test harness at all required viewports, then remove the harness.
- [ ] Verify HPOS unsynced=0, protected orders unchanged, no pending SPX work, zero unauthorized external requests, and clean sanitized logs.
- [ ] Produce the required 12-section report and stop without starting another phase.

