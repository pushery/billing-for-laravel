<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Consumer\WithdrawalConsentLedger;
use Pushery\Billing\Consumer\WithdrawalTypeResolver;
use Pushery\Billing\ContentOwnership\ContentGrants;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Events\AddonPurchased;
use Pushery\Billing\ValueObjects\ContentReference;

/**
 * Turns a paid one-off purchase into an ownership row, when the thing bought was a work.
 *
 * ## Why this is a second effect rather than a branch inside the crediting one
 *
 * `CreditAddonPurchase` claims the purchase reference and applies credit or units; it is the money path and
 * it is idempotent through that claim. Ownership is a different fact with a different lifetime, and folding
 * it in would mean a failure in either half rolls back the other — a buyer who is charged, credited, and
 * then loses the row saying they own what they paid for, because something unrelated threw.
 *
 * Both effects are safe to run twice, so the split costs nothing and buys independence.
 *
 * ## Off unless the install says which of its products are works
 *
 * The package cannot know whether an add-on is a thousand credits or a novel. With the shipped map — which
 * answers "not a work" to everything — this effect writes nothing at all, and an install that sells no works
 * has a purchase path byte-for-byte what it was.
 */
final readonly class GrantPurchasedContent
{
    public function __construct(
        private CustomerDirectory $directory,
        private AddonContentMap $works,
        private ContentGrants $grants,
        private Repository $config,
        private WithdrawalTypeResolver $types,
        private WithdrawalConsentLedger $consents,
    ) {}

    public function __invoke(AddonPurchased $event): void
    {
        if ($this->config->get('billing.content_ownership.enabled') !== true) {
            return;
        }

        $content = $this->works->contentFor($event->addonKey);

        if (! $content instanceof ContentReference) {
            return;
        }

        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return;
        }

        // The checkout reference, not the payment id: it is the key a redelivered webhook repeats, so it is
        // the one that makes a replay return the row it already wrote instead of a second one.
        //
        // THE TYPE AND THE CONSENT ARE WHAT ARM THE GATE, and until now neither was passed. `ContentGrants`
        // returns before consulting the withdrawal gate when it gets no type, so the fail-closed check
        // before provision — built, bound, tested and reachable — could not fire on any install however it
        // was configured. Passing a type nobody supplies is the whole of the defect; the rest of that path
        // was already correct.
        $this->grants->grantPurchase(
            $owner,
            $content,
            $event->reference,
            withdrawalType: $this->types->forAddon($event->addonKey),
            // The MINTED key first, the session reference second. The minted one is what the declarations
            // were actually written against -- it had to be, because at the moment they were made this
            // purchase had no reference of any kind. The fallback covers an install that records against
            // the session reference itself out of band, which is the only other shape this ledger has ever
            // been written in.
            consent: $this->consents->for($owner, $event->declarationReference ?? $event->reference),
        );
    }
}
