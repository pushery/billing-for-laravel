# Invariants

The package enforces a set of invariants **fail-closed**: when a precondition is not met, it refuses rather
than proceeding on a guess. Each throws a typed exception at the point of failure.

- **A webhook signing secret is required in production.** No secret → the package refuses to boot, so an
  unverified webhook is never accepted.
- **A metered tier needs a driver that can report usage.** A tier that bills for usage on a driver that
  cannot report it refuses to boot, rather than counting every unit and invoicing none.
- **The money-eligibility gate is fail-closed.** `CanTransactMoney` sits in front of every money-moving
  surface; an owner it cannot clear is refused, not charged.
- **Holding funds is gated.** `billing.marketplace.custody.platform_held` cannot be turned on by a flag alone
  — it requires a bound `PaymentServiceLicenseAttestation` (see [Security](security.md)).
- **A retention floor cannot be set below the statutory minimum** without an explicit jurisdiction opt-in.
- **An invoice correction carries positive amounts and, when it amends, an origin reference.** A negative
  amount or an amendment with no reference is refused at construction.
- **The audit ledger is append-only.** An ad-hoc update or delete throws; only the retention prune or an
  erasure request may remove a row.

When one of these fires, the [Troubleshooting](../guides/troubleshooting.md) guide maps the error message to
its cause.

---

[← Back to the documentation index](../README.md)
