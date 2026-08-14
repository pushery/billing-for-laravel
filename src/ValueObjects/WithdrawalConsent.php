<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The two declarations a buyer must make before a digital work is provided, frozen onto the sale.
 *
 * Two, not one, and separate on purpose: the law asks for consent to immediate provision AND acknowledgement
 * that the right to withdraw ends when provision begins. They are different statements about different
 * things, and a single pre-ticked box satisfies neither.
 *
 * Frozen because the wording will change, and a sale is governed by the words shown at the time. A consent
 * recorded in March under one version is that version's consent; a later edit is a change going forward, not
 * a rewriting of what a buyer agreed to. This is the same reason the seller posture is snapshotted onto a
 * sale rather than resolved live.
 *
 * Its very existence is the gate. There is no "access now, confirmation later": without this value, on a
 * profile that requires it, nothing is provided.
 */
final readonly class WithdrawalConsent
{
    public function __construct(
        /** The buyer agreed to provision beginning before the withdrawal period runs out. */
        public bool $consentedToImmediateProvision,
        /** The buyer acknowledged that provision extinguishes the right to withdraw. */
        public bool $acknowledgedForfeiture,
        /** The version of the wording actually shown — so a later edit cannot reinterpret this sale. */
        public string $noticeVersion,
        /** When it was given. */
        public CarbonImmutable $givenAt,
        /**
         * The wording of the first declaration, exactly as the buyer was shown it.
         *
         * Carried rather than looked up: the confirmation the law wants is of the text they actually read,
         * and a version identifier alone cannot produce it. Null means the wording was not captured — which
         * a receipt renders as nothing at all, never as an empty confirmation.
         */
        public ?string $immediateProvisionNotice = null,
        /** The wording of the second declaration, on the same terms. */
        public ?string $forfeitureNotice = null,
    ) {}

    /** Whether both declarations were made. Neither alone is enough. */
    public function isComplete(): bool
    {
        return $this->consentedToImmediateProvision && $this->acknowledgedForfeiture;
    }

    /**
     * Whether the wording of BOTH declarations is on file.
     *
     * Both, because a receipt carrying one of them is worse than one carrying neither: it looks like a
     * confirmation and is not. What the law asks for is the confirmation of what the buyer agreed to, and
     * half of that confirms half a thing.
     */
    public function hasWording(): bool
    {
        return $this->immediateProvisionNotice !== null && $this->forfeitureNotice !== null;
    }
}
