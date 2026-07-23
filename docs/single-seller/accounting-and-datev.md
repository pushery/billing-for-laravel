# Accounting and DATEV

`billing:datev:export` writes a period of issued invoices as a DATEV EXTF "Buchungsstapel" (the booking batch
a German tax advisor imports). With no `--from`/`--to` it exports the previous calendar month, so a monthly
cron hands last month's bookings to the Steuerberater:

```bash
php artisan billing:datev:export    # a period of invoices as a DATEV EXTF booking batch (defaults to last month)
```

The account numbers come from `config('billing.datev')` and must be confirmed with the advisor — left unset
the file is structurally valid with blank account fields. Each business transaction resolves to the account
that carries its own tax logic through a `DatevAccountResolver`, so a fan-revenue rate, an OSS country or a
§13b input each land on their own account rather than one revenue account for everything. With no chart
selected the export is the single-seller revenue account, byte-for-byte unchanged.

The EXTF header's field 21 (`Festschreibekennzeichen`) is emitted as `1`, marking each booking batch as
locked against alteration after import, as GoBD requires. A correction is booked as a Haben (credit) against
the invoice it corrects; see [Invoices and e-invoicing](invoices-and-e-invoicing.md) for the document side.

---

[← Back to the documentation index](../README.md)
