# SPX shipping-only checkout address design (Phase 6M)

## Scope and invariants

Add one separate "Địa chỉ giao hàng SPX" selector to Classic Checkout and Checkout Blocks. It contains province, district, and ward selects backed only by the locally imported SPX dataset. It is shipping-only: it never renders a second billing selector and never overwrites WooCommerce `address_1` or `address_2`.

The selector is required only when the cart needs shipping and the chosen package rate is `spx_express`. Local pickup, non-SPX rates, and fully virtual carts bypass it. Checkout performs no request to any external SPX endpoint. Existing shipment, tracking, mock-provider, order totals, shipping totals, and order statuses remain unchanged.

## Architecture

`SPX_Checkout_Address_Service` is the shared domain boundary. Classic PHP, the REST controller, Store API handling, resolver, and tests all call it. It exposes allowlisted province/district/ward view models and validates identifiers against the canonical current dataset. The browser never supplies trusted names or capability flags.

`SPX_Checkout_Address_Snapshot` owns HPOS-safe order metadata and customer/session prefill. Its complete snapshot is canonical names plus identifiers, source, dataset version, delivery/COD capability, and validation time. The resolver precedence becomes:

1. valid admin override;
2. valid checkout snapshot for the current dataset;
3. existing unique matching from standard WooCommerce address fields;
4. unresolved/not ready.

The public read-only REST namespace `spx-express/v1` exposes three GET routes: provinces, districts, and wards. Responses are minimal and cached by dataset version. Identifiers have strict length/character validation. No raw dataset rows, personal data, signatures, credentials, or external API responses cross this boundary.

Classic Checkout renders once near the shipping/order section, refreshes eligibility after WooCommerce checkout updates, validates posted IDs server-side, and persists through `woocommerce_checkout_create_order` using `WC_Order` CRUD.

Checkout Blocks uses a registered WooCommerce Blocks integration plus a custom inner block under the shipping-address area. It sends only IDs and dataset version with `setExtensionData`. ExtendSchema declares the checkout extension fields; `woocommerce_store_api_checkout_update_order_from_request` repeats eligibility and canonical validation before writing the order snapshot. It does not use additional checkout fields with `location=address`, because WooCommerce intentionally renders those for both shipping and billing.

## Prefill

Priority is checkout session, saved customer SPX selection, a complete unique match from the standard shipping address, then blank. Billing data is not used to create a shipping-only selection. A stale dataset version is never silently accepted; the current hierarchy is revalidated and the user must choose again when necessary.

## Validation and COD

Server-side validation is authoritative. It checks current dataset version, province/district/ward hierarchy, `Status=Available`, delivery support, and COD support when the chosen payment method is COD. Client-side checks exist only for immediate feedback. Validation failures prevent order creation and use Vietnamese, escaped, actionable messages.

## State and lifecycle

Guest state lives in the WooCommerce session and is copied to the created order. Logged-in customers additionally receive a reusable SPX selection after a successful checkout update. Order writes are made only on the in-memory `WC_Order` passed by WooCommerce; WooCommerce owns the final save, avoiding duplicate order writes.

Standard Woo province/city values are preserved. Phase 6M deliberately does not synchronize them because exact lossless equivalence between WooCommerce state/city values and SPX province/district identifiers is not guaranteed.

## Admin display

The existing SPX address panel shows the effective source, checkout snapshot, canonical hierarchy, capabilities, dataset version, and validation time. Existing admin override remains authoritative and reversible by clearing it. No shipment action is triggered.

## Security and privacy

Trust boundaries are public REST query parameters, Classic form POST, Store API extension data, WooCommerce session/customer metadata, order metadata, and the imported canonical dataset. Controls include bounded allowlisted identifiers, canonical server lookup, method allowlisting through GET-only routes, nonce/session protections supplied by WooCommerce checkout, escaped output, no client-trusted labels or capabilities, WC CRUD, and no custom permissive CORS headers. Logs contain no request payloads, full address, phone, secrets, or signatures.

## Verification

Unit and runtime tests cover the service, REST endpoints, eligibility, hierarchy/capability failures, Classic validation and persistence, Blocks schema and persistence, prefill priority, resolver precedence, stale versions, duplicate prevention, guest/session behavior, HPOS persistence, and the absence of external SPX calls. Browser tests create real local WooCommerce test orders through both Classic and Blocks and capture desktop/mobile UI evidence without touching protected orders 167 or 208.

## Official references

- WooCommerce Additional Checkout Fields documents that `location=address` appears in both billing and shipping, so it cannot implement shipping-only UI: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/
- WooCommerce custom Checkout inner blocks and extension data: https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-custom-fields/
- WooCommerce Blocks integration contract: https://developer.woocommerce.com/docs/block-development/reference/integration-interface/
- WooCommerce Store API schema extension: https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/
- WooCommerce HPOS requires order CRUD APIs: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/
