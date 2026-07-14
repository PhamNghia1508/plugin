# SPX Phase 6G-A — Production Hardening Offline Design

## Scope and invariants

Phase 6G-A prepares configuration, credential storage, routing, readiness, and lifecycle controls entirely offline. It does not verify a production account and cannot move production past `readiness_pending`. It must never call SPX during implementation or verification. Existing sandbox shipments remain bound to `test`; Mock Provider, checkout, totals, shipping totals, WooCommerce status, and protected orders are untouched.

## Canonical environment model

`SPX_Environment` is the only authority for environment names and base URLs:

| Environment | Base URL |
|---|---|
| `test` | `https://test-stable.spx.vn/` |
| `production` | `https://spx.vn/` |

Unknown values fail closed. There is no admin-editable host, filter, request-supplied host, or cross-environment fallback. API paths are root-relative and must exactly match the six implemented SPX endpoints.

## Credential model

Credentials are isolated by environment. Server-managed constants have priority over encrypted WordPress options. Test uses `SPX_TEST_*`; production uses `SPX_PRODUCTION_*`, including production shop ID. A missing production value never falls back to test.

Option secrets are encrypted by `SPX_Secret_Storage`. The key is derived from WordPress salts and a plugin-specific context. Sodium secretbox is preferred; AES-256-GCM is the fallback. Payloads are versioned (`v1:sodium` or `v1:aesgcm`). Authentication, decoding, missing-primitive, or key-rotation failures return a controlled empty value and never expose plaintext, ciphertext, nonce, tag, or key.

Credential fingerprints are one-way HMAC digests over the normalized environment credential set. A changed fingerprint invalidates previous account verification.

## Production state machine

Allowed states are `disabled`, `readiness_pending`, `verified`, and `enabled`. In 6G-A, admin transitions are limited to `disabled` and `readiness_pending`. `verified` requires a successful, environment-specific Gate 6G-B record with a matching credential fingerprint. `enabled` additionally requires every hard readiness check. There is no offline or UI path that fabricates verification.

Readiness checks cover credentials, matching verification, sender profile, address dataset, HTTPS canonical endpoint, HPOS compatibility, Action Scheduler or WP-Cron availability, and declared WP/WC/PHP compatibility. Missing webhook and fixed-rate operation are explicit warnings, not hidden assumptions.

## Order environment and routing

New real SPX shipment attempts persist immutable `_spx_environment`. Existing `_spx_shipment_environment` remains readable and is normalized without changing protected history. Once an order has a valid environment, conflicting writes fail. Tracking, rate, shipment, label, and cancel dependencies are constructed for the selected environment. Production operations fail before transport unless the production gate allows that operation; Create remains unavailable in 6G-A.

Tracking batches contain one environment only. Scheduler groups and retry actions carry environment explicitly; mixed batches fail before API service invocation. Legacy test shipments continue to route to test.

## Admin and lifecycle

The WooCommerce SPX page owns production configuration. Writes require POST, `manage_woocommerce`, a nonce, and redirect-after-post. Secret inputs are blank/masked, blank preserves the stored value, and deletion requires an explicit checkbox. Constants render read-only and never render their values.

Activation/migration uses an idempotent schema version. Recognized legacy plaintext credentials migrate to the matching environment only, are encrypted before legacy deletion, and are never copied from test to production. A failed encryption leaves the source untouched. Deactivation only unschedules SPX jobs; uninstall preserves order history and credential/config data unless a future explicit data-erasure feature is approved.

## Threat model

- SSRF/host confusion: canonical fixed origins, HTTPS, port 443, no userinfo, no redirects, exact endpoint allowlist.
- Secret disclosure: encrypted-at-rest option fallback, masked UI, safe debug views, no body/header/raw response logging.
- Cross-environment shipment: immutable order binding, no credential fallback, environment-specific factories and scheduler groups.
- Accidental production activation: state machine plus matching online verification and readiness gate; 6G-A cannot reach verified/enabled.
- Replay/duplicate side effects: this phase performs no remote action; existing no-auto-retry create behavior is preserved.
- Privilege/CSRF: capability, POST, nonce, sanitization, and PRG.
- Migration loss: encrypt-then-delete, idempotent version marker, fail-safe retention.

## Verification strategy

Six plain-PHP suites establish RED first and then verify environment configuration, secret storage, production readiness, environment routing, production settings, and credential migration. Offline WordPress runtime tests use fake credentials and a transport deny policy. Browser smoke uses temporary isolated option data and restores the prior state. Full lint, unit, runtime, HPOS, UI, security, network, and log audits complete the gate.
