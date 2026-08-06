<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Profiles;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\ClassifiesReportability;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Enums\ReportabilityReason;
use Pushery\Billing\Enums\SellerFieldBasis;
use Pushery\Billing\ValueObjects\ReportabilityVerdict;
use Pushery\Billing\ValueObjects\SellerActivity;
use Pushery\Billing\ValueObjects\SellerRecordField;

/**
 * What a German-regime platform asks a seller for.
 *
 * Two things are worth reading twice.
 *
 * The list does NOT change with whether a seller is currently reportable — only the BASIS of its fields
 * does. A seller who takes on one commissioned piece of work becomes reportable that day, and a platform
 * that only collected from the sellers it already knew about then has to chase the rest: after the year has
 * closed, under a filing deadline, from people who have gone quiet or changed their address. That chase
 * ends in withholding money from sellers who did nothing wrong, which is why the data is asked for up front.
 *
 * And the precautionary half is switchable off. Collecting an identifier and a date of birth from somebody
 * no law asks about is a real imposition, and a platform that would rather take the later chase than make
 * it is entitled to that choice. The fields that are needed anyway — where to send the document, where to
 * send the money — are never precautionary and never switch off.
 */
final readonly class GermanReportingProfile implements ClassifiesReportability, ReportingProfile
{
    public function __construct(private Repository $config) {}

    public function fieldsFor(bool $isLegalEntity, bool $reportable): array
    {
        // Needed to settle with anybody at all, reportable or not: a document has to be addressed and money
        // has to go somewhere. Never precautionary, so never switched off.
        $fields = [
            new SellerRecordField('seller_name', SellerFieldBasis::Required),
            new SellerRecordField('seller_address', SellerFieldBasis::Required),
            new SellerRecordField('payout_account', SellerFieldBasis::Required),
            new SellerRecordField('payout_account_holder', SellerFieldBasis::Required),
        ];

        // What the reporting duty adds. Its basis is what moves, not its presence.
        $basis = $reportable ? SellerFieldBasis::Required : SellerFieldBasis::Precautionary;

        if (! $reportable && ! $this->collectsPrecautionary()) {
            return $fields;
        }

        $fields[] = new SellerRecordField('seller_tax_identifier', $basis, sensitive: true);
        $fields[] = $isLegalEntity
            // A company has no date of birth; it has a registration. Not the same field with a different
            // label — a different fact, which is why this is a branch and not an optional column.
            ? new SellerRecordField('seller_register_number', $basis)
            : new SellerRecordField('seller_date_of_birth', $basis, sensitive: true);

        // A company's tax registration for cross-border supplies, where it has one.
        if ($isLegalEntity) {
            $fields[] = new SellerRecordField('seller_vat_identifier', SellerFieldBasis::Precautionary);
        }

        return $fields;
    }

    private function collectsPrecautionary(): bool
    {
        return (bool) $this->config->get('billing.marketplace.seller_record.collect_precautionary', true);
    }

    /**
     * Who falls under the duty, and why.
     *
     * Two branches put a seller in: work commissioned for one buyer, and selling goods past the point where
     * the small-scale exemption stops. Everything else is out — standardized supply, sold off the shelf to
     * whoever wants it, however much of it there is.
     *
     * ## Both directions are mistakes, so neither is the safe one
     *
     * Failing to report is an offense. Reporting somebody the law leaves out is ALSO one, and a data
     * protection breach besides, because it hands a person's details to an authority with nothing entitling
     * anyone to them. "When in doubt, report" is not caution — it is the second error, and it is the one
     * that looks responsible while it happens.
     *
     * ## The exemption is cumulative, and its upper edge is INCLUSIVE
     *
     * Few enough sales AND a small enough amount. One alone exempts nobody. And the amount test is "does not
     * exceed" — a seller at exactly the figure is left out by the law, so a strict comparison here would
     * report somebody the statute exempts. That is precisely the over-reporting the paragraph above is
     * about, arrived at through an operator rather than a policy.
     *
     * ## The exemption belongs to the goods branch alone
     *
     * There is no small-scale relief for commissioned work: three commissions worth a year's rent are
     * reportable, and a thousand standardized downloads are not. Reaching for the thresholds outside the
     * goods branch would exempt exactly the sellers the duty exists for.
     */
    public function classify(SellerActivity $activity): ReportabilityVerdict
    {
        if ($activity->individuallyCommissioned()) {
            return new ReportabilityVerdict(ReportabilityReason::IndividuallyCommissioned);
        }

        if (! $activity->isGoods()) {
            return new ReportabilityVerdict(ReportabilityReason::Standardized);
        }

        return new ReportabilityVerdict(
            $this->withinDeMinimis($activity)
                ? ReportabilityReason::GoodsWithinDeMinimis
                : ReportabilityReason::GoodsAboveDeMinimis
        );
    }

    /**
     * Whether a goods seller stays under BOTH edges of the exemption.
     *
     * The operators are configurable, not the comparison written into the code, because the upper one is
     * exactly where this went wrong once: a strict "less than" reports the seller sitting on the figure the
     * law lets go. A consumer whose statute reads differently changes the operator rather than the class.
     */
    private function withinDeMinimis(SellerActivity $activity): bool
    {
        return $this->compare($activity->salesCount, $this->salesOperator(), $this->maxSales())
            && $this->compare($activity->compensation->minorUnits, $this->compensationOperator(), $this->maxCompensation());
    }

    private function compare(int $value, string $operator, int $limit): bool
    {
        return match ($operator) {
            '<=' => $value <= $limit,
            default => $value < $limit,
        };
    }

    private function salesOperator(): string
    {
        return $this->operator('sales_operator', '<');
    }

    private function compensationOperator(): string
    {
        // Inclusive by default: the statute exempts the seller who "does not exceed" the figure, and a
        // strict comparison would report the one sitting exactly on it.
        return $this->operator('compensation_operator', '<=');
    }

    private function operator(string $key, string $default): string
    {
        $value = $this->config->get("billing.reporting.goods_de_minimis.{$key}");

        return $value === '<' || $value === '<=' ? $value : $default;
    }

    private function maxSales(): int
    {
        $value = $this->config->get('billing.reporting.goods_de_minimis.max_sales');

        return is_int($value) ? $value : 30;
    }

    private function maxCompensation(): int
    {
        $value = $this->config->get('billing.reporting.goods_de_minimis.max_compensation_minor');

        return is_int($value) ? $value : 200_000;
    }
}
