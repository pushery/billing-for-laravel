<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\Exceptions\WithdrawalDeclarationsMissing;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * The point where a buyer's two declarations are taken, and the gate that will not let a purchase past
 * without them.
 *
 * ## What was missing, exactly
 *
 * Measured 2026-08-06: `WithdrawalConsentLedger::record()` had no caller anywhere in the package. Every
 * other part of this path was built — the two declarations as separate fields, the wording version frozen
 * onto the record, the fail-closed gate before provision, the profile that decides which jurisdiction's
 * rules apply. All of it read a consent that nothing ever wrote.
 *
 * The consequence was not a missing feature but an unusable one: with a profile active the gate saw `null`
 * for every purchase and refused every provision of a work whose right ends at delivery. Fail-closed, and
 * therefore safe — but it also meant the profile could not be turned on by anybody, because there was no
 * way to give the consent it demanded.
 *
 * ## Why the key is minted here rather than supplied
 *
 * A declaration must be recorded BEFORE the buyer leaves for the provider — that is the whole point of it —
 * and at that moment the purchase has no reference yet. The add-on row is written by the WEBHOOK effect and
 * its reference comes from the provider's checkout session, so the obvious key does not exist yet.
 *
 * Two candidates, and the choice is decided:
 *
 * **The package mints its own key** (this). It travels to the provider as metadata and comes back on the
 * webhook, which is how the recorded declaration is found again.
 *
 * **The caller supplies one** — their cart id, their order number. Rejected as THE key, though it is welcome
 * beside it. A declaration is a piece of EVIDENCE, and evidence whose uniqueness depends on the discipline
 * of whoever passes it is weak evidence: a reused order number silently lets last month's declaration cover
 * today's purchase, and nothing goes red. That is the same reasoning that already says the provider's own
 * hosted checkout may show the notices but may not replace this record — the proof has to be ours, entirely.
 *
 * ## What this class does NOT do
 *
 * It renders nothing. The wording of the two notices is the operator's and their adviser's, and a package
 * that shipped one would be handing every install the same legal text for a different product in a
 * different jurisdiction. What the package owns is that the answer is taken before the redirect, survives
 * in one shape, and cannot be skipped.
 */
final readonly class PurchaseDeclarations
{
    public function __construct(
        private WithdrawalGate $gate,
        private WithdrawalConsentLedger $ledger,
        private WithdrawalTypeResolver $types,
    ) {}

    /**
     * Write the two declarations down against a fresh key, and hand that key back.
     *
     * The key is what the caller passes to the checkout so the declaration can be found again after the
     * payment. It is opaque on purpose: nothing may be inferred from it, and it identifies exactly one
     * intention to buy.
     *
     * @return string the declaration reference, to be carried through the checkout
     */
    public function declare(Model $owner, WithdrawalConsent $consent): string
    {
        // Random rather than derived. A key built out of the owner and the add-on would collide with the
        // buyer's own second purchase of the same thing -- two purchases, two declarations -- and the
        // collision would resolve to "already on file", which is the failure this whole path exists to
        // prevent, reached from the inside.
        $reference = 'wd_'.bin2hex(random_bytes(16));

        $this->ledger->record($owner, $reference, $consent);

        return $reference;
    }

    /**
     * Refuse to start a checkout whose declarations are not on file.
     *
     * @throws WithdrawalDeclarationsMissing
     */
    public function assertMayCheckout(Model $owner, string $addonKey, ?string $declarationReference): void
    {
        // No profile, no rule. This is the Mode S guarantee and it is checked FIRST, before the catalog is
        // asked anything: an install that has not opted into a consumer-rights regime must not have its
        // checkout changed, refused, or even slowed by a classification lookup it never asked for.
        if (! $this->gate->isEnforced()) {
            return;
        }

        $type = $this->types->forAddon($addonKey);

        // Unclassified refuses. See WithdrawalDeclarationsMissing::unclassified() for why this is the only
        // safe reading of a null with a profile active — the short version is that "nobody classified this"
        // and "this needs no declarations" look identical here, and one of them is a statutory failure.
        if (! $type instanceof WithdrawalType) {
            throw WithdrawalDeclarationsMissing::unclassified($addonKey);
        }

        $consent = $declarationReference === null ? null : $this->ledger->for($owner, $declarationReference);

        // The policy decides, not this class. Which kinds are part-billable, which need declarations at all,
        // and what a complete declaration is are all questions the jurisdiction answers — a consumer under
        // another profile gets a different answer out of the same call.
        if (! $this->gate->mayProvide($type, $consent)) {
            throw WithdrawalDeclarationsMissing::incomplete($addonKey);
        }
    }
}
