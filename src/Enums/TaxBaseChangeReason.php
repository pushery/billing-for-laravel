<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why the amount a sale was taxed on changed.
 *
 * Both reasons correct the same figures in the same period, which is exactly why the distinction is easy to
 * drop — and why dropping it costs later. They differ in what happens **afterwards**: money given back is
 * gone, and the correction is final. Money merely not received may still arrive, and when it does the
 * correction has to be corrected in turn. A document that does not say which one it was leaves nobody able
 * to tell a finished matter from an open one, months later, with only the amount to go on.
 *
 * Neutral on purpose: a jurisdiction names its own provision for each, and the package only needs to know
 * whether a later receipt puts the correction back.
 */
enum TaxBaseChangeReason: string
{
    /** The consideration was handed back. Nothing can arrive later, so the correction stands. */
    case Repaid = 'repaid';

    /**
     * The consideration will not be received — a lost dispute, a debt written off.
     *
     * It is a judgement about the future, and the future can disagree: if the money does arrive after all,
     * the tax and the input tax are corrected a second time, back to where they were.
     */
    case Uncollectible = 'uncollectible';

    /** Whether a later receipt puts this correction back. */
    public function reversesOnLaterReceipt(): bool
    {
        return $this === self::Uncollectible;
    }
}
