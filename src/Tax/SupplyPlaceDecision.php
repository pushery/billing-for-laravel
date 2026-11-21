<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CountryEvidencePolicy;
use Pushery\Billing\Enums\PlaceOfSupplyRule;
use Pushery\Billing\Models\PlaceEvidence;
use Pushery\Billing\ValueObjects\CountrySignals;
use RuntimeException;

/**
 * The one place a sale's country is decided, recorded, and answered from afterwards.
 *
 * ## Two questions that are easy to mistake for one
 *
 * Which country the buyer is in is a question of evidence — what the buyer said, what their payment says,
 * where their connection is. Whether that country's tax applies is a separate question about the seller: a
 * seller below a turnover limit charges their own country's tax on the very same sale to the very same
 * buyer.
 *
 * Answered as one question, the second silently disappears: the buyer's country is established, the
 * destination's rate is applied, and a seller who owed their own country's tax has been charging the wrong
 * one all year. So the evidence resolves the country, the threshold decides whose rate applies, and both
 * answers travel together.
 *
 * ## Decided once
 *
 * The country is established at the sale and read from the record ever after. A refund, a correction, a
 * re-issued document all work on the country originally resolved — re-deriving it would let a buyer who has
 * since moved change what an old sale was taxed under, retroactively and invisibly.
 */
final readonly class SupplyPlaceDecision
{
    public function __construct(
        private CountryEvidencePolicy $evidence,
        private PlaceEvidenceStore $store,
        private DistanceSaleThresholdMonitor $thresholds,
    ) {}

    /**
     * Establish and record a sale's place, and say whose rate applies.
     *
     * @return array{country: string, rule: PlaceOfSupplyRule}
     */
    public function decide(
        CountrySignals $signals,
        string $reference,
        string $currency,
        CarbonInterface $soldAt,
        ?Model $owner = null,
    ): array {
        $evidence = $this->evidence->resolve($signals);

        // Refuses an unsettled resolution rather than attributing the sale anywhere.
        $record = $this->store->record($evidence, $reference, $soldAt, $owner);

        return [
            'country' => $record->resolved_country,
            // The seller's side of the same sale: below a limit, their own country's rate applies to a
            // buyer whose country is perfectly well established.
            'rule' => $this->thresholds->rule((int) $soldAt->format('Y'), $currency),
        ];
    }

    /**
     * Record that the customer of a sale is established somewhere else from a day on, and return the country.
     *
     * The place of a service to a business is where the recipient has its seat when the service is supplied (Art. 44 VAT
     * Directive), and the place of an electronic service to a consumer is where the consumer lives then (Art. 58), so a
     * customer who moves takes every later cycle with them, and every earlier cycle stays where it was. The move is
     * settled from signals exactly as the sale was, and recorded as a dated record of its own beside the sale's; a cycle
     * reads the one in force on the first day of its period. A second move from the same day corrects the first and is
     * kept beside it, and the day reads the later one.
     *
     * @throws InvalidArgumentException when the sale has no recorded place to move from
     * @throws RuntimeException when the signals do not settle on a country
     */
    public function relocate(
        CountrySignals $signals,
        string $reference,
        CarbonInterface $from,
        CarbonInterface $resolvedAt,
        ?Model $owner = null,
    ): string {
        if (! $this->isEstablished($reference)) {
            throw new InvalidArgumentException("The sale `{$reference}` has no recorded place, so there is nothing to move: record its place first.");
        }

        return $this->store->record($this->evidence->resolve($signals), $this->store->nextDatedReference($reference, $from), $resolvedAt, $owner)->resolved_country;
    }

    /**
     * The country a recorded sale was taxed in.
     *
     * Read, never re-derived: a buyer who has since moved must not be able to change what an old sale was
     * taxed under.
     */
    public function countryFor(string $reference): ?string
    {
        return $this->store->countryFor($reference);
    }

    /** Whether a sale has an established country at all. Nothing may be issued for one that does not. */
    public function isEstablished(string $reference): bool
    {
        return PlaceEvidence::model()::query()->where('reference', $reference)->exists();
    }
}
