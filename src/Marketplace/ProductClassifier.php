<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Exceptions\ProductNotClassified;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\ArchetypeClassification;
use Pushery\Billing\ValueObjects\TaxonomyCell;

/**
 * Resolves what follows from a product, including the two cases that cannot answer for themselves.
 *
 * A voluntary payment takes its answers from whatever it was paid on, so it needs that other thing named.
 * Refusing without it is the point: a default there would report a tip on commissioned work as if it were a
 * file download — under-reporting in one direction, and, if it guessed the other way, over-reporting, which
 * is its own offense rather than a cautious error.
 *
 * A product with no classification at all cannot be sold. Not because the answer would be unknown, but
 * because there is no safe substitute for it: every one of the five consequences would be guessed, and a
 * guess that happens to be right most of the time is the hardest kind of defect to find.
 */
final readonly class ProductClassifier
{
    public function __construct(
        private ProductTaxonomy $taxonomy,
        private BillingEventLog $log,
    ) {}

    /**
     * What follows from selling this.
     *
     * @param  ?TaxArchetype  $soldAlongside  what a voluntary payment was paid on. Required for that shape
     *                                        and meaningless for every other.
     */
    public function classify(?TaxArchetype $archetype, ?TaxArchetype $soldAlongside = null): ArchetypeClassification
    {
        if (! $archetype instanceof TaxArchetype) {
            throw ProductNotClassified::beforeSale();
        }

        $classification = $this->taxonomy->classify($archetype);

        if (! $this->delegates($classification)) {
            return $classification;
        }

        if (! $soldAlongside instanceof TaxArchetype || $soldAlongside === $archetype) {
            throw ProductNotClassified::delegatedWithoutReference($archetype->value);
        }

        return $this->merge($classification, $this->classify($soldAlongside));
    }

    /**
     * The fallback for a record that never went through classification at all.
     *
     * It is a net, not a path: every use is written to the audit trail, because a fallback nobody can see
     * becomes the normal case within a release or two, and then the classification requirement is a
     * comment rather than a rule.
     */
    public function classifyWithFallback(?TaxArchetype $archetype, TaxArchetype $fallback, ?TaxArchetype $soldAlongside = null): ArchetypeClassification
    {
        if ($archetype instanceof TaxArchetype) {
            return $this->classify($archetype, $soldAlongside);
        }

        $this->log->record('billing.product_archetype_fallback', null, ['fallback' => $fallback->value]);

        return $this->classify($fallback, $soldAlongside);
    }

    private function delegates(ArchetypeClassification $classification): bool
    {
        return array_any($this->cells($classification), fn (TaxonomyCell $cell): bool => $cell->isDelegated());
    }

    /** Take each delegated answer from the thing it was sold alongside, and keep the rest. */
    private function merge(ArchetypeClassification $own, ArchetypeClassification $reference): ArchetypeClassification
    {
        return new ArchetypeClassification(
            regime: $own->regime->isDelegated() ? $reference->regime : $own->regime,
            placeOfSupply: $own->placeOfSupply->isDelegated() ? $reference->placeOfSupply : $own->placeOfSupply,
            rateCategory: $own->rateCategory->isDelegated() ? $reference->rateCategory : $own->rateCategory,
            reportable: $own->reportable->isDelegated() ? $reference->reportable : $own->reportable,
            withdrawal: $own->withdrawal->isDelegated() ? $reference->withdrawal : $own->withdrawal,
        );
    }

    /** @return list<TaxonomyCell> */
    private function cells(ArchetypeClassification $classification): array
    {
        return [
            $classification->regime,
            $classification->placeOfSupply,
            $classification->rateCategory,
            $classification->reportable,
            $classification->withdrawal,
        ];
    }
}
