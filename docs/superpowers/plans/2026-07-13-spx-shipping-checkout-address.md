# Phase 6M implementation plan

1. Add failing, network-free unit tests for canonical address view models, hierarchy/status/delivery/COD validation, dataset-version handling, and SPX shipping eligibility.
2. Implement the shared checkout address service and HPOS-safe snapshot model; make the existing resolver honor admin override before a valid checkout snapshot.
3. Add failing runtime tests for public GET-only REST routes, malformed IDs, minimal responses, Classic validation/persistence, prefill, and protected-order invariants.
4. Implement the REST controller and Classic Checkout renderer, local script/style, authoritative validation, session/customer prefill, and CRUD persistence.
5. Add failing registration/schema tests for Checkout Blocks and Store API validation/persistence.
6. Implement a WooCommerce Blocks IntegrationInterface, custom shipping-address inner block, editor registration, extension data schema, and Store API order update handler. Build and lint the JavaScript bundle.
7. Extend the admin address panel with effective-source and snapshot diagnostics; update readiness and mapper tests for precedence and stale/capability failures.
8. Update README and API analysis with scope, operation, privacy, and the standard-address synchronization limitation.
9. Run PHP 7.4 lint, all existing unit/runtime suites, new Phase 6M suites, security scans, HPOS/unsynced checks, logs review, and explicit checks that protected orders 167/208 are unchanged.
10. Use a real browser to complete Classic and Blocks checkout as guest/logged-in test cases, verify responsive behavior and failure paths, create only new local WooCommerce test orders, and capture screenshots/results.
11. Review the complete diff and verification evidence. Report PASS only for demonstrated Phase 6M outcomes, then stop and suggest—not start—Phase 6I.
