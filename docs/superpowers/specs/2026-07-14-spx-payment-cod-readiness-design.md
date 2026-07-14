# SPX Payment/COD Readiness Design

## Scope

PHASE 6G-B0.5 introduces one canonical WooCommerce payment resolver for shipment readiness and SPX Create input. It does not call SPX, change an order status, call `payment_complete()`, or mutate WooCommerce order/shipping totals.

## Decision Contract

`SPX_Payment_Resolver::resolve( WC_Order $order ): array` returns:

- `payment_method` — normalized WooCommerce gateway code;
- `is_paid` and `needs_payment` — booleans read from `WC_Order`;
- `payment_state` — `blocked`, `paid`, `cash_on_delivery`, `missing`, `unconfirmed`, or `invalid`;
- `cod_amount` — integer VND or `null`;
- `ready_for_shipment` — boolean;
- `reason` — stable machine code or an empty string.

Precedence:

1. `cancelled`, `failed`, or `refunded` is blocked with `order_status_blocked`.
2. A paid order is ready with `payment_state=paid` and COD `0`, provided its total is numeric and non-negative.
3. An unpaid COD order is ready with COD equal to its valid rounded order total.
4. An unpaid order without a payment method is blocked with `payment_method_missing` and COD `null`.
5. An unpaid non-COD order is blocked with `payment_not_confirmed` and COD `null`.
6. A total that is `null`, negative, non-numeric, NaN, or infinite is fail-closed with `invalid_order_total` whenever a branch would otherwise become shipment-ready.

No partial-payment remainder is inferred. Without an official confirmed paid-amount source, an unpaid non-COD order remains blocked and an unpaid COD order uses the complete valid order total.

## Data Flow and Enforcement

`WC_Order → SPX_Payment_Resolver → SPX_Order_Mapper → SPX_Shipment_Readiness → SPX_Shipment_Service → SPX_Create_Request_Mapper`

- `SPX_Order_Mapper` copies the resolver result; it does not infer COD.
- `SPX_Shipment_Readiness` consumes canonical payment state and adds the corresponding user-facing block reason.
- `SPX_Admin_Shipment` and the canonical metabox use the same resolver result.
- `SPX_Shipment_Service` preserves `null`, rejects non-ready payment state before mapping, and makes zero HTTP calls.
- `SPX_Create_Request_Mapper` never falls back a missing/invalid COD amount to zero.
- The legacy direct order action also checks the canonical readiness state before any provider call.

## Admin Presentation

The SPX order metabox adds a payment section with the gateway title/code, paid state, SPX COD amount, WooCommerce shipping total, and the canonical blocking message. All values are escaped; raw gateway metadata is never rendered.

## Security and Safety

- No request reaches an SPX endpoint during implementation or verification.
- Payment and order values are treated as untrusted input and validated fail-closed.
- No credentials, signatures, raw order objects, phone numbers, or full addresses are emitted.
- Tests assert order status, order total, and shipping total remain unchanged.
- Prior browser-tool exposure remains documented; only the new execution window and current artifacts may be declared clean.

## Verification

RED evidence must precede production edits. Green verification covers resolver decisions, direct Create blocking, Create mapper fail-closed behavior, metabox consistency, order #642 read-only readiness, PHP 7.4/8.2 suites, HPOS, Classic/Blocks, rate regressions, security scans, and zero external SPX requests.
