<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pushery\Billing\Enums\GrantStatus;
use Pushery\Billing\Enums\RevokeReason;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\Models\AddonPurchase;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;

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
     * How many grants one revocation pass loads.
     *
     * Five hundred, which is a compromise with a name: small enough that a bestseller's owners never sit in
     * memory at once, large enough that a takedown is not ten thousand round trips.
     */
    private const int REVOKE_CHUNK = 500;

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
     * End every grant on ONE WORK, across all its owners — a takedown order, or a creator's account going.
     *
     * ## Two levels, and this is the second one
     *
     * "The work can no longer be delivered" and "access ends, by force" are different facts, and the package
     * models them separately on purpose. A work whose publication ended answers `ContentAvailability::ContentGone`
     * through the catalog seam and the grant is left ALONE — somebody owning a work that is no longer sold is
     * the ordinary case, not a revocation.
     *
     * This is the other one: a legal order, or an account deletion, where ownership itself has to end. Reach
     * for it only when that is genuinely what happened. Using it for a delisting would take away something
     * people bought.
     *
     * ## Why it is chunked and its siblings are not
     *
     * A purchase has a handful of grants; a popular work can have tens of thousands. `revokePurchase()` may
     * load its rows and does; loading every owner of a bestseller into memory to revoke them is a different
     * shape of operation, and the one that falls over on the day it is actually needed.
     *
     * The scope narrows to one merchant's rows where a marketplace hosts several sellers of the same
     * reference. Left out, every owner of that reference goes — which is right for a legal order against the
     * work and wrong for one against a seller.
     *
     * @return list<AccessGrant> the grants this call ended, in id order
     */
    public function revokeContent(
        ContentReference $content,
        RevokeReason $reason,
        ?MerchantScope $merchant = null,
        ?CarbonInterface $at = null,
    ): array {
        $query = AccessGrant::query()
            // Column order matching `billing_access_grants_content_index` (content_type, content_ref,
            // status), which the migration created for exactly this read and which nothing had ever used.
            ->where('content_type', $content->type)
            ->where('content_ref', $content->reference)
            ->where('status', GrantStatus::Active->value)
            ->when($merchant instanceof MerchantScope, fn (Builder $q): Builder => $q
                ->where('merchant_type', $merchant?->type)
                ->where('merchant_id', $merchant?->id))
            ->orderBy('id');

        return $this->revokeInChunks($query, $reason, $at);
    }

    /**
     * End every grant sold by ONE MERCHANT — the shape a deleted creator account has.
     *
     * `RevokeReason::CreatorDeleted` existed as a case with no producer anywhere, and this axis is what it
     * names. Chunked for the same reason as the work axis: a seller's whole catalog is not a handful of
     * rows, and it is the one call that matters on the day somebody's account goes.
     *
     * Deliberately NOT the same thing as erasing the merchant. Erasure is about a person's data; this is
     * about what their buyers may still reach, and the two have different answers — a financial record
     * survives an erasure, while access does not survive a deletion.
     *
     * @return list<AccessGrant> the grants this call ended, in id order
     */
    public function revokeForMerchant(MerchantScope $merchant, RevokeReason $reason, ?CarbonInterface $at = null): array
    {
        $query = AccessGrant::query()
            // Matching `billing_access_grants_merchant_index` (merchant_type, merchant_id, status).
            ->where('merchant_type', $merchant->type)
            ->where('merchant_id', $merchant->id)
            ->where('status', GrantStatus::Active->value)
            ->orderBy('id');

        return $this->revokeInChunks($query, $reason, $at);
    }

    /**
     * Walk a revocation query in chunks, revoking as it goes.
     *
     * `chunkById` rather than `chunk`: revoking changes the `status` these queries filter on, so a plain
     * offset walk would skip every second page as the result set shrinks under it — a bug that leaves half a
     * takedown undone and reports success.
     *
     * @param  Builder<AccessGrant>  $query
     * @return list<AccessGrant>
     */
    private function revokeInChunks(Builder $query, RevokeReason $reason, ?CarbonInterface $at): array
    {
        $revoked = [];

        $query->chunkById(self::REVOKE_CHUNK, function (Collection $grants) use (&$revoked, $reason, $at): void {
            foreach ($grants as $grant) {
                $revoked[] = $this->revoke($grant, $reason, $at);
            }
        });

        return $revoked;
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
