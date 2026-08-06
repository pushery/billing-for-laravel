<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\ValueObjects\InboundTaxTreatment;

/**
 * What settling one creator's supply resolves to: either a hold, or the plan for the document to issue.
 *
 * A hold is not an error — a creator whose standing is unestablished is paid nothing and gets no document
 * until it is clarified, and that is a first-class outcome. Otherwise the outcome carries everything the
 * document needs and nothing it has to re-derive: which series numbers it (a self-billed invoice or a plain
 * settlement note), the number drawn from that series, and the tax treatment the input-side matrix already
 * decided. The persistence step renders from this; it never re-runs the decision.
 */
final readonly class SettlementOutcome
{
    private function __construct(
        public bool $isHold,
        public ?DocumentSeries $series,
        public ?string $number,
        public ?InboundTaxTreatment $treatment,
    ) {}

    public static function hold(): self
    {
        return new self(true, null, null, null);
    }

    public static function document(DocumentSeries $series, string $number, InboundTaxTreatment $treatment): self
    {
        return new self(false, $series, $number, $treatment);
    }

    /**
     * A settled supply whose series and treatment are decided and whose guards have passed, but whose number
     * is not yet drawn. The monthly collective run plans every transaction this way and draws ONE number for
     * the whole document, so a plan must not consume a number per line.
     */
    public static function planned(DocumentSeries $series, InboundTaxTreatment $treatment): self
    {
        return new self(false, $series, null, $treatment);
    }
}
