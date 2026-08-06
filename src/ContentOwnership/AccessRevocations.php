<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\GrantStatus;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\Models\AddonPurchase;

/**
 * Taking access away — a decision of its own, deliberately not welded to the money.
 *
 * ## Why the two are uncoupled
 *
 * Every combination of "money moved" and "access ended" is a real business, and each of them is somebody's
 * deliberate policy:
 *
 * - A refund that leaves access in place. Common, and often the point: a goodwill refund on a work somebody
 *   has already read costs nothing extra to leave alone, and taking it back turns a recovered customer into
 *   an angry one.
 * - A chargeback that ends access immediately. Involuntary, decided by somebody else, and the money is
 *   already gone.
 * - A takedown with no refund at all. A legal demand does not come with a payment instruction.
 *
 * A build that hard-wired revocation to a refund would make the first impossible and the third
 * unrepresentable. So this is its own action with its own switches, and it moves NO money.
 *
 * ## Uncoupled is not consequence-free
 *
 * Worth being exact, because "decoupled" invites the wrong reading: a refund still runs the whole correction
 * cascade on the money side. What is uncoupled is whether the buyer keeps the file — nothing else.
 *
 * ## The reason is never flattened
 *
 * A statutory withdrawal and a goodwill refund both end in a revoked row, and they are not the same event: one
 * is a right the buyer exercised, the other a decision the platform made. They are counted differently, they
 * are answered for differently, and free text cannot be counted at all — so the reason is a column, and
 * nothing here maps two causes onto one value for convenience.
 *
 * ## Nothing is ever deleted
 *
 * A revoked grant stops granting and stays. "Why can this person no longer read what they bought" is a
 * question somebody will ask, and a deleted row cannot answer it — nor can it satisfy a retention duty that
 * outlives the access by years.
 */
final readonly class AccessRevocations
{
    /**
     * End one grant's access, with the reason on the row.
     *
     * Idempotent, and the FIRST reason wins. A chargeback that arrives after a takedown must not overwrite
     * why access actually ended — the later event did not cause it, and an audit trail that records the last
     * thing to happen rather than the thing that mattered is worse than none.
     */
    public function revoke(AccessGrant $grant, RevokeReason $reason, ?CarbonInterface $at = null): AccessGrant
    {
        if ($grant->status === GrantStatus::Revoked) {
            return $grant;
        }

        $grant->forceFill([
            'status' => GrantStatus::Revoked,
            'revoked_reason' => $reason,
            'revoked_at' => $at ?? Carbon::now(),
        ])->save();

        return $grant;
    }

    /**
     * End every grant that came from one purchase.
     *
     * A purchase can hand over several works — a bundle — and a refund of it is a refund of all of them. The
     * count comes back so a caller can tell "revoked nothing" from "revoked something", which a boolean
     * cannot.
     *
     * @return list<AccessGrant>
     */
    public function revokePurchase(string $sourceReference, RevokeReason $reason, ?CarbonInterface $at = null): array
    {
        /** @var list<AccessGrant> $grants */
        $grants = AccessGrant::query()
            ->where('source_reference', $sourceReference)
            ->where('status', GrantStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(fn (AccessGrant $grant): AccessGrant => $this->revoke($grant, $reason, $at), $grants);
    }

    /**
     * End every grant that came from the purchase a PAYMENT belongs to.
     *
     * A refund and a dispute both name the payment, never the checkout — a provider's dispute object has no
     * session on it at all. The purchase row is the only place the two references meet, so this is the hop
     * that lets a money-side event reach an ownership row. No purchase row, no grants: a payment this
     * install never recorded is not one whose access it can end.
     *
     * @return list<AccessGrant>
     */
    public function revokeForPayment(string $paymentReference, RevokeReason $reason, ?CarbonInterface $at = null): array
    {
        $purchase = AddonPurchase::query()->where('payment_reference', $paymentReference)->first();

        if (! $purchase instanceof AddonPurchase) {
            return [];
        }

        return $this->revokePurchase((string) $purchase->reference, $reason, $at);
    }
}
