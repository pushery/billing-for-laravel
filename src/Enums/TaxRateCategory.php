<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Which band of a country's rates applies — not the rate itself.
 *
 * The two are separate on purpose. A category is a property of what was sold and is stable; a rate is a
 * property of a country at a moment and moves. Freezing only the rate would lose why it was that rate;
 * freezing only the category would leave a correction re-deriving a number that has since changed.
 */
enum TaxRateCategory: string
{
    case Standard = 'standard';

    /** The reduced band, where a country grants one for this kind of supply. */
    case Reduced = 'reduced';

    /**
     * The band actually granted once the audio-visual gate has been applied.
     *
     * Any audio or video part of a supply, however small, closes the reduced band for the WHOLE supply. The
     * rule lives on the enum rather than beside a table because it is a fact about the band, not about which
     * table is answering: the dated interval table and the undated matrix both have to apply it, and a
     * second copy of a two-line rule is how one table starts pricing a supply differently from the other.
     */
    public function withAudioVisual(bool $hasAudioVisualComponent): self
    {
        return $this === self::Reduced && $hasAudioVisualComponent ? self::Standard : $this;
    }
}
