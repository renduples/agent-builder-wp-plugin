# Storefront Assistant

| Field | Value |
|-------|-------|
| Slug | `storefront-assistant` |
| Version | 1.0.1 |
| Category | Ecommerce |

Bundled Agent Builder agent. Helps a visitor browse a WooCommerce catalog and build a cart, both in wp-admin chat and — its main purpose — directly in the browser via the WebMCP Bridge, so an AI browser agent visiting the storefront can shop on a visitor's behalf. Every tool it holds is scoped to the calling visitor's own session; none of them can see or change another visitor's data.

Deliberately does not place orders or take payment. Checkout is a separate, much more security-sensitive surface (order creation semantics, payment-failure states, PCI scope) that this agent intentionally leaves to the store's own checkout page — see `wc_view_cart`'s `checkout_url`. See `docs/commerce-readiness-brief.md` for the fuller design rationale.

See `agent.json` and `abilities.json` for tools and risk tiers.
