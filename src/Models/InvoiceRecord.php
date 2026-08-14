<?php

declare(strict_types=1);

namespace Pushery\Billing\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Override;
use Pushery\Billing\Casts\UtcDateTime;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Enums\FanReceiptTier;
use Pushery\Billing\Enums\InvoiceCorrectionKind;
use Pushery\Billing\Enums\InvoiceStatus;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\RecipientTaxStatus;
use Pushery\Billing\Enums\ReversalAttribution;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Enums\SellerOfRecordPosture;
use Pushery\Billing\Enums\SettlementDocumentType;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxationBasis;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Enums\TaxExemptionReason;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Invoicing\Guards\ChargeClaimKeyDeriver;
use Pushery\Billing\Invoicing\Guards\ImmutableIssuedInvoiceGuard;
use Pushery\Billing\Invoicing\Guards\RegimePostureGuard;
use Pushery\Billing\Invoicing\Guards\SellerMatchesPostureGuard;
use Pushery\Billing\Marketplace\DocumentRoleGuard;
use Pushery\Billing\ValueObjects\CountingPeriod;
use Pushery\Billing\ValueObjects\Invoice;
use Pushery\Billing\ValueObjects\Money;

/**
 * A stored invoice. Maps to the neutral {@see Invoice} DTO the Invoices contract returns, so views
 * never touch the model or a provider object.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property ?string $provider
 * @property ?string $provider_id
 * @property ?string $number
 * @property ?string $pdf_path
 * @property ?int $credited_invoice_id
 * @property ?string $credited_invoice_number
 * @property ?int $reissue_of_invoice_id
 * @property int $total_minor
 * @property string $currency
 * @property InvoiceStatus $status
 * @property ?Carbon $issued_at
 * @property ?Carbon $due_at
 * @property ?Carbon $created_at
 * @property ?array<string,mixed> $buyer
 * @property ?array<string,mixed> $seller
 * @property ?int $subtotal_minor
 * @property ?int $tax_minor
 * @property bool $reverse_charge
 * @property bool $tax_exempt
 * @property ?TaxExemptionReason $tax_exemption_reason
 * @property ?string $buyer_reference
 * @property ?string $vat_note
 * @property bool $oss
 * @property ?string $destination_country
 * @property ?string $destination_subdivision the subdivision of that country, where the obligation is a
 *                                            subdivision's rather than the country's — `CA`, `NY`, `BY`.
 *                                            Null means none was settled, which is an answer and not a gap
 * @property ?string $oss_rate
 * @property ?TaxArchetype $tax_archetype
 * @property ?TaxArchetype $sold_alongside_archetype
 * @property ?PlaceOfSupplyRule $place_of_supply_rule
 * @property ?TaxRateCategory $tax_rate_category
 * @property ?int $tax_rate_bps
 * @property ?bool $platform_reporting
 * @property ?string $rate_matrix_version
 * @property ?RecipientTaxStatus $recipient_tax_status
 * @property ?TaxationBasis $taxation_basis
 * @property ?int $margin_minor
 * @property ?SupplyRegime $supply_regime
 * @property ?SellerOfRecordPosture $seller_posture
 * @property ?InvoiceCorrectionKind $correction_kind
 * @property ?TaxBaseChangeReason $tax_base_change_reason
 * @property ?string $settled_charge_reference
 * @property ?string $charge_claim_key `provider|reference` for the document that claims a settled charge
 *                                     as the sale's FIRST one; null on a reissue, on every correction and
 *                                     on anything carrying a period, which is what lets a unique index
 *                                     hold the invariant without refusing documents that must exist.
 *                                     Derived when the row is created — never assigned by a caller
 * @property ?int $commission_bps
 * @property ?int $commission_flat_minor
 * @property ?RoundingResidual $commission_residual
 * @property ?SettlementDocumentType $settlement_document_type
 * @property ?DocumentSeries $document_series
 * @property ?FanReceiptTier $receipt_tier
 * @property ?string $settlement_period
 * @property ?Carbon $service_period_start
 * @property ?Carbon $service_period_end
 * @property ?Carbon $delivered_on
 * @property ?Carbon $invoice_effect_revoked_at
 * @property ?string $invoice_effect_revoked_channel
 * @property ?int $fan_gross_minor
 * @property ?array<int,array<string,mixed>> $lines
 */
final class InvoiceRecord extends Model
{
    protected $table = 'billing_invoices';

    /** @var list<string> */
    protected $fillable = [
        'owner_type', 'owner_id', 'provider', 'provider_id', 'number', 'pdf_path', 'total_minor', 'currency',
        'status', 'issued_at', 'due_at', 'credited_invoice_id', 'credited_invoice_number', 'reissue_of_invoice_id',
        'buyer', 'subtotal_minor',
        'tax_minor', 'reverse_charge', 'tax_exempt', 'tax_exemption_reason', 'buyer_reference', 'vat_note', 'oss', 'destination_country', 'destination_subdivision', 'oss_rate',
        'tax_archetype', 'sold_alongside_archetype', 'place_of_supply_rule', 'tax_rate_category', 'tax_rate_bps', 'platform_reporting',
        'rate_matrix_version', 'recipient_tax_status', 'taxation_basis', 'margin_minor', 'supply_regime', 'seller_posture', 'seller',
        'settlement_document_type', 'document_series', 'receipt_tier', 'settlement_period',
        'service_period_start', 'service_period_end', 'delivered_on',
        'invoice_effect_revoked_at', 'invoice_effect_revoked_channel', 'fan_gross_minor', 'correction_kind', 'tax_base_change_reason', 'settled_charge_reference',
        'commission_bps', 'commission_flat_minor', 'commission_residual', 'lines',
    ];

    /**
     * The scalar columns an issued document may never change.
     *
     * A constant rather than an inline list because there is a THIRD copy of this knowledge — the test that
     * proves each column is refused — and a third copy that repeats rather than derives is one that rots.
     * It did: `tax_exemption_reason` was missing from the list and from the test at the same time, so the
     * guard against an editable tax characteristic was green while one stayed editable.
     *
     * @var list<string>
     */
    public const array FROZEN_SCALARS = [
        'number', 'total_minor', 'subtotal_minor', 'tax_minor', 'currency', 'reverse_charge', 'tax_exempt',
        // WHY it was exempt, frozen beside the fact THAT it was. The two were split for a while — the
        // flag frozen, the reason editable — and they are not interchangeable: a reverse-charged
        // supply IS taxed and an export outside the union is not. Both e-invoice renderers read this
        // column as the EN 16931 exemption reason, so an editable one lets a numbered document claim
        // afterwards that it was an export, with every amount on it still adding up.
        'tax_exemption_reason',
        'buyer_reference', 'vat_note', 'oss', 'destination_country', 'destination_subdivision', 'oss_rate', 'issued_at',
        // The tax characteristics of the sale, frozen for the same reason as the amounts: they were
        // read back from the product until now, and a product can be reclassified after it has been
        // sold. A correction that re-derived them would reverse an amount nobody ever declared, and
        // report it into a country the original sale never touched — without looking wrong.
        // …and WHAT a voluntary payment was paid on, for the same reason one step removed: it is the
        // input every one of those characteristics was derived from, so an amendable reference would
        // let a settled tip be re-pointed at a different product and silently change whether the
        // seller behind it has to be reported at all.
        'tax_archetype', 'sold_alongside_archetype', 'place_of_supply_rule', 'tax_rate_category', 'tax_rate_bps',
        'platform_reporting', 'rate_matrix_version', 'recipient_tax_status', 'taxation_basis', 'margin_minor',
        // The shape of the sale and who it named as seller. Re-classifying a settled transaction
        // does not adjust a number: it makes every document already issued about it describe a
        // transaction that did not happen. The only correct path is to cancel and re-issue.
        'supply_regime', 'seller_posture', 'settlement_document_type', 'document_series', 'receipt_tier', 'settlement_period',
        // WHICH months the document is for. Frozen with the amounts, because it is the same kind of
        // claim: moving a period on an issued document silently re-declares the supply into another
        // return, and the totals stay perfectly consistent while doing it. Cancel and re-issue.
        'service_period_start', 'service_period_end', 'delivered_on',
        // …and WHOSE reference that is. The two are one key, and freezing half of it froze nothing:
        // an update could re-point a numbered tax document at another payment provider while the
        // reference it belongs to stayed put.
        'provider',
        'fan_gross_minor', 'correction_kind', 'tax_base_change_reason', 'settled_charge_reference',
        // The claim on that reference. It is derived at creation and it is what a unique index reads,
        // so an update that cleared it would hand the sale's exclusive slot back and let a second
        // first-document be written for a charge that already has one — undoing the constraint from
        // inside the application, which is the one direction a database cannot defend itself in.
        'charge_claim_key',
        'commission_bps', 'commission_flat_minor', 'commission_residual',
    ];

    /**
     * The same defaults the schema carries, so a row that was just created reads like one that was read back.
     *
     * Without them a model created without these columns holds null for each, while the row the database
     * stores holds the value — a disagreement that lasts only until somebody re-reads, which is exactly why
     * it hides. Held against the migration by ModelSchemaDefaultsTest.
     *
     * @var array<string, bool|string>
     */
    protected $attributes = [
        'status' => 'draft',
        'reverse_charge' => false,
        'tax_exempt' => false,
        'oss' => false,
    ];

    /** @var array<string,string> */
    protected $casts = [
        'total_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'tax_minor' => 'integer',
        'fan_gross_minor' => 'integer',
        'reverse_charge' => 'boolean',
        'tax_exemption_reason' => TaxExemptionReason::class,
        'tax_exempt' => 'boolean',
        'oss' => 'boolean',
        // decimal:2 keeps the applied VAT rate exact (a string, e.g. "19.00") — a float cast would let
        // 19.00 drift, and the rate on a frozen tax document must round-trip byte-for-byte.
        'oss_rate' => 'decimal:2',
        'status' => InvoiceStatus::class,
        // NOT the plain 'datetime' cast: this package targets a non-UTC (German) app, and the framework
        // default re-reads a stored instant in the APP timezone, shifting an invoice's frozen issue instant
        // by the UTC offset on every round-trip. UtcDateTime keeps the instant exact — same as the
        // Subscription model does for its provider timestamps.
        'issued_at' => UtcDateTime::class,
        'due_at' => UtcDateTime::class,
        'tax_archetype' => TaxArchetype::class,
        'sold_alongside_archetype' => TaxArchetype::class,
        'place_of_supply_rule' => PlaceOfSupplyRule::class,
        'tax_rate_category' => TaxRateCategory::class,
        'tax_rate_bps' => 'integer',
        'commission_bps' => 'integer',
        'commission_flat_minor' => 'integer',
        'platform_reporting' => 'boolean',
        'recipient_tax_status' => RecipientTaxStatus::class,
        'taxation_basis' => TaxationBasis::class,
        'margin_minor' => 'integer',
        'supply_regime' => SupplyRegime::class,
        'seller_posture' => SellerOfRecordPosture::class,
        'settlement_document_type' => SettlementDocumentType::class,
        'document_series' => DocumentSeries::class,
        'correction_kind' => InvoiceCorrectionKind::class,
        'commission_residual' => RoundingResidual::class,
        'receipt_tier' => FanReceiptTier::class,
        'tax_base_change_reason' => TaxBaseChangeReason::class,
        'invoice_effect_revoked_at' => UtcDateTime::class,
        'buyer' => 'array',
        'seller' => 'array',
        'lines' => 'array',
        // Whole days, never instants: a service period is stated in days on every document format that
        // carries it, and a time of day would make two documents for the same month differ by when they
        // happened to be issued.
        'service_period_start' => 'date',
        'service_period_end' => 'date',
        'delivered_on' => 'date',
    ];

    /**
     * GoBD immutability: an issued invoice's CONTENT must not change once recorded. The status (the payment
     * state — open → paid → …) may still transition, and the buyer / credited-invoice links are allowed to
     * reconcile (a credit note persisted before its original is stored later backfills the original's frozen
     * buyer and local id). But the number, the amounts, the currency, the tax treatment, the issue date and
     * the line items are frozen: any code path that dirties one of them on an EXISTING row is rejected.
     */
    /**
     * What this document was converted at, one row per conversion layer.
     *
     * The direction that matters: a correction asks what the document WAS booked at, and reads these rows
     * rather than converting again. Re-deriving would reverse an amount nobody ever declared, and the
     * difference would be a currency movement rather than anything either party did.
     *
     * Empty on a single-currency sale, which never converted and has nothing to freeze.
     *
     * @return HasMany<InvoiceExchangeRate, $this>
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(InvoiceExchangeRate::class, 'invoice_id');
    }

    #[Override]
    protected static function booted(): void
    {
        // Five delegations, and that shape is the point. Every rule below used to live here as a closure —
        // a third of this class in one static method — which meant each of them could only be exercised by
        // saving a real row against a real database. A rule that expensive to reach is a rule whose edge
        // cases do not get written, and the ungiven edge cases are the ones that come back as defects.
        //
        // Registered HERE rather than in an observer so no second caller — a job, a console command,
        // consumer code writing its own document — can route around them. What moved is where the rules
        // LIVE, not where they are enforced.

        // The regime and the posture are one decision seen twice, checked at CREATION because that is the
        // only moment either can be wrong: both columns are frozen afterwards.
        self::creating(static function (self $invoice): void {
            $regime = $invoice->supply_regime;
            $posture = $invoice->seller_posture;

            if ($regime instanceof SupplyRegime && $posture instanceof SellerOfRecordPosture) {
                new RegimePostureGuard()->assertCoherent($regime, $posture);
            }
        });

        // Which document holds the exclusive claim on a settled charge. Assigned rather than defaulted: any
        // value a caller passed is overwritten, because a column deciding whether a duplicate is possible
        // must not be settable.
        self::creating(static function (self $invoice): void {
            $invoice->setAttribute('charge_claim_key', new ChargeClaimKeyDeriver()->keyFor(
                $invoice->settled_charge_reference,
                $invoice->provider,
                coversAPeriod: $invoice->settlement_period !== null,
                isReissue: $invoice->reissue_of_invoice_id !== null,
                isCorrection: $invoice->credited_invoice_id !== null,
            ));
        });

        // The seller a document names against the posture it carries. Skipped where no seller was
        // snapshotted, so a single-seller row is untouched.
        self::creating(static function (self $invoice): void {
            $posture = $invoice->seller_posture;
            $seller = $invoice->getAttribute('seller');
            $company = Config::get('billing.company');

            if ($posture instanceof SellerOfRecordPosture && is_array($seller)) {
                new SellerMatchesPostureGuard()->assertMatches($posture, $seller, is_array($company) ? $company : []);
            }
        });

        // The document's ROLE against the sale's regime. Keyed on the regime rather than the posture: a
        // self-billed invoice names the CREATOR as seller, which reads as "not the platform", so the posture
        // is not a clean key for a creator-facing document's role.
        self::creating(static function (self $invoice): void {
            $regime = $invoice->supply_regime;
            $series = $invoice->document_series;

            if ($regime instanceof SupplyRegime && $series instanceof DocumentSeries) {
                new DocumentRoleGuard()->assertPermitted($regime, $series);
            }
        });

        // And what an issued document may no longer change.
        self::updating(static function (self $invoice): void {
            new ImmutableIssuedInvoiceGuard()->assertUnchanged($invoice);
        });
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /**
     * Whether this document was produced here rather than hydrated from a provider's invoice.
     *
     * DERIVED on purpose, and the absence of a column is the design. `provider_id` is written by exactly
     * two writers, both webhook effects mirroring a provider's own invoice ({@see PersistInvoice},
     * {@see PersistInvoiceCorrection}); the four issuers that produce a document here never set it. So
     * "locally generated" is not a second fact about a row — it IS `provider_id === null`, and a stored
     * boolean would be a copy that can disagree with the thing it copies. This package has paid for that
     * shape twice already, and both times the copy was the one that answered.
     *
     * The equivalence is structural rather than lucky, and `InvoicePdfAndOriginTest` holds it across both
     * families of writer: the day something hydrates a local document with a provider's id, that case goes
     * red and names the decision instead of letting the meaning drift.
     */
    public function locallyGenerated(): bool
    {
        return $this->provider_id === null;
    }

    /**
     * Whether this row is an invoice correction — a document that corrects another invoice, rather than a
     * charge. It is identified by a reference to what it corrects (the local row or the provider's own
     * number), which drives the accounting direction (DATEV books it "H", not "S") and the e-invoice type
     * code (381/384, not 380). A correction carries POSITIVE amounts; the correction's nature — not a sign —
     * is what inverts the meaning, exactly as EN 16931 and a DATEV Haben booking require.
     */
    public function isCorrection(): bool
    {
        return $this->credited_invoice_id !== null || $this->credited_invoice_number !== null;
    }

    /**
     * Whether the recipient wrote this document on the supplier's behalf — a Gutschrift in the settlement
     * sense, not a correction.
     *
     * Here rather than in the XML writers' trait because BOTH halves of a hybrid document need it and they
     * are rendered by different code. It lived only in the trait, so the machine-readable half said 389 and
     * the half a person opens said nothing — one file contradicting itself, with the conformance-checked
     * half being the correct one, which is why no validator ever complained.
     */
    public function isSelfBilled(): bool
    {
        return $this->settlement_document_type === SettlementDocumentType::SelfBilledInvoice;
    }

    /**
     * Whether this document states a sale an earlier document already stated.
     *
     * A buyer who took a short receipt may ask for a full invoice afterwards. They get a real document with
     * its own number, and the receipt they already hold is not touched — reaching back to change an issued
     * document is what a numbered series exists to prevent.
     *
     * Which leaves two documents over one sale. Everything that SUMS documents has to skip this one, or the
     * sale is counted twice and tax is declared that was never taken. That is the only thing this predicate
     * is for, and every aggregate in the package asks it.
     */
    public function isReissue(): bool
    {
        return $this->reissue_of_invoice_id !== null;
    }

    /**
     * When the document this one corrects was issued, or null when it corrects nothing reachable.
     *
     * A correction belongs to a return period, and WHICH period is decided by the ORIGINAL's date, not its
     * own: that is what "correcting the second quarter" means. Reading it from the corrected row rather than
     * carrying a copy is deliberate — a copy is a second place for the date to live, and a correction whose
     * copy drifted would be declared against a quarter it does not correct.
     *
     * Null when the correction names only a number the package cannot resolve to a row, which is the case
     * for a document issued elsewhere. A caller that needs the period then has to be told, not guess.
     */
    public function correctedIssuedAt(): ?CarbonImmutable
    {
        if ($this->credited_invoice_id === null) {
            return null;
        }

        $issued = self::query()->whereKey($this->credited_invoice_id)->first()?->issued_at;

        return $issued instanceof Carbon ? CarbonImmutable::parse($issued->toIso8601String()) : null;
    }

    /** Whether a creator's objection has taken away this document's effect as an invoice. */
    public function invoiceEffectRevoked(): bool
    {
        return $this->invoice_effect_revoked_at !== null;
    }

    /**
     * Whether this document's invoice effect is void for an accounting period that starts on the given day.
     *
     * The objection works EX NUNC — from the taxation period of the objection forward — so a period that
     * starts on or after the first day of the objection's month is void, and any earlier period (the one the
     * document was originally booked in) is untouched. That is what keeps the objection from rewriting a past
     * declaration: the input booked before it stands, and only the current and following periods drop it.
     */
    public function invoiceEffectVoidForPeriod(CarbonInterface $periodStart): bool
    {
        $revokedAt = $this->invoice_effect_revoked_at;

        return $revokedAt !== null && $revokedAt->copy()->startOfMonth()->lessThanOrEqualTo($periodStart);
    }

    public function toDto(?string $downloadUrl = null): Invoice
    {
        return new Invoice(
            id: (string) $this->id,
            date: $this->issued_at ?? $this->created_at ?? Carbon::now(),
            total: $this->total(),
            status: $this->status,
            number: $this->number,
            downloadUrl: $downloadUrl,
        );
    }

    /**
     * Whoever the document was issued to or for — the creator on a settlement, the buyer on a receipt.
     *
     * A relation rather than a hand-rolled morph lookup, because the correction path has to reach the
     * creator's STANDING and a second resolution of the same two columns is a second place it can be
     * resolved differently.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Narrow to the documents a counting period CONTAINS — the one place that rule lives.
     *
     * A document that corrects nothing is placed by its own date. A CORRECTION is placed by the configured
     * attribution: either by its own date, or by the date of the document it credits, which pulls it back
     * into the period whose figure it undoes. The second is the shipped default.
     *
     * ## Why this is a scope and not a private method
     *
     * Two queries need it, and they used to have one and a half. The counter carried the full rule; the
     * reporting run's roster filtered on `issued_at` alone — so under the shipped attribution a seller whose
     * only activity in a year was a correction of an older settlement entered the roster and then received a
     * row of ZEROS, because the counters placed that correction in the previous year.
     *
     * A row of zeros is not an empty answer. It states that a seller received nothing, which is a claim
     * about their year, and it is exactly what `SellerReportingPeriod` documents itself as avoiding. The
     * roster and the figures have to ask the same question, and the only way to be sure of that is for there
     * to be one question.
     *
     * Read through the credited row rather than copied onto the correction: a copy would be a second place
     * for the original's date to live, and the two would part company the first time one was amended.
     *
     * @param  Builder<InvoiceRecord>  $query
     * @return Builder<InvoiceRecord>
     */
    public function scopePlacedIn(Builder $query, CountingPeriod $period, ReversalAttribution $attribution): Builder
    {
        $from = $period->from->toDateTimeString();
        $until = $period->until->toDateTimeString();

        return $query
            ->whereNotNull('issued_at')
            ->where(function (Builder $window) use ($from, $until, $attribution): void {
                $window->where(function (Builder $own) use ($from, $until): void {
                    $own->whereNull('credited_invoice_id')
                        ->where('issued_at', '>=', $from)
                        ->where('issued_at', '<', $until);
                });

                if ($attribution === ReversalAttribution::ReversalPeriod) {
                    $window->orWhere(function (Builder $reversal) use ($from, $until): void {
                        $reversal->whereNotNull('credited_invoice_id')
                            ->where('issued_at', '>=', $from)
                            ->where('issued_at', '<', $until);
                    });

                    return;
                }

                $window->orWhere(function (Builder $original) use ($from, $until): void {
                    $original->whereNotNull('credited_invoice_id')
                        ->whereExists(function (QueryBuilder $credited) use ($from, $until): void {
                            $credited->select('id')
                                ->from('billing_invoices as credited')
                                ->whereColumn('credited.id', 'billing_invoices.credited_invoice_id')
                                ->where('credited.issued_at', '>=', $from)
                                ->where('credited.issued_at', '<', $until);
                        });
                });
            });
    }
}
