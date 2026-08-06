<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Consumer\WithdrawalConsentLedger;
use Pushery\Billing\ContentOwnership\ContentGrants;
use Pushery\Billing\Contracts\AddonCatalog;
use Pushery\Billing\Contracts\AddonContentMap;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\ProductTaxonomy;
use Pushery\Billing\Contracts\SuppliesProductArchetypes;
use Pushery\Billing\Enums\TaxArchetype;
use Pushery\Billing\Enums\WithdrawalType;
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
        private AddonCatalog $addons,
        private ProductTaxonomy $taxonomy,
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
            withdrawalType: $this->withdrawalTypeFor($event->addonKey),
            consent: $this->consents->for($owner, $event->reference),
        );
    }

    /**
     * What kind of withdrawal right this add-on carries, or null when nothing classifies it.
     *
     * Two hops, and both may legitimately answer nothing. A catalog that does not supply archetypes at all
     * is the shipped state — `AddonCatalog` is implemented outside this package, so the capability is asked
     * for by type rather than assumed. And an add-on nobody has classified answers null.
     *
     * Null travels on rather than being resolved to a default, and it means "not classified, so nothing to
     * gate" — **on every install, whether or not a consumer-rights profile is set**. `ContentGrants` returns
     * on a null type before the gate is asked, so the profile is never read on this path.
     *
     * That is deliberate: it is what keeps classification from becoming mandatory for an install that does
     * not use it. It is also a hole, and naming it is the point. The withdrawal gate needs TWO conditions to
     * bite — a profile AND a classified archetype — so an operator who sets `BILLING_CONSUMER_RIGHTS_PROFILE`
     * and leaves one work without an `archetype` key gets that work delivered with no consent recorded, and
     * nothing anywhere says so. `billing:doctor` reports exactly that combination; whether the runtime should
     * refuse instead is an open decision rather than a settled one this comment may describe.
     */
    private function withdrawalTypeFor(string $addonKey): ?WithdrawalType
    {
        if (! $this->addons instanceof SuppliesProductArchetypes) {
            return null;
        }

        $archetype = $this->addons->archetypeFor($addonKey);

        if (! $archetype instanceof TaxArchetype) {
            return null;
        }

        // A cell is a fixed value, a delegation to the product a tip was given on, or a state deferred until
        // a later event. Only a fixed one names a withdrawal type here — reading a value out of the other two
        // is what `TaxonomyCell::value()` refuses to allow, and rightly: a tip's withdrawal type belongs to
        // what it was given on, and a voucher's is not decided until it is redeemed. Neither is an add-on this
        // effect can classify on its own, so both answer null and the gate stays out of it.
        $cell = $this->taxonomy->classify($archetype)->withdrawal;

        if (! $cell->isFixed()) {
            return null;
        }

        $value = $cell->value();

        return $value instanceof WithdrawalType ? $value : null;
    }
}
