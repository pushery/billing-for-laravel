# Migrating from Cashier

Your billables already carry `stripe_id` — the very column this package reads. So after
[installing](../single-seller/installation.md), run one command to adopt every subscription you already have:

```bash
php artisan billing:sync
```

It pulls each existing customer's live subscription from Stripe and writes the local row and tier column.
Without it your paying customers stay invisible to the package until their next `customer.subscription.*`
webhook — which, on an untouched annual plan, could be a year away.

---

[← Back to the documentation index](../README.md)
