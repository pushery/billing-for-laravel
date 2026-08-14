<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Pushery\Billing\Consumer\ConformityUpdateGate;
use Pushery\Billing\Consumer\WithdrawalGate;
use Pushery\Billing\Contracts\BundleContents;
use Pushery\Billing\Enums\GrantSource;
use Pushery\Billing\Enums\GrantStatus;
use Pushery\Billing\Enums\WithdrawalType;
use Pushery\Billing\Exceptions\WithdrawalConsentMissing;
use Pushery\Billing\Models\AccessGrant;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * Where ownership rows come from: a purchase, a gift, a comp, a bundle.
 *
 * ## The gate sits here, before the row, and never after
 *
 * Creating the row IS provision. Where a consumer-rights profile is active and the work is one whose right
 * of withdrawal ends on delivery, the buyer's declarations must already be on record — so a missing consent
 * means NO ROW, never "access now, paperwork later". That ordering is the whole protection: once somebody
 * can read the work, the thing the record exists to preserve has already been given away.
 *
 * ## Every entry point writes through one method
 *
 * Four sources, one `record()`. Each has its own front door because they differ in what they require — a
 * gift has a second party, a comp has no contract at all — but they must not differ in what they write, or
 * the register would carry rows that mean subtly different things depending on which door they came in by.
 */
final readonly class ContentGrants
{
    public function __construct(
        private UpdatePolicyResolver $policies,
        private ConformityUpdateGate $conformity,
        private WithdrawalGate $withdrawal,
        private BundleContents $bundles,
        private Repository $config,
    ) {}

    /**
     * A work somebody bought for themselves. A rental is the same call with an expiry.
     *
     * Idempotent on the purchase reference: a redelivered webhook returns the row it already wrote rather
     * than a second one. That matters more here than for most ledgers, because two grants for one work are
     * two revocation targets and revoking one leaves the other granting.
     */
    public function grantPurchase(
        Model $owner,
        ContentReference $content,
        string $sourceReference,
        ?MerchantScope $merchant = null,
        ?CarbonInterface $expiresAt = null,
        ?WithdrawalType $withdrawalType = null,
        ?WithdrawalConsent $consent = null,
        ?string $declarationReference = null,
        ?CarbonInterface $acquiredAt = null,
    ): AccessGrant {
        $this->assertMayProvide($withdrawalType, $consent);

        return $this->record(
            $owner,
            $content,
            GrantSource::Purchase,
            merchant: $merchant,
            sourceReference: $sourceReference,
            expiresAt: $expiresAt,
            withdrawalType: $withdrawalType,
            declarationReference: $declarationReference,
            acquiredAt: $acquiredAt,
            consent: $consent,
        );
    }

    /**
     * A work one person bought for another.
     *
     * The consent parameter is the PURCHASER'S, and the name says so because nothing else can. The buyer is
     * the one with the contract; the recipient has none with us. Declarations collected from the recipient
     * are worth nothing — the right of withdrawal does not end, and every refund inside the window stays a
     * claim rather than a courtesy. That is the single most expensive way to get a gift flow wrong, and it
     * looks completely reasonable while you are building it.
     */
    public function grantGift(
        Model $recipient,
        Model $purchaser,
        ContentReference $content,
        string $sourceReference,
        ?MerchantScope $merchant = null,
        ?WithdrawalType $withdrawalType = null,
        ?WithdrawalConsent $purchaserConsent = null,
        ?string $declarationReference = null,
        ?CarbonInterface $acquiredAt = null,
    ): AccessGrant {
        $this->assertMayProvide($withdrawalType, $purchaserConsent);

        return $this->record(
            $recipient,
            $content,
            GrantSource::Gift,
            merchant: $merchant,
            sourceReference: $sourceReference,
            purchaser: $purchaser,
            withdrawalType: $withdrawalType,
            declarationReference: $declarationReference,
            acquiredAt: $acquiredAt,
            // The PURCHASER'S, because they are the one with the contract. The recipient has none with us,
            // so declarations collected from them are worth nothing — and a window computed from them
            // would be the same mistake wearing a date.
            consent: $purchaserConsent,
        );
    }

    /**
     * A work given rather than sold: a review copy, a goodwill grant, a prize.
     *
     * No money, so no supply and no document. No contract, so no right of withdrawal and no gate — asking
     * for declarations here would be asking somebody to waive a right they never acquired. Both of those are
     * asserted in tests rather than left as a remark, because a comp quietly joining the receipt or consent
     * pipeline later is precisely the kind of drift nobody notices until an auditor does.
     */
    public function comp(
        Model $owner,
        ContentReference $content,
        ?MerchantScope $merchant = null,
        ?CarbonInterface $expiresAt = null,
        ?CarbonInterface $acquiredAt = null,
    ): AccessGrant {
        return $this->record(
            $owner,
            $content,
            GrantSource::Comp,
            merchant: $merchant,
            expiresAt: $expiresAt,
            acquiredAt: $acquiredAt,
        );
    }

    /**
     * Every work the bundle holds AT THIS MOMENT, one row each.
     *
     * Materialised rather than expanded on read, and that is what makes the non-additive default true by
     * construction: a work added to the bundle next month has no row for anybody who bought before, because
     * nobody wrote one. There is nothing to remember to switch off.
     *
     * Calling it again for the same buyer TOPS UP what has since been added — but only where the install has
     * said its bundles work that way. That is the whole meaning of `bundle_additive_default`, and it is a
     * real difference rather than a setting that reads well: with it off, a second call is a no-op and the
     * earlier buyer keeps exactly what the bundle held on the day they bought it.
     *
     * @return list<AccessGrant>
     */
    public function grantBundle(
        Model $owner,
        string $bundleReference,
        string $sourceReference,
        ?MerchantScope $merchant = null,
        ?WithdrawalType $withdrawalType = null,
        ?WithdrawalConsent $consent = null,
        ?string $declarationReference = null,
        ?CarbonInterface $acquiredAt = null,
    ): array {
        $this->assertMayProvide($withdrawalType, $consent);

        $additive = $this->config->get('billing.content_ownership.bundle_additive_default') === true;

        // Whether this buyer already holds part of THIS bundle is the only thing that tells a first purchase
        // apart from a top-up, and the two must behave differently. Counting what this call has written so
        // far would not do it: on a first purchase everything after the first work would look like a top-up.
        $topUp = AccessGrant::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('bundle_ref', $bundleReference)
            ->exists();

        $granted = [];

        foreach ($this->bundles->worksIn($bundleReference) as $work) {
            $existing = $this->existingGrant($owner, $work, $merchant);

            if ($existing instanceof AccessGrant) {
                $granted[] = $existing;

                continue;
            }

            // A work this buyer is missing from a bundle they already hold was added after they bought it.
            // Whether that reaches them is the install's decision, and the shipped answer is no.
            if ($topUp && ! $additive) {
                continue;
            }

            $granted[] = $this->record(
                $owner,
                $work,
                GrantSource::Bundle,
                merchant: $merchant,
                sourceReference: $sourceReference,
                bundleReference: $bundleReference,
                withdrawalType: $withdrawalType,
                declarationReference: $declarationReference,
                acquiredAt: $acquiredAt,
                consent: $consent,
            );
        }

        return $granted;
    }

    /**
     * Mark grants whose term has passed, so a report can count them.
     *
     * Cosmetic on purpose, and worth saying out loud: NOTHING depends on this having run. The reader decides
     * from the dates, so a grant whose window closed stops granting at the instant it closes whether or not
     * this ever executes. A sweep that access depended on would be a sweep that can be late, and late here
     * means serving a work somebody no longer paid for.
     */
    public function expireLapsedGrants(?CarbonInterface $at = null): int
    {
        return AccessGrant::query()
            ->where('status', GrantStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at ?? Carbon::now())
            ->update(['status' => GrantStatus::Expired->value]);
    }

    /**
     * @throws WithdrawalConsentMissing
     */
    private function assertMayProvide(?WithdrawalType $type, ?WithdrawalConsent $consent): void
    {
        // No classification means the install has not opted into a consumer-rights reading for this work, so
        // there is nothing to gate on. The gate itself is inert without a profile anyway; this keeps a
        // single-seller install that never classifies anything from having to pass a type it does not use.
        if (! $type instanceof WithdrawalType) {
            return;
        }

        $this->withdrawal->assertMayProvide($type, $consent);
    }

    /**
     * Write the row, or hand back the one that is already there.
     *
     * The duplicate check runs twice on purpose: once as a read, and once as a rescue when the unique index
     * fires anyway. Two webhook deliveries arriving together both pass the read, and only the database can
     * settle which one wins — so the loser re-reads instead of surfacing a constraint violation to a caller
     * whose only mistake was being retried.
     *
     * Deliberately NOT wrapped in a transaction: on PostgreSQL a constraint violation poisons the whole
     * transaction, so catching one inside it and continuing is not something you can do. One statement,
     * caught outside, is the shape that works on every engine.
     */
    private function record(
        Model $owner,
        ContentReference $content,
        GrantSource $source,
        ?MerchantScope $merchant = null,
        ?string $sourceReference = null,
        ?Model $purchaser = null,
        ?CarbonInterface $expiresAt = null,
        ?string $bundleReference = null,
        ?WithdrawalType $withdrawalType = null,
        ?string $declarationReference = null,
        ?CarbonInterface $acquiredAt = null,
        /**
         * The buyer's declarations, threaded through for the WINDOW rather than for the gate.
         *
         * The gate has already run by the time this is called — that ordering is the point of it — so this
         * is not a second check. What the window needs is a fact the gate does not hand on: whether the
         * right EXTINGUISHED here, which is what a complete consent on an extinguish-on-delivery sale does.
         *
         * Inferring it from "the gate let us through" would work today and would be a rule nobody stated,
         * one gate change away from silently putting a date on a right that no longer exists.
         */
        ?WithdrawalConsent $consent = null,
    ): AccessGrant {
        $existing = $this->existingGrant($owner, $content, $merchant);

        if ($existing instanceof AccessGrant) {
            return $existing;
        }

        $scope = $merchant ?? MerchantScope::platform();
        $acquired = Carbon::parse($acquiredAt ?? Carbon::now());

        $attributes = [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'purchaser_type' => $purchaser?->getMorphClass(),
            'purchaser_id' => $purchaser?->getKey(),
            'content_type' => $content->type,
            'content_ref' => $content->reference,
            'source' => $source,
            'status' => GrantStatus::Active,
            'acquired_at' => $acquired,
            'expires_at' => $expiresAt,
            'source_reference' => $sourceReference,
            // Frozen with the sale, like every other fact a document was made under: a creator who changes
            // their policy tomorrow changes it for future sales, not for one already made.
            'update_policy' => $policy = $this->policies->policyFor($content, $merchant),
            // The LENGTH of a windowed promise, beside the promise itself. It had no writer at all: the
            // column existed, the policy was documented, and every windowed grant came out with a null
            // window — which the resolver correctly reads as a broken row and bounds at the moment of
            // purchase. That is what `frozen` does, so two of the four documented values of a shipped
            // setting were byte-identical, and an operator selling "updates for twelve months" delivered
            // frozen content.
            'update_window_ends_at' => $this->policies->windowEndsFor($policy, $content, $merchant, $acquired),
            'conformity_update_until' => $this->conformity->updatesUntil($acquired),
            'withdrawal_type' => $withdrawalType,
            // Frozen from `$acquired` — the moment provision happened, which is the line above this one and
            // is why this column can exist at all. The configuration used to state that a window was not
            // computable because nothing recorded that moment; this row has recorded it since the grant
            // register landed, and the paragraph outlived its own truth.
            //
            // Frozen rather than derived on read, like every other fact the sale was made under: an
            // operator who changes profile tomorrow changes it for future sales, not for a right somebody
            // already holds.
            'withdrawal_window_ends_at' => $this->withdrawal->windowEndsFor($withdrawalType ?? WithdrawalType::NotApplicable, $consent, $acquired),
            'withdrawal_declaration_ref' => $declarationReference,
            'bundle_ref' => $bundleReference,
            'merchant_uid' => $scope->uid(),
            'merchant_type' => $scope->type,
            'merchant_id' => $scope->id,
        ];

        try {
            return AccessGrant::query()->create($attributes);
        } catch (UniqueConstraintViolationException $collision) {
            $raced = $this->existingGrant($owner, $content, $merchant);

            // Re-thrown rather than papered over: if the row is not there after a uniqueness violation, some
            // OTHER constraint fired, and quietly returning something plausible would hide a real defect
            // behind a retry-handling path.
            if (! $raced instanceof AccessGrant) {
                throw $collision;
            }

            return $raced;
        }
    }

    private function existingGrant(Model $owner, ContentReference $content, ?MerchantScope $merchant): ?AccessGrant
    {
        return AccessGrant::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('content_type', $content->type)
            ->where('content_ref', $content->reference)
            ->where('merchant_uid', ($merchant ?? MerchantScope::platform())->uid())
            ->first();
    }
}
