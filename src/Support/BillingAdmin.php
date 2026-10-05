<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pushery\Billing\Contracts\CreatorTaxStatusResolver;
use Pushery\Billing\Contracts\RoutesMoney;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\ChargeType;
use Pushery\Billing\Enums\RefundAttemptStatus;
use Pushery\Billing\Enums\RefundKind;
use Pushery\Billing\Enums\TaxBaseChangeReason;
use Pushery\Billing\Exceptions\CommissionTermsUnknown;
use Pushery\Billing\Marketplace\ClawbackCalculator;
use Pushery\Billing\Marketplace\RoutedChargeLedger;
use Pushery\Billing\Marketplace\RoutedRefundCorrector;
use Pushery\Billing\Models\BillingEvent;
use Pushery\Billing\Models\MerchantCharge;
use Pushery\Billing\Models\RefundAttempt;
use Pushery\Billing\Models\Subscription;
use Pushery\Billing\ValueObjects\ChargeRouting;
use Pushery\Billing\ValueObjects\MerchantAccountReference;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PlatformFee;
use Pushery\Billing\ValueObjects\RefundResult;

/**
 * The support/admin console core: the three out-of-band operations a support agent performs on an
 * owner's billing — comp a tier, cancel immediately, refund a charge — each recorded on the billing
 * audit ledger, plus a reader for that ledger. It carries no UI and no authorization of its own: an
 * app wires these into its OWN admin panel behind its OWN admin gate. Every action leaves an audit
 * trail so a comp or refund is always traceable to who did it — the app passes the acting agent as $actor.
 */
final readonly class BillingAdmin
{
    public function __construct(
        private SubscriptionActions $actions,
        private BillingManager $manager,
        private BillingEventLog $log,
        private Repository $config,
        private AddonRefunds $refunds,
        /**
         * Where a routed sale is looked up, so a refund knows whether one merchant was paid out of it.
         *
         * Defaulted rather than required: this class is public surface a consumer may construct itself, and
         * a new required argument would be a fatal error in their code. A single-seller install resolves the
         * ledger, finds nothing for its charges, and behaves exactly as it did.
         */
        private RoutedChargeLedger $routed = new RoutedChargeLedger,
        /**
         * The chain corrector and the standing it needs, for a refund on a ROUTED sale.
         *
         * Both nullable and both resolved at the point of use rather than defaulted here: the corrector
         * takes collaborators of its own, so a hand-built default would be a second wiring of the document
         * chain living in a constructor signature. Null means "ask the container when it matters", which is
         * also what keeps a single-seller installation from constructing machinery it never reaches.
         *
         * This is the gap they close. Chargeback, statutory withdrawal and prepaid-term cancellation all
         * corrected the chain; the package's only refund VERB did not. So an ordinary support refund on a
         * routed sale moved the money and left the creator's settlement and the buyer's receipt claiming the
         * full amount — and settlement numbers come from a gapless series, so that state can only be
         * answered by a further correcting document, never tidied away.
         */
        private ?RoutedRefundCorrector $corrector = null,
        private ?CreatorTaxStatusResolver $statuses = null,
    ) {}

    /**
     * Comp an owner onto a tier out of band by writing the tier column directly. Use a tier listed in
     * `billing.untouchable_tiers` so the next provider webhook does not overwrite the grant.
     */
    public function comp(Model $owner, string $tierKey, ?string $reason = null, ?Model $actor = null): void
    {
        $owner->forceFill([$this->tierColumn() => $tierKey])->save();

        $this->log->record('admin.comp', $owner, ['tier' => $tierKey, 'reason' => $reason], AuditSource::Admin, $actor);
    }

    /**
     * Cancel an owner's subscription immediately (support-initiated), recording the reason.
     *
     * Answers whether there was a running subscription to cancel, in the scope and of the type named: the
     * platform's default contract unless a merchant or a type is given. The row read is the one the actions end,
     * the latest in that scope, and it counts as running unless it is terminated or in a terminal state. Nothing
     * running is reported rather than recorded. The actions return quietly when they find no row, and recording
     * the cancel anyway told support a subscription had ended that went on billing.
     */
    public function cancel(Model $owner, ?string $reason = null, ?Model $actor = null, ?MerchantScope $merchant = null, ?string $type = null): bool
    {
        $subscription = Subscription::model()::query()
            ->forOwner($owner)
            ->forMerchant($merchant)
            ->ofType($type)
            ->latest('id')
            ->first();

        if (! $subscription instanceof Subscription || $subscription->terminated() || $subscription->isReplaceableByANewSubscription()) {
            return false;
        }

        $this->actions->cancelNow($owner, $merchant, $type);

        $this->log->record('admin.cancel', $owner, [
            'reason' => $reason,
            ...($merchant instanceof MerchantScope ? ['merchant' => $merchant->uid()] : []),
            ...($type !== null ? ['type' => $type] : []),
        ], AuditSource::Admin, $actor);

        return true;
    }

    /**
     * Refund a charge on the active driver's rails, recording the outcome. The idempotency key makes a
     * double-click or retry safe — pass a stable key per admin action; the default collapses identical
     * refunds of the same charge + amount onto the first, so a retry cannot double-refund.
     *
     * When the refunded charge was a one-time add-on, the credit it granted is clawed back in the same
     * breath (reverse + debit + audit, atomically) — so a support refund is not a double loss: the money
     * goes back AND the customer no longer keeps the credit they were refunded for. A refund of anything
     * that is not a tracked add-on (a subscription invoice) reverses nothing. The provider round-trip is
     * kept OUTSIDE any transaction; only the local reversal is transactional.
     *
     * @param  ?string  $reason  what happened in THIS case, in somebody's own words
     * @param  RefundKind  $kind  what KIND of thing it was — a category the books can group by, which a
     *                            sentence cannot. Trailing and defaulted so every existing call site keeps
     *                            writing the row it wrote before, and `Goodwill` is what those rows are:
     *                            nothing in this package could exercise a withdrawal right yet
     */
    public function refund(Model $owner, string $chargeReference, Money $amount, ?string $reason = null, ?string $idempotencyKey = null, ?Model $actor = null, RefundKind $kind = RefundKind::Goodwill): RefundResult
    {
        // The routed charge, resolved ONCE. The routing and the ledger work both need it, and reading the
        // row twice is two readings that can differ: a concurrent reversal between them would price this
        // refund against one state and cap it against another.
        $charge = $this->routedChargeFor($chargeReference);
        $attempt = $charge instanceof MerchantCharge ? $this->attemptFor($charge, $amount, $idempotencyKey) : null;

        // A refund the caller's key has already made. The provider answered once under that key and the money
        // is booked; asking again would be answered from its idempotency window while that lasts and as a second
        // refund after it, which nothing here would book.
        if ($attempt instanceof RefundAttempt && $attempt->status === RefundAttemptStatus::Succeeded) {
            return $this->madeBy($attempt);
        }

        // The attempt's key when there is one. The row is written BEFORE the provider is called and its id
        // is what the key is derived from, so a retry of the same intent reaches the provider with the same
        // key and is collapsed there -- which a key recomputed from amounts cannot promise, because the
        // amounts are exactly what a partly-applied reversal changes. A caller that named the refund with a
        // key of its own finds that key on the row instead.
        //
        // Unchanged for a single-seller charge. There is no attempt row, so the shipped key is byte-for-byte
        // what it always was.
        $key = $attempt instanceof RefundAttempt
            ? $attempt->idempotency_key
            : $idempotencyKey ?? 'refund:'.$chargeReference.':'.$amount->minorUnits.':'.$amount->currency;

        // WHETHER THIS SALE WAS ROUTED, and it was never asked before. The rails' refund has taken a routing
        // since the marketplace lane was built, and the only caller in the package -- this one -- passed
        // three arguments, so the reversal branch inside it was unreachable from production. A support
        // refund on a routed sale gave the buyer their money back and left the merchant their share.
        //
        // Null for every single-seller charge, because nothing routed it and the ledger holds no row. The
        // shipped payload is then byte-for-byte what it always was.
        $result = $this->manager->driver()->rails()->refund($chargeReference, $amount, $key, $this->routingFor($charge));

        // The attempt gets an outcome either way, and the failure branch is the one worth having: an attempt
        // row with no ending is a reversal nobody can later say was tried. `failRefund` moves no totals, so
        // a refused refund leaves the three cumulative columns exactly where they were.
        if ($attempt instanceof RefundAttempt) {
            $result->successful
                // Book what CAME BACK, not what was asked for. On the separate-transfer lane the rails
                // report no reversal reference on purpose -- the share moved in its own call and refunding
                // the payment does not touch it -- and booking the intent there told a consumer to unwind a
                // payout still sitting with the merchant, while spending the room a later chargeback needs.
                //
                // A null reference is that honest "nothing came back", so it books zero. A reference with no
                // amount beside it means the provider reversed but did not say how much, and there the
                // attempt's own figure is the best available answer, which is what null preserves.
                ? $this->routed->completeRefund(
                    $attempt,
                    $result->reversedTransferReference === null
                        ? new Money(0, $amount->currency)
                        : $result->transferReversed,
                    reversalRequested: $result->reversedTransferReference !== null,
                )
                : $this->routed->failRefund($attempt, 'The provider refused an admin-initiated refund.');
        }

        if ($result->successful) {
            $this->refunds->reverse($chargeReference, $this->refundedHere($owner, $chargeReference, $result, $amount), $reason, AuditSource::Admin, $actor);
            $this->correctChain($chargeReference, $result->amount, $attempt);
        }

        $this->log->record('admin.refund', $owner, [
            'charge' => $chargeReference,
            // The provider's reference for this refund, which tells a second refund from a retry of the first
            // when the add-on reversal sums what was refunded here.
            'refund' => $result->reference,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'reason' => $reason,
            // Beside the reason rather than instead of it. The sentence says what happened in this one
            // case; the kind says which of them this was, and only one of the two can be counted.
            'kind' => $kind->value,
            'successful' => $result->successful,
        ], AuditSource::Admin, $actor);

        return $result;
    }

    /**
     * What this charge has been refunded through here, this refund included: the cumulative total the add-on reversal
     * reads.
     *
     * The reversal takes a cumulative total because the provider's refund webhook reports one, and handing it this
     * refund's own amount made a second partial refund take back only the difference between the two. Summed from
     * this package's record of the refunds of the sale it made, each counted once by the reference the provider gave
     * it, so a retry under the same key is not counted twice. The sum can lag the provider's total and never exceed
     * it: a refund made elsewhere reaches the reversal with the webhook's total, and a webhook that arrived before
     * this call returned leaves nothing new to take back. Adding this amount to what the purchase had reversed would
     * have counted that refund twice.
     */
    private function refundedHere(Model $owner, string $chargeReference, RefundResult $result, Money $amount): Money
    {
        $refunds = [$this->refundIdentity($result->reference, $chargeReference, $amount->minorUnits, $amount->currency) => $amount->minorUnits];

        $recorded = BillingEvent::model()::query()
            ->where('type', 'admin.refund')
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->pluck('payload');

        foreach ($recorded as $payload) {
            // A refund of the platform's own supply is no refund of the sale; recorded before it said so, it carries
            // the one kind that path is taken for.
            if (! is_array($payload) || ($payload['charge'] ?? null) !== $chargeReference || ($payload['currency'] ?? null) !== $amount->currency || ($payload['successful'] ?? null) !== true || ! is_int($payload['amount'] ?? null) || ($payload['supply'] ?? null) === 'platform' || ($payload['kind'] ?? null) === RefundKind::WithdrawnBuyerFee->value) {
                continue;
            }

            $refunds[$this->refundIdentity($payload['refund'] ?? null, $chargeReference, $payload['amount'], $amount->currency)] = $payload['amount'];
        }

        return new Money(array_sum($refunds), $amount->currency);
    }

    /** One refund's identity: the provider's reference, or for a refund recorded without one the key a retry of it collapses onto. */
    private function refundIdentity(mixed $reference, string $chargeReference, int $amount, string $currency): string
    {
        return is_string($reference) && $reference !== '' ? $reference : 'refund:'.$chargeReference.':'.$amount.':'.$currency;
    }

    /**
     * Correct the document chain behind a routed sale, after money has actually gone back.
     *
     * Called only on SUCCESS, and only from the routed branch. A correcting document for money that never
     * moved would state a reduction that did not happen — permanently, out of a gapless series.
     *
     * Three silences here are answers rather than gaps, and each has already been decided elsewhere on the
     * chargeback and prepaid paths: a single-seller charge has no chain; a creator whose row is gone leaves
     * nobody whose standing at the supply can be read, and the money has already gone back, so refusing
     * would strand the buyer's refund over a bookkeeping input; and a sale the collective run has not
     * settled yet is answered by the corrector itself with nulls.
     *
     * The attempt is carried through because THIS path is the one that holds it. It was in scope here all
     * along and stopped at the call, so the documents stating a reversal's consequence could not name the
     * reversal — the one path that could answer the question was the one throwing the answer away. Null on a
     * single-seller charge, where no attempt row was opened at all.
     */
    private function correctChain(string $chargeReference, Money $refunded, ?RefundAttempt $attempt = null): void
    {
        // Its OWN lookup, and deliberately not `routedChargeFor()`. That helper gates on the driver being a
        // `RoutesMoney`, which is the right question for the REVERSAL — the routing handed to the rails is
        // meaningless without it. It is the wrong question for a document: whether the chain needs
        // correcting depends on whether a routed charge exists, not on what the current driver can do.
        //
        // It is also the question the sibling paths ask. `ConsumerWithdrawal` looks the charge up exactly
        // this way, and having the two disagree is how the package ended up correcting the chain on three
        // paths out of four.
        $charge = $this->routed->find($this->manager->driver()->name(), $chargeReference);

        if (! $charge instanceof MerchantCharge) {
            return;
        }

        $merchant = $charge->merchant;

        if (! $merchant instanceof Model) {
            return;
        }

        $corrector = $this->corrector ?? Container::getInstance()->make(RoutedRefundCorrector::class);
        $statuses = $this->statuses ?? Container::getInstance()->make(CreatorTaxStatusResolver::class);

        // Frozen at the supply, not read as of today: a creator who has since registered for VAT must not
        // retroactively change how a sale made before that is corrected.
        //
        // ON ONE LINE, and not a style choice — do not fold it back. php-code-coverage 14 counts the
        // CONTINUATION line of a multi-line ternary as executable and never records it as hit.
        $suppliedOn = $charge->settled_at instanceof Carbon ? CarbonImmutable::parse($charge->settled_at) : CarbonImmutable::now();

        $corrector->correct(
            $charge,
            $refunded,
            $statuses->statusAt($merchant, $suppliedOn),
            CarbonImmutable::now(),
            // Money went back to the buyer. Not a write-off and not a dispute — both correct a different set
            // of links, and naming the reason here keeps that decision out of this class.
            TaxBaseChangeReason::Repaid,
            $attempt,
        );
    }

    /**
     * Return money the PLATFORM took for a supply of its own, on a sale it also collected for somebody else.
     *
     * ## Why this is not {@see self::refund()} with an argument
     *
     * That method returns part of the SALE, and everything it does follows from that: it prices a reversal
     * of the merchant's transfer, it caps against the sale's gross, and it claws back the entitlement the
     * purchase granted. None of the three is right here. A buyer fee granted no entitlement, it never
     * entered the merchant's transfer, and it is not part of the gross those caps are read from — so
     * routing it through that path would take money back off a creator who was never paid it.
     *
     * ## The routing is null ON PURPOSE, and that is the whole mechanism
     *
     * With no routing the rails ask the provider for a plain refund: no transfer reversal, no application-fee
     * refund. The money therefore comes out of the platform's own share, which is exactly where the fee went.
     * Passing the sale's routing would have been the plausible thing to write and would have moved the wrong
     * money in silence — a refund that balances, taken from the wrong party.
     *
     * The caller is responsible for the ceiling: this method returns what the provider was asked for, and
     * the counter that makes a retry a no-op belongs with the row that carries the amount.
     */
    public function refundPlatformSupply(
        Model $owner,
        string $chargeReference,
        Money $amount,
        RefundKind $kind,
        ?string $reason = null,
        ?Model $actor = null,
    ): RefundResult {
        // Derived from the KIND as well as the reference and amount, so the platform's own supply and the
        // sale it rode on cannot collapse into one another at the provider — two refunds of the same cents
        // on the same payment are exactly what an idempotency key is supposed to keep apart, not merge.
        $key = 'refund:'.$kind->value.':'.$chargeReference.':'.$amount->minorUnits.':'.$amount->currency;

        // NO ROUTING ARGUMENT, and its absence is the mechanism rather than an omission. Without one the
        // rails ask for a plain refund — no transfer reversal, no application-fee refund — so the money
        // comes out of the platform's own share, which is where the fee went. Passing this sale's routing
        // would have been the plausible line to write and would have taken the money from the creator.
        //
        // Written as three arguments because the fourth defaults to null and the formatter strips an
        // explicit one; the sentence above is what carries the intent that the argument used to.
        $result = $this->manager->driver()->rails()->refund($chargeReference, $amount, $key);

        $this->log->record('admin.refund', $owner, [
            'charge' => $chargeReference,
            // Not a refund of the sale: what the add-on reversal sums leaves this out.
            'supply' => 'platform',
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'reason' => $reason,
            'kind' => $kind->value,
            'successful' => $result->successful,
        ], AuditSource::Admin, $actor);

        return $result;
    }

    /**
     * The owner's recent billing audit trail, newest first.
     *
     * @return array<int, BillingEvent>
     */
    public function events(Model $owner, int $limit = 50): array
    {
        return BillingEvent::model()::query()
            ->where('subject_type', $owner->getMorphClass())
            ->where('subject_id', $owner->getKey())
            ->latest('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    private function tierColumn(): string
    {
        $column = $this->config->get('billing.tier_column', 'plan');

        return is_string($column) ? $column : 'plan';
    }

    /** The routed charge behind a reference, or null when the payment was never routed at all. */
    private function routedChargeFor(string $chargeReference): ?MerchantCharge
    {
        $driver = $this->manager->driver();

        return $driver instanceof RoutesMoney
            ? $this->routed->find($driver->name(), $chargeReference)
            : null;
    }

    /**
     * Write down what this reversal intends to move, before the provider is asked to move it.
     *
     * The three amounts are recorded together and are NOT proportional to one another — a fee with a fixed
     * component makes the merchant's share of a half refund more than half of their payout — so they are
     * computed once, here, from the terms the SALE was made under rather than from today's configuration.
     *
     * @throws CommissionTermsUnknown when a partial reversal has no terms to price the remainder with
     */
    private function beginReversal(MerchantCharge $charge, Money $amount, ?string $idempotencyKey = null): RefundAttempt
    {
        $terms = $charge->frozenFee();
        $full = $charge->refunded_minor + $amount->minorUnits >= $charge->gross_minor;

        // A row from before the terms were recorded can still be refunded IN FULL: with nothing left of the
        // sale there is no remainder to price, so every rate returns the same figure -- everything the
        // merchant still holds. A PARTIAL one genuinely cannot be computed, and a rate borrowed from today
        // would produce a balanced number belonging to a different sale.
        if (! $terms instanceof PlatformFee) {
            if (! $full) {
                throw CommissionTermsUnknown::forPartialReversal($charge->charge_reference);
            }

            $terms = new PlatformFee;
        }

        [$merchantClawback, $feeReturned] = new ClawbackCalculator()->forRefund($charge, $terms, $amount);

        return $this->routed->beginRefund($charge, $amount, $merchantClawback, $feeReturned, idempotencyKey: $idempotencyKey);
    }

    /**
     * The reversal a refund on a routed sale is made under: the one the caller's own key already opened, or a new one.
     *
     * A caller that names its refund with a key of its own retries under that key, and the retry has to find the
     * row the first try wrote. It used to open a second row, keyed from that row's id, so the provider took the
     * retry for a different refund: a partial one was paid out twice, and a full one came back refused and was
     * booked as a failure. Without a key of the caller's, every call is a refund of its own, as before.
     *
     * The latest row the key opened on this charge answers. One still pending is asked again under its key, and the
     * provider collapses the repeat; one that succeeded is the refund already made. Only a refusal opens another,
     * under the key and a count, because nothing moved under it and the same key would only fetch the refusal back.
     *
     * @throws InvalidArgumentException when the key already names a refund of another charge or another amount
     */
    private function attemptFor(MerchantCharge $charge, Money $amount, ?string $idempotencyKey): RefundAttempt
    {
        if ($idempotencyKey === null) {
            return $this->beginReversal($charge, $amount);
        }

        $opened = RefundAttempt::model()::query()
            ->where('provider', $charge->provider)
            ->where('charge_reference', $charge->charge_reference)
            ->orderBy('id')
            ->get()
            ->filter(static fn (RefundAttempt $attempt): bool => $attempt->idempotency_key === $idempotencyKey || str_starts_with($attempt->idempotency_key, $idempotencyKey.'#'));

        $latest = $opened->last();

        if (! $latest instanceof RefundAttempt) {
            if (RefundAttempt::model()::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                throw new InvalidArgumentException("The idempotency key [{$idempotencyKey}] already names a refund of another charge.");
            }

            return $this->beginReversal($charge, $amount, $idempotencyKey);
        }

        if ($latest->amount_minor !== $amount->minorUnits || $latest->currency !== $amount->currency) {
            throw new InvalidArgumentException("The idempotency key [{$idempotencyKey}] already names a refund of another amount.");
        }

        if ($latest->status === RefundAttemptStatus::Failed) {
            return $this->beginReversal($charge, $amount, $idempotencyKey.'#'.($opened->count() + 1));
        }

        return $latest;
    }

    /**
     * What a refund the caller's key has already made returns, without the provider being asked again.
     *
     * The provider's own reference for the refund is not kept, so the result carries the key the refund was asked
     * under, and the amount the row recorded.
     */
    private function madeBy(RefundAttempt $attempt): RefundResult
    {
        return new RefundResult(
            successful: true,
            reference: $attempt->idempotency_key,
            amount: new Money($attempt->amount_minor, $attempt->currency),
        );
    }

    /**
     * The lane a reversal has to take, built from the charge ALREADY IN HAND, or null when it was not routed.
     *
     * Read off the ROW rather than resolved again, and that is the whole point of the row carrying it. The
     * lane decides how the reversal happens -- a destination charge unwinds its transfer with the refund, a
     * separate transfer needs its own call -- so taking it from today's configuration would reverse an old
     * sale as though it had been made under the current lane, silently and in either direction.
     *
     * A row written before the lane was recorded answers null here, and null is the honest answer: it means
     * nobody can say which reversal this sale needs, and inventing one is how a merchant either keeps a
     * refunded share or receives a flag that does nothing.
     *
     * It used to look the row up itself, from a reference, while `refund()` had just looked up the same row
     * — and `refund()` carried a comment saying the charge was resolved once. The comment described the
     * intent and the code did something else, which is the worse of the two ways to be wrong: a reader
     * checking that invariant found it asserted rather than held.
     *
     * Two reads are two answers. A concurrent reversal landing between them prices this refund against one
     * state and routes it by another, and both figures look entirely reasonable afterwards. Nothing this
     * method reads — the lane, the merchant, the frozen fee — is touched by the claim written in between, so
     * taking the charge as an argument costs nothing and removes the window outright.
     */
    private function routingFor(?MerchantCharge $charge): ?ChargeRouting
    {
        $driver = $this->manager->driver();

        // Null covers both ways there is no routing to describe: a driver that does not route money at all
        // (the charge was never resolved), and a payment this package never routed.
        if (! $driver instanceof RoutesMoney || ! $charge instanceof MerchantCharge) {
            return null;
        }

        $lane = $charge->charge_type;
        $merchant = $charge->merchant;

        // A charge that was erased down to its financial facts keeps its amounts and loses the person, so
        // the merchant is gone while the row remains. There is nobody left to claw back from, and inventing
        // a destination would send a reversal at whoever the reference happens to resolve to today.
        if (! $lane instanceof ChargeType || ! $merchant instanceof Model) {
            return null;
        }

        $account = $driver->marketplaceRails()->accounts()->accountFor($merchant);

        return $account instanceof MerchantAccountReference ? new ChargeRouting(
            destination: $account,
            applicationFee: new Money($charge->fee_minor, $charge->currency),
            type: $lane,
        ) : null;
    }
}
