<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Profiles;

use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Enums\SupplyRegime;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\TaxRateCategory;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\ValueObjects\ArchetypeClassification;
use Pushery\Billing\ValueObjects\TaxonomyCell;

/**
 * The German reading of the nine product shapes: one table, five answers each.
 *
 * Three rows are worth reading twice, because they are where a table like this normally goes wrong.
 *
 * A bundle containing audio or video is its OWN row rather than a text work with a flag, and it takes the
 * standard band. Any such content disqualifies the reduced one outright, and a platform that wants the
 * reduced band splits the product instead — a distinction that evaporates the moment it is left to be
 * re-derived from a product whose contents can change.
 *
 * A voluntary payment DELEGATES: its regime, its band and its reportability come from whatever it was paid
 * on. Wiring it to "not reportable" would under-report every tip on commissioned work.
 *
 * A multi-purpose voucher DEFERS: at issue nobody knows what will be bought with it, so nothing about it is
 * taxable yet. A null quietly read as "the usual" would tax it at issue, which is precisely backwards.
 */
final readonly class GermanProductTaxonomy implements ProductTaxonomy
{
    public function classify(TaxArchetype $archetype): ArchetypeClassification
    {
        return match ($archetype) {
            TaxArchetype::Download => $this->resale(TaxRateCategory::Standard, WithdrawalType::ExtinguishedOnDelivery),
            TaxArchetype::Subscription => $this->resale(TaxRateCategory::Standard, WithdrawalType::ProRataOnCancellation),
            // Text and images only — the one shape a reduced band exists for here.
            TaxArchetype::Ebook => $this->resale(TaxRateCategory::Reduced, WithdrawalType::ExtinguishedOnDelivery),
            // …and the same content with audio or video in it is not that shape.
            TaxArchetype::BundleWithAudioVideo => $this->resale(TaxRateCategory::Standard, WithdrawalType::ExtinguishedOnDelivery),
            TaxArchetype::Livestream => $this->resale(TaxRateCategory::Standard, WithdrawalType::ServicePerformed),
            // Commissioned for one buyer: taxed where the seller is, and reportable.
            TaxArchetype::CustomOneToOne => new ArchetypeClassification(
                regime: TaxonomyCell::fixed(SupplyRegime::CommissionChain),
                placeOfSupply: TaxonomyCell::fixed(PlaceOfSupplyRule::Domestic),
                rateCategory: TaxonomyCell::fixed(TaxRateCategory::Standard),
                reportable: TaxonomyCell::fixed(true),
                withdrawal: TaxonomyCell::fixed(WithdrawalType::ServicePerformed),
            ),
            // Everything about it belongs to what it was paid on — including whether it is reportable.
            TaxArchetype::Tip => new ArchetypeClassification(
                regime: TaxonomyCell::delegated(),
                placeOfSupply: TaxonomyCell::delegated(),
                rateCategory: TaxonomyCell::delegated(),
                reportable: TaxonomyCell::delegated(),
                // The one answer that does not delegate: it is provided the instant it is paid, so there is
                // never anything to change one's mind about.
                withdrawal: TaxonomyCell::fixed(WithdrawalType::NotApplicable),
            ),
            // Nothing has been bought yet, so nothing is taxable yet — not even in principle.
            TaxArchetype::Voucher => new ArchetypeClassification(
                regime: TaxonomyCell::deferred(),
                placeOfSupply: TaxonomyCell::deferred(),
                rateCategory: TaxonomyCell::deferred(),
                reportable: TaxonomyCell::fixed(false),
                // The purchase of the voucher itself is refundable in the ordinary way.
                withdrawal: TaxonomyCell::fixed(WithdrawalType::PlainRefundWindow),
            ),
            // The single row where the platform arranges rather than resells.
            TaxArchetype::ConsumerGoods => new ArchetypeClassification(
                regime: TaxonomyCell::fixed(SupplyRegime::Intermediation),
                placeOfSupply: TaxonomyCell::fixed(PlaceOfSupplyRule::Domestic),
                rateCategory: TaxonomyCell::fixed(TaxRateCategory::Standard),
                reportable: TaxonomyCell::fixed(true),
                // Between two private people there is no such right at all.
                withdrawal: TaxonomyCell::fixed(WithdrawalType::NotApplicable),
            ),
        };
    }

    /** The common shape: the platform resells, taxed where the buyer is, not reportable. */
    private function resale(TaxRateCategory $band, WithdrawalType $withdrawal): ArchetypeClassification
    {
        return new ArchetypeClassification(
            regime: TaxonomyCell::fixed(SupplyRegime::CommissionChain),
            placeOfSupply: TaxonomyCell::fixed(PlaceOfSupplyRule::Destination),
            rateCategory: TaxonomyCell::fixed($band),
            reportable: TaxonomyCell::fixed(false),
            withdrawal: TaxonomyCell::fixed($withdrawal),
        );
    }
}
