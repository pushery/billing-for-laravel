# Retention and erasure

The package keeps exactly what a subject-access request and an erasure request need, and no more. The two
commands are covered operationally in [Data protection](../single-seller/data-protection.md); this page is the
matrix.

| Data | On erasure (`billing:erase`) | Retention window |
|---|---|---|
| Subscriptions, usage counters, credit balances | deleted | — |
| Stored provider API keys owned by the owner | deleted | — |
| Personal data inside webhook payloads | scrubbed | payloads aged out after `billing.retention.webhook_payload_days` (default 90) |
| Owed credit balance | banked to the audit ledger, then purged | audit window below |
| Invoices (`InvoiceRecord`) | **retained**, unlinked from the owner | `billing.retention.erased_financial_days` (default eight years, § 147 AO / § 14b UStG) |
| Audit ledger | retained | `billing.retention.audit_days` (default ten years) |

**Why invoices stay.** A valid invoice has to carry the buyer's name and address (§ 14 UStG), and invoices
have to be kept for years — so the right to erasure yields to a legal retention obligation (Art. 17(3)(b)).
The rows are unlinked from the owner and kept; `billing:prune` removes them once the retention window closes.

The retention floors cannot be set below the statutory minimum without an explicit jurisdiction opt-in — a
fail-closed guard, see [Invariants](invariants.md). Weigh the default windows against GDPR storage limitation
for your own case and jurisdiction.

---

[← Back to the documentation index](../README.md)
