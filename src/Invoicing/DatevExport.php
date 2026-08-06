<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\DatevAccountResolver;
use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\Enums\ExchangeRateLayer;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\VoucherEvent;
use Pushery\Billing\Exceptions\InvalidDatevBatch;
use Pushery\Billing\Marketplace\MerchantLiabilityAccounts;
use Pushery\Billing\Models\InvoiceExchangeRate;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\ProviderFee;
use Pushery\Billing\Tax\FrozenExchangeRate;
use Pushery\Billing\Tax\UnionMembership;
use Pushery\Billing\ValueObjects\DatevAccount;
use Pushery\Billing\ValueObjects\VoucherMovement;

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
    /** How many characters a document-reference field carries, per the format description (field 11). */
    private const int REFERENCE_LENGTH = 36;

    /** How many characters the booking text carries, per the format description (field 14). */
    private const int TEXT_LENGTH = 60;

    /**
     * The narrow record: the leading fields every booking has always emitted.
     *
     * Trailing fields may be left off entirely, so this is the shape a batch keeps unless a row in it needs a
     * column further right — which is what makes the wider record additive rather than a format change.
     */
    private const int NARROW_WIDTH = 14;

    /** The field carrying the reverse-charge transaction key (1-based), and the rate that goes with it. */
    private const int TRANSACTION_KEY_FIELD = 43;

    /**
     * The column captions, in field order, as far as this exporter can write.
     *
     * Only the first {@see width()} of them reach the file — the caption row declares exactly the columns the
     * bookings carry, no more.
     *
     * @var list<string>
     */
    private const array CAPTIONS = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basis-Umsatz',
        'WKZ Basis-Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schluessel)', 'BU-Schluessel', 'Belegdatum',
        'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext',
        'Postensperre', 'Diverse Adressnummer', 'Geschaeftspartnerbank', 'Sachverhalt', 'Zinssperre',
        'Beleglink',
        'Beleginfo - Art 1', 'Beleginfo - Inhalt 1', 'Beleginfo - Art 2', 'Beleginfo - Inhalt 2',
        'Beleginfo - Art 3', 'Beleginfo - Inhalt 3', 'Beleginfo - Art 4', 'Beleginfo - Inhalt 4',
        'Beleginfo - Art 5', 'Beleginfo - Inhalt 5', 'Beleginfo - Art 6', 'Beleginfo - Inhalt 6',
        'Beleginfo - Art 7', 'Beleginfo - Inhalt 7', 'Beleginfo - Art 8', 'Beleginfo - Inhalt 8',
        'KOST1 - Kostenstelle', 'KOST2 - Kostenstelle', 'Kost-Menge', 'EU-Land u. UStID', 'EU-Steuersatz',
        'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergaenzung L+L',
    ];

    public function __construct(
        private Repository $config,
        private DatevAccountResolver $accounts,
        private MerchantLiabilityAccounts $liabilities,
    ) {}

    /**
     * @param  iterable<InvoiceRecord>  $invoices  the documents of the period
     * @param  iterable<ProviderFee>  $providerFees  what the provider charged in the period — appended, and
     *                                               empty by default, so an existing call produces the same
     *                                               batch byte for byte
     * @param  iterable<VoucherMovement>  $voucherMovements  what happened to vouchers in the period, likewise
     *                                                       appended and empty by default
     */
    public function export(iterable $invoices, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $generatedAt = null, iterable $providerFees = [], iterable $voucherMovements = []): string
    {
        $this->assertSinglePostingPeriod($from, $to);

        /** @var list<list<string>> $rows */
        $rows = [];

        foreach ($invoices as $invoice) {
            // A self-billed document a creator has objected to loses its effect as an invoice from the taxation
            // period of the objection forward, so from that period on the platform draws no input tax from it:
            // it is left out of the batch entirely (the whole booking of a settlement IS its input). A period
            // that ends before the objection is untouched — the objection is ex nunc, so the original booking
            // stands and only the current and following periods drop it.
            if ($invoice->invoiceEffectVoidForPeriod($from)) {
                continue;
            }

            foreach ($this->bookingsFor($invoice) as $row) {
                $rows[] = $row;
            }
        }

        foreach ($providerFees as $fee) {
            $rows[] = $this->providerFeeBooking($fee);
        }

        foreach ($voucherMovements as $movement) {
            foreach ($this->voucherBookings($movement) as $row) {
                $rows[] = $row;
            }
        }

        $width = $this->width($rows);

        $lines = [
            $this->header($from, $to, $generatedAt ?? Carbon::now(), $this->batchCurrency()),
            implode(';', array_map($this->quote(...), array_slice(self::CAPTIONS, 0, $width))),
        ];

        foreach ($rows as $row) {
            $lines[] = implode(';', [...$row, ...array_fill(0, $width - count($row), '')]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * How wide the record is: as wide as the widest row in it, never narrower than the narrow record.
     *
     * The field set grows only when a booking actually needs a column further right — a reverse-charge
     * settlement carrying its transaction key. Everything else keeps the record it has always had, so a batch
     * with no such booking in it (every batch a single-seller install produces) is unchanged byte for byte.
     * Widening is not a per-row decision: every row in one file carries the same columns, or the caption row
     * describes only some of them.
     *
     * @param  list<list<string>>  $rows
     */
    private function width(array $rows): int
    {
        return array_reduce($rows, fn (int $carry, array $row): int => max($carry, count($row)), self::NARROW_WIDTH);
    }

    /**
     * One booking for what the payment provider charged.
     *
     * It debits the provider-fee account against money in transit — the fee is deducted from the money on
     * its way to the bank, which is why it looks like a bank charge and why booking it as one is the classic
     * finding: the fee account carries the tax treatment (an Automatikkonto that self-assesses and deducts
     * where the provider is established abroad), and the transit account carries none. The amounts are the
     * same either way; only the return differs, and only later.
     *
     * A dispute fee is not a separate transaction from an ordinary provider fee. It is the same service,
     * more expensive, so it books the same way — giving it a line of its own would be an invitation to give
     * it a different treatment too.
     *
     * @return list<string>
     */
    private function providerFeeBooking(ProviderFee $fee): array
    {
        return $this->chainRow(
            $fee->amount_minor,
            $fee->currency,
            $this->accounts->resolve(DatevTransaction::PspFee),
            $this->accounts->resolve(DatevTransaction::MoneyTransit)->number,
            $fee->occurred_at,
            // No document reference: that field is the open-item key and holds the platform's OWN document
            // number, which a provider fee has none of. The provider's charge id goes in the text instead,
            // where it is readable in full — the reference field permits neither the underscore nor the colon
            // that virtually every provider id is built from, so putting it there would mean mangling it
            // ourselves to satisfy a field it was never meant for.
            '',
            ['Provider-Gebuehr', $fee->reference],
        );
    }

    /**
     * The booking text, assembled from parts and kept inside the field.
     *
     * The parts are ordered by how much a reader loses without them, and one that does not fit is dropped
     * WHOLE rather than cut. A cut identifier is the dangerous outcome: it still looks like an identifier, so
     * it reads as a reference to something else, and the reconciliation it breaks gives no hint that anything
     * was shortened. An absent part is at least visibly absent — and the document reference is in its own
     * field regardless, so nothing that identifies the booking is only here.
     *
     * The last resort, a single part longer than the whole field, cuts from the right and keeps the start:
     * with everything else already dropped there is nothing left to give up.
     *
     * @param  list<string>  $parts
     */
    private function text(array $parts): string
    {
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        while (count($parts) > 1 && mb_strlen(implode(' ', $parts)) > self::TEXT_LENGTH) {
            array_pop($parts);
        }

        return mb_substr(implode(' ', $parts), 0, self::TEXT_LENGTH);
    }

    /**
     * Which creator a booking belongs to, as the identifier the platform holds — never a name.
     *
     * The batch reaches a tax advisor, and a creator's identity there is the account it settles with, not a
     * person. A name in a booking text would also be a fan's name on the buyer side of the same file, which
     * is the leak the disclosure guard exists to prevent.
     */
    private function creatorTag(InvoiceRecord $invoice): string
    {
        return $invoice->settlement_document_type instanceof SettlementDocumentType
            ? 'CR-'.$invoice->owner_id
            : '';
    }

    /** The document a correction corrects, so the text says what the amount undoes. */
    private function correctsTag(InvoiceRecord $invoice): string
    {
        $corrected = $invoice->credited_invoice_number;

        return is_string($corrected) && $corrected !== '' ? 'zu '.$corrected : '';
    }

    /**
     * The due date as the field carries it, or empty when the document states none.
     *
     * Six digits, day-month-year — the form the import reads as a payment-processing date. The field is
     * shared: it carries either a second document number or this date, and the package reserves it for the
     * date. That reservation is worth guarding, because the tempting misuse is to park some other short
     * identifier here, which the import would then read as a document number and quietly settle the wrong
     * open item with.
     *
     * The guard is {@see InvalidDatevBatch::DUE_DATE_PATTERN}, asserted over every row of an emitted batch
     * rather than branched on here. A runtime check would be dead weight — the only thing that reaches this
     * field is a formatter, so it cannot fail at runtime; what it can fail at is a later edit, and that is
     * caught where the whole file is read, not where one value is written.
     */
    private function dueDateField(InvoiceRecord $invoice): string
    {
        $due = $invoice->due_at;

        return $due instanceof CarbonInterface ? $due->format('dmy') : '';
    }

    /**
     * What a voucher event books.
     *
     * Issue takes money against a promise — money in transit against a liability, and NO tax, because nothing
     * has been supplied yet and neither the place nor the rate is known. Redemption is the supply: the whole
     * sale goes to revenue, and the debit side is SPLIT between what the buyer paid now and what the
     * liability settles. Expiry keeps money for a supply that never happened, so it is income, not turnover.
     *
     * The split is what the rule turns on. Booking only the cash side would make the sale smaller by the
     * voucher — a voucher is a way of PAYING, never a discount on what was sold, and a batch that treats it
     * as one understates turnover by exactly the voucher's face value, every time.
     *
     * @return list<list<string>>
     */
    private function voucherBookings(VoucherMovement $movement): array
    {
        $liabilities = $this->accounts->resolve(DatevTransaction::VoucherLiabilities);
        $transit = $this->accounts->resolve(DatevTransaction::MoneyTransit);
        $reference = $movement->reference;

        return match ($movement->event) {
            VoucherEvent::Issued => [$this->chainRow(
                $movement->amount->minorUnits, $movement->amount->currency, $transit, $liabilities->number,
                $movement->occurredOn, $reference, ['Gutschein-Ausgabe', $reference],
            )],
            VoucherEvent::Redeemed => $this->redemptionBookings($movement, $liabilities, $transit),
            VoucherEvent::Expired => [$this->chainRow(
                $movement->amount->minorUnits, $movement->amount->currency, $liabilities,
                $this->accounts->resolve(DatevTransaction::OtherIncome)->number,
                $movement->occurredOn, $reference, ['Gutschein-Verfall', $reference],
            )],
        };
    }

    /**
     * The two rows a redemption produces — the cash part and the voucher part, both against revenue.
     *
     * Two rows rather than one because a booking row names one account and one contra account; a split debit
     * has no other shape. A voucher that covered the whole sale produces only the voucher row: a zero-amount
     * cash row would state a payment that never happened.
     *
     * @return list<list<string>>
     */
    private function redemptionBookings(VoucherMovement $movement, DatevAccount $liabilities, DatevAccount $transit): array
    {
        $currency = $movement->amount->currency;
        $revenue = $this->accounts->resolve(DatevTransaction::FanRevenueStandard)->number;
        $paidOnTop = $movement->paidOnTop();
        $rows = [];

        if (! $paidOnTop->isZero()) {
            $rows[] = $this->chainRow(
                $paidOnTop->minorUnits, $currency, $transit, $revenue,
                $movement->occurredOn, $movement->reference, ['Gutschein-Einloesung', $movement->reference],
            );
        }

        $rows[] = $this->chainRow(
            $movement->amount->minorUnits, $currency, $liabilities, $revenue,
            $movement->occurredOn, $movement->reference, ['Gutschein-Einloesung', $movement->reference],
        );

        return $rows;
    }

    /**
     * A document reference, checked against what the field can actually carry.
     *
     * Refused rather than trimmed. The import accepts a shortened or mangled reference without complaint,
     * and the booking then points at a document nobody can find — which surfaces as a reconciliation that
     * does not close, months later, with nothing to point at. A reference the package mints fits comfortably;
     * the check exists because a consumer configures its prefix.
     */
    private function documentField(string $reference): string
    {
        if (mb_strlen($reference) > self::REFERENCE_LENGTH) {
            throw InvalidDatevBatch::referenceTooLong($reference, self::REFERENCE_LENGTH);
        }

        // Delimited with ~ rather than / because the permitted set itself contains a slash, which would
        // close the pattern early and turn a validation into a syntax error nobody sees until it runs.
        if (preg_match('~^['.InvalidDatevBatch::REFERENCE_ALPHABET.']*$~', $reference) !== 1) {
            throw InvalidDatevBatch::referenceHasForbiddenCharacter($reference);
        }

        return $reference;
    }

    /**
     * Refuse a batch that covers more than one posting period.
     *
     * A batch IS a period: the header states the range, and the import posts the whole file into it. A range
     * crossing a month boundary therefore lands part of itself in the wrong month — accepted whole, with
     * nothing anywhere saying so.
     */
    private function assertSinglePostingPeriod(CarbonInterface $from, CarbonInterface $to): void
    {
        if ($from->format('Ym') !== $to->format('Ym')) {
            throw InvalidDatevBatch::spansPostingPeriods($from->format('Y-m-d'), $to->format('Y-m-d'));
        }
    }

    /**
     * The currency this batch is declared in.
     *
     * Taken from configuration rather than from the rows, and deliberately: a batch header states ONE
     * currency, so deriving it from the rows would silently pick whichever document happened to come first
     * in a mixed export. The configured company currency is the answer that is at least stable and
     * inspectable — and a genuinely mixed batch is a question for the exporter's caller, not something a
     * header field can resolve.
     */
    private function batchCurrency(): string
    {
        $currency = $this->config->get('billing.currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR';
    }

    private function header(CarbonInterface $from, CarbonInterface $to, CarbonInterface $generatedAt, string $currency): string
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
            //
            // Field 22 used to be a hard-coded 'EUR'. Every booking row already carries its document's own
            // currency, so an installation invoicing in anything else exported rows in one currency under a
            // header declaring another — internally contradictory, and the sort of thing an importer either
            // rejects or, worse, silently believes.
            '', '1', '0', '1', $this->quote($currency),
            '', '', '', '', '', '', '', '', '',
        ]);
    }

    /**
     * The booking row(s) a document produces.
     *
     * A self-billed settlement that carries its frozen fan gross is a commission-chain transaction and books
     * the full THREE-part chain — the fan sale, the creator input, the payout — because those three legs
     * belong to one transaction and only together do the margin and the VAT liability reconcile. Everything
     * else — a fan invoice, a single-seller invoice, a settlement issued before this chain existed — books the
     * single row it always has, so the shipped export stays byte-identical.
     *
     * @return list<list<string>>
     */
    private function bookingsFor(InvoiceRecord $invoice): array
    {
        if ($invoice->settlement_document_type instanceof SettlementDocumentType && $invoice->fan_gross_minor !== null) {
            return $this->settlementChain($invoice);
        }

        return [$this->booking($invoice)];
    }

    /** @return list<string> */
    private function booking(InvoiceRecord $invoice): array
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

        // Konto (field 7) and Gegenkonto (field 8) are resolved from the document's ROLE, not read as fixed
        // config: a fan invoice debits receivables against fan revenue, a self-billed settlement debits the
        // creator's input account against the creator-liabilities account. The BU-Schlüssel (field 9) stays
        // empty either way — every account these resolve to is an Automatikkonto that derives its VAT from the
        // posting, and setting a BU-Schlüssel would cancel that (the classic import error). Position is fixed;
        // only these two values change with the role.
        [$konto, $gegenkonto] = $this->accountsFor($invoice);

        return $this->row(
            $this->amount($invoice),
            $marker,
            $invoice->currency,
            $konto->number,
            $gegenkonto,
            $date,
            $reference,
            $this->text([
                // What the row IS, from the document's role — a settlement is a credit note the platform
                // wrote to itself, and calling it an invoice in the one field a human reads is how it gets
                // taken for one.
                $invoice->settlement_document_type instanceof SettlementDocumentType ? 'Gutschrift' : 'Rechnung',
                $reference,
                $this->creatorTag($invoice),
                $this->correctsTag($invoice),
            ]),
            $this->dueDateField($invoice),
            $this->transactionKeyOf($invoice, $konto),
            $this->documentRate($invoice),
        );
    }

    /**
     * One booking row as its fields, in order.
     *
     * The row is narrow unless it carries a reverse-charge transaction key, which sits far to the right: only
     * then does it reach that far, and only then does the whole batch widen with it.
     *
     * @param  array{0: int, 1: int}|null  $transactionKey  the key and the rate that qualifies it
     * @return list<string>
     */
    private function row(
        string $amount,
        string $marker,
        string $currency,
        string $konto,
        string $gegenkonto,
        CarbonInterface $date,
        string $reference,
        string $text,
        string $dueDate = '',
        ?array $transactionKey = null,
        ?FrozenExchangeRate $rate = null,
    ): array {
        $row = [
            $amount,
            $this->quote($marker),
            $this->quote($currency),
            // Fields 4-6: Kurs, Basis-Umsatz, WKZ Basis-Umsatz.
            //
            // DATEV's rule is that a row whose WKZ Umsatz differs from the batch's base currency must carry
            // the RATE or the base amount. Carrying neither is not a formatting lapse: the row is either
            // rejected outright, or booked at face value into a base-currency account — 500,00 PLN posted as
            // 500,00 EUR, which overstates the revenue by a factor of four and looks like a plausible number
            // all the way through.
            //
            // THE RATE, not the base amount, and that is a decision rather than a preference. The rate is
            // the value frozen on the document, so writing it is a transcription; the base amount would have
            // to be COMPUTED here, and this package has no converter — nothing in `src/` multiplies an
            // amount by a rate today. Building the first one inside an export path would set the rounding
            // rule for every future conversion from the wrong place, and a second rounding rule is the
            // divergence this package keeps paying for. DATEV performs the multiplication itself.
            $rate instanceof FrozenExchangeRate ? $this->rateField($rate) : '',
            '', '',
            $konto,
            $gegenkonto,
            '',
            $date->format('dm'),
            $this->quote($this->documentField($reference)),
            // Bare when there is none, never an empty quoted string: the field has always gone out bare, and
            // a document that states no due date must produce the row it always did, down to the byte.
            $dueDate === '' ? '' : $this->quote($dueDate),
            '',
            $this->quote($text),
        ];

        if ($transactionKey === null) {
            return $row;
        }

        return [
            ...$row,
            ...array_fill(0, self::TRANSACTION_KEY_FIELD - 1 - count($row), ''),
            (string) $transactionKey[0],
            (string) $transactionKey[1],
        ];
    }

    /**
     * The reverse-charge transaction key a booking carries, with the rate that qualifies it.
     *
     * Only a reverse-charge input booking has one, and only where the chart declares it — the catalog of
     * keys belongs to a chart of accounts, so an install that never configured one keeps the record it has.
     * The rate travels with the key because the key alone does not say which rate the reverse charge is
     * assessed at, and the format keeps the two in adjacent fields for that reason.
     *
     * @return array{0: int, 1: int}|null
     */
    private function transactionKeyOf(InvoiceRecord $invoice, DatevAccount $account): ?array
    {
        if (! $invoice->reverse_charge || $account->reverseChargeTransactionKey === null) {
            return null;
        }

        // The rate as the field states it: hundredths of a percent become whole tenths of a percent, so 19%
        // is 190. A settlement with no frozen rate has nothing to qualify the key with, and a key without its
        // rate is refused by the import — so the pair stays out together.
        $rate = $invoice->tax_rate_bps;

        return $rate === null || $rate <= 0 ? null : [$account->reverseChargeTransactionKey, intdiv($rate, 10)];
    }

    /**
     * The three-part commission-chain booking of one settled transaction.
     *
     *   (1) fan sale    money-transit  an  fan revenue     — the fan's gross, output VAT on the revenue account
     *   (2) creator input  input account  an  creditor      — the payout GROSS; the input expense is its net
     *   (3) payout       creditor       an  money-transit   — the payout leaves the account
     *
     * The creditor nets to zero per transaction (+payout in, −payout out); the money-transit does NOT — it
     * keeps the margin plus the VAT liability, which is the point. Every row is a debit of its Konto ("S") in
     * the ordinary case; a correction reverses the whole chain (a general reversal), which is a separate
     * concern. All three carry the sale-month date (the two fictional supplies are simultaneous).
     *
     * @return list<list<string>>
     */
    private function settlementChain(InvoiceRecord $invoice): array
    {
        $reference = $invoice->number ?? (string) $invoice->id;
        $date = $invoice->issued_at ?? $invoice->created_at ?? Carbon::now();
        $currency = $invoice->currency;
        $payout = $invoice->total_minor;
        $creator = $this->creatorTag($invoice);
        $corrects = $this->correctsTag($invoice);

        // A correction reverses the WHOLE chain, every leg at once. Reversing one of them moves the margin
        // permanently: the sale comes back and the input stays, or the other way round, and the difference
        // sits in the books as a profit or a loss that nothing ever caused.
        $marker = $invoice->isCorrection() ? 'H' : 'S';

        $moneyTransit = $this->accounts->resolve(DatevTransaction::MoneyTransit);
        $fanRevenue = $this->fanRevenueAccount($invoice);
        $creatorInput = $this->accounts->resolve($this->creatorInputTransaction($invoice));
        $liabilities = $this->liabilityAccountFor($invoice);

        return [
            $this->chainRow((int) $invoice->fan_gross_minor, $currency, $moneyTransit, $fanRevenue->number, $date, $reference, ['Fan-Umsatz', $reference, $creator, $corrects], marker: $marker),
            // The creator-input leg is the one the reverse charge sits on: it is the platform's input side,
            // and the transaction key qualifies THAT booking, not the fan sale and not the payout.
            $this->chainRow($payout, $currency, $creatorInput, $liabilities, $date, $reference, ['Gutschrift', $reference, $creator, $corrects], $this->transactionKeyOf($invoice, $creatorInput), $marker),
            $this->chainRow($payout, $currency, new DatevAccount($liabilities), $moneyTransit->number, $date, $reference, ['Auszahlung', $reference, $creator, $corrects], marker: $marker),
        ];
    }

    /**
     * Which account this merchant's payable books against — one collective account, or their own.
     *
     * The choice belongs to the installation, and it is read here rather than resolved once for the whole
     * batch because in the individual arrangement the answer differs per row.
     */
    private function liabilityAccountFor(InvoiceRecord $invoice): string
    {
        return $this->liabilities->forMerchant($invoice->owner_type, $invoice->owner_id);
    }

    /**
     * Which revenue account a sale books to.
     *
     * A sale taxed at the buyer's country goes to that country's own account: it carries foreign VAT, and on
     * the domestic revenue account an automatic posting would derive DOMESTIC tax from it — the right gross
     * under the wrong tax, which reconciles and is wrong. Otherwise the reduced rate for an e-book or
     * e-paper, else the standard one.
     *
     * Only where a chart of accounts is selected. Without one there is a single configured revenue account
     * and nothing to route to, which is what leaves the shipped single-seller export exactly as it was.
     */
    private function fanRevenueAccount(InvoiceRecord $invoice): DatevAccount
    {
        $country = $invoice->destination_country;

        if ((bool) $invoice->oss && is_string($country) && $country !== '' && $this->chartSelected()) {
            return $this->accounts->resolve(DatevTransaction::OssRevenue, $country);
        }

        return $this->accounts->resolve(
            $invoice->tax_rate_bps === 700 ? DatevTransaction::FanRevenueReduced : DatevTransaction::FanRevenueStandard
        );
    }

    /** Whether a chart of accounts is configured — the condition under which anything resolves per transaction. */
    private function chartSelected(): bool
    {
        $chart = $this->datev()['chart'] ?? null;

        return is_string($chart) && $chart !== '';
    }

    /**
     * One booking row at an arbitrary amount and account pair, on an Automatikkonto (empty BU).
     *
     * A debit of the Konto **in the ordinary case** — `$marker` defaults to `'S'` and most callers take it.
     * It is not always: `settlementChain()` computes `$invoice->isCorrection() ? 'H' : 'S'` and passes it to
     * all three legs, so every row of a correcting settlement is a CREDIT. This docblock used to say "always
     * a debit" with no qualifier, while `settlementChain()`'s own scoped the same statement correctly — the
     * direction of a booking is not a detail to be wrong about in prose.
     *
     * @param  list<string>  $textParts
     * @param  array{0: int, 1: int}|null  $transactionKey
     * @return list<string>
     */
    private function chainRow(int $amountMinor, string $currency, DatevAccount $konto, string $gegenkonto, CarbonInterface $date, string $reference, array $textParts, ?array $transactionKey = null, string $marker = 'S'): array
    {
        return $this->row(
            number_format(abs($amountMinor) / 100, 2, ',', ''),
            $marker,
            $currency,
            $konto->number,
            $gegenkonto,
            $date,
            $reference,
            $this->text($textParts),
            transactionKey: $transactionKey,
        );
    }

    /**
     * The Konto (field 7) and Gegenkonto (field 8) a document books to, from its frozen role.
     *
     * A fan invoice is unchanged: receivables (the configured customer account) against fan revenue, which
     * with no chart selected resolves to the single-seller revenue account — byte-for-byte as shipped. A
     * self-billed settlement (a Gutschrift or settlement note) is the platform's INPUT side: the creator's
     * input account (its VAT treatment carried by the account itself) against the collective creator-liability
     * account. The person-account model (a single collective vs. individual creditors) is a separate concern;
     * this books the collective account.
     *
     * @return array{0: DatevAccount, 1: string} [Konto, Gegenkonto]
     */
    private function accountsFor(InvoiceRecord $invoice): array
    {
        if (! $invoice->settlement_document_type instanceof SettlementDocumentType) {
            $revenue = $this->fanRevenueAccount($invoice);

            // A receivables account is not resolved from the chart — it is the configured customer account,
            // and it carries no reverse-charge key of its own: the fan side of a sale is never a reverse
            // charge to the platform.
            return [new DatevAccount($this->number('customer_account')), $revenue->number];
        }

        $input = $this->accounts->resolve($this->creatorInputTransaction($invoice));

        return [$input, $this->liabilityAccountFor($invoice)];
    }

    /**
     * Which input account a self-billed settlement books to, read from the frozen tax treatment.
     *
     * An exempt supply (a small business or private party) books to the tax-free input; a reverse-charge
     * supply to the §13b input, split by whether the creator is in the union (Abs. 1) or a third country
     * (Abs. 2) and by the rate; everything else is a standard-rated domestic input carrying 19% input VAT. A
     * reduced-rate domestic input has no confirmed account, so it stays unresolved and the export fails closed
     * rather than book it to the standard account — the deliberate refusal a wrong account would hide.
     *
     * That last sentence was a PROMISE for as long as it stood here, not a description: the method returned
     * CreatorInputDeStandard unconditionally, so a 7% domestic input booked to the 19% input-VAT account. It
     * had a test, and the test was green — it nulled the standard account and then observed a refusal, which
     * measures the missing account rather than the rate and would have passed with no reduced-rate branch at
     * all. The refusal is now carried by CreatorInputDeReduced, which ships unmapped on purpose.
     */
    private function creatorInputTransaction(InvoiceRecord $invoice): DatevTransaction
    {
        if ($invoice->tax_exempt) {
            return DatevTransaction::CreatorInputExempt;
        }

        if ($invoice->reverse_charge) {
            $reduced = $invoice->tax_rate_bps === 700;
            $seller = $invoice->getAttribute('seller');
            $country = is_array($seller) && is_string($seller['country'] ?? null) ? $seller['country'] : null;

            if (UnionMembership::isMember($country)) {
                return $reduced
                    ? DatevTransaction::CreatorInputEuReverseChargeReduced
                    : DatevTransaction::CreatorInputEuReverseCharge;
            }

            return $reduced
                ? DatevTransaction::CreatorInputThirdCountryReverseChargeReduced
                : DatevTransaction::CreatorInputThirdCountryReverseCharge;
        }

        // The fourth place this exporter has to know the reduced rate, and the one that used to fall
        // through. `creator_input_de_reduced` ships unmapped, so this resolves to a refusal unless the
        // operator has confirmed an account — which is what the paragraph above always claimed happened.
        return $invoice->tax_rate_bps === 700
            ? DatevTransaction::CreatorInputDeReduced
            : DatevTransaction::CreatorInputDeStandard;
    }

    /**
     * The gross total's UNSIGNED magnitude as a DATEV decimal (comma separator, no thousands), e.g.
     * "119,00". The sign never appears here — direction is the Soll/Haben marker's job (see booking()) —
     * so a negative-total invoice books its magnitude with "H", not a minus DATEV would reject.
     */
    /**
     * The document's frozen rate, or null where the row needs none.
     *
     * Null in two different situations, and both are correct rather than a gap:
     *
     * - **The row is already in the base currency.** DATEV wants fields 4-6 EMPTY there, so filling them
     *   would be the defect. This is also what keeps a single-currency install byte-identical: the branch
     *   is never entered, and its export is the file it always was.
     * - **A foreign-currency document with no frozen rate.** Those exist — the freeze arrived after some
     *   rows were written — and this method does not invent one. Deriving a rate at export time would give
     *   DATEV a number the document itself does not state, which is the divergence the freeze exists to
     *   prevent: the books and the document would then disagree, and only the books would be re-derivable.
     *
     * The DOCUMENT layer specifically. A document is exported at the rate it was ISSUED at; the reporting
     * layer answers a different question (what the period is declared at) and the payout layer a third
     * (what the bank actually did). They legitimately differ, and taking whichever happened to be attached
     * would make the export's number depend on which freezes had run.
     */
    private function documentRate(InvoiceRecord $invoice): ?FrozenExchangeRate
    {
        // Compared case-insensitively, because `batchCurrency()` upper-cases what config gave it and the
        // document's column is whatever the sale was written with. A case difference is not a currency
        // difference, and treating it as one would put a rate on every base-currency row in an install that
        // happens to store 'eur'.
        if (strtoupper($invoice->currency) === $this->batchCurrency()) {
            return null;
        }

        $rate = $invoice->exchangeRates
            ->firstWhere(fn (InvoiceExchangeRate $row): bool => $row->layer === ExchangeRateLayer::Document);

        return $rate?->frozen();
    }

    /**
     * The rate as DATEV reads it: a decimal with a comma, and never scientific notation.
     *
     * The stored figure is an integer at eight decimal places. `number_format` rather than a float cast,
     * because a small rate cast to string becomes `4.3E-5` — which DATEV reads as a malformed field rather
     * than as a number, and the row is rejected with no indication of why.
     */
    private function rateField(FrozenExchangeRate $rate): string
    {
        return str_replace('.', ',', number_format($rate->rateScaled / FrozenExchangeRate::SCALE, 8, '.', ''));
    }

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
