<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Container\Container;
use Pushery\Billing\Contracts\ReportingPlausibilityRule;
use Pushery\Billing\Contracts\ReportingProfile;
use Pushery\Billing\Exceptions\DuplicateReportingRule;
use Pushery\Billing\Marketplace\Plausibility\DuplicateSellerRule;
use Pushery\Billing\Marketplace\Plausibility\QuarterCoverageRule;
use Pushery\Billing\Marketplace\Plausibility\SellerRecordRule;
use Pushery\Billing\Marketplace\Plausibility\UnclassifiedActivityRule;
use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * The catalog a period is checked against: the package's own rules plus whatever the consumer added.
 *
 * Modeled on {@see CheckpointRegistry}, deliberately — the two solve the same
 * problem and a second shape for it would be a second thing to learn. Collected lazily, at the moment a run
 * asks, so nothing depends on which service provider booted first.
 *
 * ## What is package code and what is not
 *
 * The four shipped rules are STRUCTURAL: undecided classification, quarters that do not sum to the year, a
 * seller reported twice, an unfilable seller record. None of them names a country, a threshold or a form.
 * What varies by jurisdiction — which fields a record must carry, which identifiers must hold which check —
 * comes through {@see ReportingProfile}, so a consumer under another duty binds
 * another profile instead of switching a German rule off.
 *
 * A consumer with rules of their own adds them:
 *
 *     $app->make(ReportingPlausibilityRules::class)->add(new OurOwnRule);
 *
 * ## Why adding twice is refused rather than tolerated
 *
 * A rule's key is half of an acknowledgement's key. Two rules answering to one key make an acknowledgement
 * ambiguous — it would clear whichever of them ran first — and the failure surfaces as a finding that
 * "comes back" after being answered, which reads like a bug in the acknowledgement rather than in the
 * catalog.
 */
final class ReportingPlausibilityRules
{
    /** @var list<ReportingPlausibilityRule> */
    private array $added = [];

    public function __construct(private readonly Container $container) {}

    public function add(ReportingPlausibilityRule $rule): self
    {
        $this->added[] = $rule;

        return $this;
    }

    /**
     * Every rule that applies, package first, in a fixed order.
     *
     * Ordered so two runs over the same data report the same findings in the same order — an operator diffs
     * this against the previous run, and a list that reshuffles itself makes that impossible.
     *
     * @return list<ReportingPlausibilityRule>
     *
     * @throws DuplicateReportingRule when two rules answer to one key
     */
    public function all(): array
    {
        $rules = [
            $this->container->make(UnclassifiedActivityRule::class),
            $this->container->make(SellerRecordRule::class),
            $this->container->make(QuarterCoverageRule::class),
            $this->container->make(DuplicateSellerRule::class),
            ...$this->added,
        ];

        $seen = [];

        foreach ($rules as $rule) {
            if (isset($seen[$rule->key()])) {
                throw DuplicateReportingRule::forKey($rule->key());
            }

            $seen[$rule->key()] = true;
        }

        return $rules;
    }
}
