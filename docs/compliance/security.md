# Security

- A `billing.enabled` **master switch** makes the whole billing surface disappear, including Cashier's own
  routes.
- A **scoped Content-Security-Policy** covers the account hub.
- Card capture happens on the provider's own hosted page, so no card data ever touches your app — the
  integration stays within **PCI SAQ-A**.
- Every hub and admin screen is **fail-closed authorized**; the admin console is gated by an ability your app
  defines (see [Admin and support](../single-seller/admin-and-support.md)).
- The **webhook-secret guard** refuses to boot in production without a signing secret, so unverified webhooks
  are never accepted.

Report vulnerabilities privately through the [security policy](../../SECURITY.md) rather than opening a public
issue.

## When you need a payment-services or e-money license

This is engineering guidance, not legal advice — the rules differ by country and change, and the
responsibility for meeting them is yours. The package is built so that the safe path is the default and the
regulated path cannot be reached by accident:

- **The provider holds the money.** On a routed sale the payment provider holds the funds end to end; the
  platform never has other people's money on its own account. This is the shipped behavior and the only path
  the package implements.
- **Holding funds yourself is opt-in and gated.** `billing.marketplace.custody.platform_held` is `false` by
  default, and setting it `true` refuses to boot unless you bind a `PaymentServiceLicenseAttestation` — the
  deliberate, code-level act of declaring that you hold the required license. Holding funds on your own
  account is a regulated activity in most jurisdictions, so the package will not let a flag alone turn it on.
- **No withdrawable or transferable balance.** The credit ledger records a claim against future invoices, not
  a store of value: it has no method that pays a balance out and none that moves it between owners, and a test
  keeps it that way. A balance that can be recharged, withdrawn, and transferred is e-money almost everywhere,
  and the package does not ship one.
- **No interest on held funds.** There is no yield or interest option; paying interest on customer funds is a
  further regulated activity the package does not offer.

If your product needs any of these, that is a decision to take with a professional who knows your
jurisdiction — the guards mark the line, they do not clear it for you.

---

[← Back to the documentation index](../README.md)
