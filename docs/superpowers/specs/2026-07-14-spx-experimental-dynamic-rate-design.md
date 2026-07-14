# PHASE 6J-X Experimental Dynamic Sandbox Rate Design

## Status and boundaries

This document records the user-approved PHASE 6J-X design. The feature is experimental, disabled by default, sandbox-only, and limited to `POST /open/api/v1/order/batch_check_order`. It does not authorize production charges or establish the SPX fee unit/currency.

Activation requires all of the following: `SPX_EXPERIMENTAL_DYNAMIC_RATE === true`, `SPX_EXPERIMENTAL_FEE_MULTIPLIER === 1000`, SPX environment `test`, WordPress environment `local`, `development`, or `staging`, non-production operational state, complete sandbox credentials, a valid canonical sender, and a current valid Phase 6M recipient selection. Missing or invalid prerequisites cause no network request and use the configured fallback policy.

## Architecture

- `SPX_Fee_Conversion_Contract` owns conversion modes, contract version, multiplier validation, decimal preservation, plausibility limits (5,000–2,000,000 VND candidate), and safe audit output.
- `SPX_Checkout_Rate_Request_Builder` converts one WooCommerce package plus the canonical Phase 6M selection and sender profile into the existing internal shipment contract. It ignores virtual/downloadable items, resolves variations, requires positive item weight, aggregates quantity/dimensions, and revalidates hierarchy/status/Delivery/COD locally.
- `SPX_Checkout_Rate_Cache` stores only sanitized quotes under a PII-free deterministic key for 240 seconds and provides in-request coalescing.
- `SPX_Dynamic_Checkout_Rate_Service` evaluates all gates, calls the existing `SPX_Rate_Service` through the existing environment-aware provider once per cache miss, applies the conversion contract, and returns one of `spx_dynamic`, `fallback_fixed`, or `fixed_disabled_experiment`.
- `SPX_Shipping_Method` remains the single Classic/Blocks server-side integration point. It adds the customer label `SPX Express – Giao tiêu chuẩn`, applies the candidate only on a successful dynamic result, and otherwise applies the existing fixed amount or omits the rate under `fail_closed`.

## Data flow and persistence

The checkout selection is read from the existing Phase 6M session/customer snapshot and revalidated against the local dataset. SPX is never called from JavaScript. A safe quote is attached to WooCommerce rate metadata; when WooCommerce creates the shipping item/order, approved audit fields are copied using CRUD. Historical orders are not recalculated or migrated.

Safe audit fields are source, environment, individual raw fee numbers, multiplier, unit mode, converted VND candidate, quote time, contract version, and cache-hit state. Raw request/response, credentials, signatures, phone numbers, and full addresses are neither logged nor persisted. Raw fee fields are never rendered with `wc_price()`; only the converted candidate may be shown as a WooCommerce shipping amount.

## Failure and rollback

Default policy is `fixed_fallback`; optional `fail_closed` returns no SPX rate. API errors, invalid/missing weights, stale hierarchy, unsupported capabilities, bad credentials, production context, malformed fee, or implausible candidate never become a customer charge. Disabling either experimental constant immediately restores fixed behavior without migration.

## Verification

Development follows TDD with the six required unit suites, then offline runtime/HPOS persistence, an explicitly gated sandbox integration, and Classic/Blocks browser smoke at 390×844, 768×1024, and 1440×900. Protected orders 167, 208, 239, 244, 291, and 292 are audited before and after. The final classification can only be `SPX EXPERIMENTAL DYNAMIC RATE PASS` or a concrete blocker and must retain all unit/currency/production limitations.

