<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use Pushery\Billing\Enums\GrantSource;

/**
 * One line of somebody's library: what they hold, and what can be done with it right now.
 *
 * ## The seller is the platform, and this object must not suggest otherwise
 *
 * `merchant` is **provenance** — who the work came from — and never a contracting party. The platform is the
 * seller toward the buyer for every content flow, and a library screen that named the creator as the seller
 * would state something legally wrong and, on top of that, break the creator's anonymity, which the whole
 * arrangement exists to protect. The field is named and documented so a template author cannot reach for it
 * expecting "who I bought from".
 *
 * ## `revocable` is not a withdrawal signal
 *
 * It says this access CAN be taken away — by a refund, a chargeback, a legal takedown. It says nothing about
 * whether the buyer may still withdraw from the purchase: that turns on the extinguishment flow and the
 * work's own withdrawal type, and pulling both into one field would eventually show "you can still cancel
 * this" for a purchase whose right of withdrawal has validly ended, or the reverse. A screen that needs the
 * withdrawal status reads the snapshot on the grant.
 *
 * ## What it deliberately does not carry
 *
 * No file, no URL, no bytes. Delivery is the consumer's own domain and always was — this package holds who
 * owns what, and stops there.
 */
final readonly class OwnedWork
{
    public function __construct(
        public ContentReference $content,
        public AccessDecision $decision,
        /** How it was come by. A fact about the row, not about the money — see `AccessVia` for the read-side answer. */
        public GrantSource $source,
        public CarbonInterface $acquiredAt,
        /** When a rental or a windowed license ends. Null means permanent, not unknown. */
        public ?CarbonInterface $expiresAt = null,
        /** The bundle it arrived with, so a library can group what was bought together. */
        public ?string $bundleReference = null,
        /**
         * Where the work came FROM. Provenance, never the seller: the platform is the contracting party for
         * every content flow, and this must never be rendered as "sold by".
         */
        public ?MerchantScope $merchant = null,
    ) {}

    /** Whether this line can be opened right now — granted AND the work is there to hand over. */
    public function isDeliverable(): bool
    {
        return $this->decision->isDeliverable();
    }

    /**
     * Whether to show it as owned-but-unavailable rather than as a download.
     *
     * The state a tombstone exists for: somebody owns a work that has been taken down or has not been
     * released yet. Two different sentences on a screen, one question here.
     */
    public function isOwnedButUnavailable(): bool
    {
        return $this->decision->granted && ! $this->decision->availability->isDeliverable();
    }
}
