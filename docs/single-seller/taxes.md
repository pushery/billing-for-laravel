# Taxes

Tax is applied through the `billing.tax` mode:

- **`provider`** — provider tax (Stripe Tax) is collected on the hosted checkout and flows onto the persisted
  invoice.
- **`eu_oss`** — a local EU One-Stop-Shop rate table computes the tax.
- **`none`** — no tax is applied.

The computation path is a seam: the package binds a `TaxCalculator` (the EU-OSS table, or none) so a local
computation can replace the provider path without touching the invoicing code.

## Reverse charge, only on a validated VAT id

Intra-EU B2B **reverse charge** is rendered as EN 16931 category `AE`. The reverse-charge zero rate is applied
only when both are true:

- the buyer's VAT id is **validated** — a `VatIdValidator` seam, with a VIES-backed implementation shipped
  and a null default that proves nothing offline; and
- the supply is **cross-border** — the buyer's country differs from `billing.company.country`.

So a fake id, a VIES outage, or a domestic sale is never wrongly zero-rated. A correction inherits the tax
treatment of the invoice it corrects, so a reverse-charge invoice's correction is itself category `AE`
(never the zero-rated `Z` a 0% rate would default to).

---

[← Back to the documentation index](../README.md)
