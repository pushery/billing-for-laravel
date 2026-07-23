<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\DatevAccountResolver;
use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\Models\InvoiceRecord;

/**
 * Exports invoices as a DATEV "Buchungsstapel" (EXTF) file — the booking batch a German tax advisor
 * imports. It writes the 31-field EXTF header, the column captions and one revenue booking per invoice
 * (gross amount, debit marker, the configured receivables/revenue accounts, document date and number).
 *
 * The account numbers, account length and any tax key are specific to a chart of accounts, so they are
 * read from config('billing.datev') and must be confirmed with the Steuerberater — left empty, the file
 * is still structurally valid with blank account fields to fill in. This produces the plain XML/CSV
 * baseline; it does not post anything itself.
 */
final readonly class DatevExport
{
    /** @var list<string> */
    private const array CAPTIONS = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz',
        'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schluessel)', 'BU-Schluessel', 'Belegdatum',
        'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
    ];

    public function __construct(
        private Repository $config,
        private DatevAccountResolver $accounts,
    ) {}

    /**
     * @param  iterable<InvoiceRecord>  $invoices
     */
    public function export(iterable $invoices, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $generatedAt = null): string
    {
        $rows = [
            $this->header($from, $to, $generatedAt ?? Carbon::now()),
            implode(';', array_map($this->quote(...), self::CAPTIONS)),
        ];

        foreach ($invoices as $invoice) {
            $rows[] = $this->booking($invoice);
        }

        return implode("\r\n", $rows)."\r\n";
    }

    private function header(CarbonInterface $from, CarbonInterface $to, CarbonInterface $generatedAt): string
    {
        return implode(';', [
            $this->quote('EXTF'), '700', '21', $this->quote('Buchungsstapel'), '13',
            $generatedAt->format('YmdHis').'000',
            '', $this->quote(''), $this->quote(''), $this->quote(''),
            $this->number('consultant'),
            $this->number('client'),
            $from->copy()->startOfYear()->format('Ymd'),
            (string) $this->accountLength(),
            $from->format('Ymd'),
            $to->format('Ymd'),
            $this->quote('Billing'),
            // Fields 19–22: Buchungstyp (1 = Finanzbuchführung), Rechnungslegungszweck (0 = unabhängig),
            // Festschreibekennzeichen, WKZ. Field 21 is written as "1" — festgeschrieben. A batch exported
            // as "0" stays alterable after import, which is what GoBD does not permit; the flag is a
            // property of the exported batch, not a preference, so it is not configurable.
            '', '1', '0', '1', $this->quote('EUR'),
            '', '', '', '', '', '', '', '', '',
        ]);
    }

    private function booking(InvoiceRecord $invoice): string
    {
        $reference = $invoice->number ?? (string) $invoice->id;
        $date = $invoice->issued_at ?? $invoice->created_at ?? Carbon::now();

        // Direction lives ONLY in the Soll/Haben marker — never the amount (field 1 is the UNSIGNED
        // magnitude, "Umsatz OHNE Soll/Haben-Kz"; a leading minus is rejected on import). A normal invoice
        // debits receivables ("S"); a credit note reverses to "H". So does a genuinely NEGATIVE invoice — a
        // downgrade proration credit finalizes as a regular negative-total invoice (no credit-note link),
        // which is economically a revenue reduction and must book "H" too, or turnover is overstated. The
        // two ways of being a credit XOR: a negative credit note is a debit again.
        // XOR via !== (the `xor` keyword binds looser than `=` — a classic precedence trap).
        $isCredit = $invoice->isCorrection() !== ($invoice->total_minor < 0);
        $marker = $isCredit ? 'H' : 'S';

        // The revenue account (Gegenkonto) comes from the account resolver — the export names the business
        // transaction (fan revenue) and gets an account back, rather than reading a config field itself. With
        // no chart of accounts selected this resolves to the single-seller revenue_account, so the output is
        // byte-for-byte unchanged. The Konto (customer/receivables) stays the configured customer account
        // (the person-account model is a separate concern).
        //
        // The BU-Schlüssel (field 9) stays empty: the revenue account is an Automatikkonto, which derives its
        // VAT from the posting, and setting a BU-Schlüssel would cancel that — the classic import error.
        $revenue = $this->accounts->resolve(DatevTransaction::FanRevenueStandard);

        return implode(';', [
            $this->amount($invoice),
            $this->quote($marker),
            $this->quote($invoice->currency),
            '', '', '',
            $this->number('customer_account'),
            $revenue->number,
            '',
            $date->format('dm'),
            $this->quote($reference),
            '', '',
            $this->quote('Rechnung '.$reference),
        ]);
    }

    /**
     * The gross total's UNSIGNED magnitude as a DATEV decimal (comma separator, no thousands), e.g.
     * "119,00". The sign never appears here — direction is the Soll/Haben marker's job (see booking()) —
     * so a negative-total invoice books its magnitude with "H", not a minus DATEV would reject.
     */
    private function amount(InvoiceRecord $invoice): string
    {
        return str_replace('.', ',', $invoice->total()->absolute()->toDecimal());
    }

    /** A configured DATEV number field as a plain string, or empty when unset. */
    private function number(string $key): string
    {
        $value = $this->datev()[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function accountLength(): int
    {
        $length = $this->datev()['account_length'] ?? 4;

        return is_int($length) ? $length : 4;
    }

    /** @return array<array-key, mixed> */
    private function datev(): array
    {
        $value = $this->config->get('billing.datev');

        return is_array($value) ? $value : [];
    }

    /** Quote a text field and escape embedded quotes the DATEV way (doubling). */
    private function quote(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
