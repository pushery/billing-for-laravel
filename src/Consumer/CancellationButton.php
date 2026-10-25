<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\ListsContracts;
use Pushery\Billing\Contracts\SubscriptionActions;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\CancellationKind;
use Pushery\Billing\Enums\CancellationTiming;
use Pushery\Billing\Enums\DeclarationStatus;
use Pushery\Billing\Exceptions\DeclarationRefused;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Notifications\CancellationReceivedNotification;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\CancellationSettlement;
use Pushery\Billing\ValueObjects\ContractChoice;
use Pushery\Billing\ValueObjects\DeclaredCancellation;
use Pushery\Billing\ValueObjects\MerchantScope;
use Throwable;

/**
 * The cancellation button of § 312k BGB: a cancellation anybody can declare, signed in or not, kept with the moment
 * it arrived and confirmed in text form.
 *
 * ## The order is the point
 *
 * The declaration is written first, because the row is the receipt. The cancellation runs second, and nothing it
 * does can take the receipt back: whatever the provider answers, the declaration stands. The confirmation goes last,
 * so it can say when the contract ends where that is already known. Your page shows the contracts
 * {@see ListsContracts} lists, posts the person's entries as a {@see DeclaredCancellation}, and shows the receipt.
 *
 * ## What is carried out at once, and what waits for a person
 *
 * One shape is carried out the moment it arrives: a signed-in person, naming one of their own running contracts,
 * declaring an ordinary cancellation at the earliest possible date. The earliest end is the one
 * {@see GermanNoticePeriod} allows. Where it falls inside the period that is paid, the contract ends there and the
 * rest of the period goes back through {@see ProratedCancellation}; otherwise it ends at the period end, which lets
 * the person go no later than the law requires.
 *
 * Every other shape waits for a person, each for a reason of its own. A chosen date can lie past the paid period,
 * and ending the contract now would end it earlier than declared. An extraordinary cancellation turns on whether its
 * reason holds. A declaration made without signing in names its contract in free text, which nothing may turn into
 * somebody's subscription. And a provider that refuses or cannot be reached leaves the declaration where it was,
 * with the class of the failure on the row and never the provider's message, which can carry the customer's details.
 */
final readonly class CancellationButton
{
    public function __construct(
        private ListsContracts $contracts,
        private BillingEventLog $events,
        /**
         * What a carried-out cancellation ends the contract through. Resolved at the point of use, because the bound
         * actions follow the configured driver, and a declaration that waits for a person needs neither.
         */
        private ?SubscriptionActions $actions = null,
        private ?ProratedCancellation $prorated = null,
    ) {}

    /**
     * Take a cancellation in: keep it, carry it out where nothing is left to judge, and confirm it.
     *
     * The declaration is stamped with the moment it arrived and kept in the language the page was in, which is the
     * language the confirmation is written in.
     *
     * @param  ?Model  $owner  the signed-in person, or null for somebody who declared without signing in
     * @param  ?Model  $actor  who pressed the button, where that is somebody other than the owner
     *
     * @throws DeclarationRefused when the page passed the declaration incomplete; nothing is stored then
     */
    public function declare(DeclaredCancellation $declared, ?Model $owner = null, ?Model $actor = null): CancellationDeclaration
    {
        $this->assertComplete($declared);

        $choice = $owner instanceof Model && $declared->contractKey !== null ? $this->choiceOf($owner, $declared->contractKey) : null;
        $now = CarbonImmutable::now();

        $declaration = CancellationDeclaration::model()::query()->create([
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'subscription_id' => $choice?->subscription->getKey(),
            'kind' => $declared->kind,
            'reason' => $declared->kind === CancellationKind::Extraordinary ? trim((string) $declared->reason) : null,
            'name' => trim($declared->name),
            'email' => trim($declared->email),
            'contract' => trim($declared->contract),
            'ends' => $declared->ends,
            'ends_on' => $declared->ends === CancellationTiming::OnDate ? $declared->endsOn?->toDateString() : null,
            'confirmation_email' => trim($declared->confirmationEmail),
            'locale' => Lang::getLocale(),
            'declared_at' => $now,
            'status' => DeclarationStatus::AwaitingReview,
            'owner_erased_at' => $owner instanceof Model ? null : $now,
        ]);

        $this->events->record('cancellation.declared', $declaration, [
            'kind' => $declared->kind->value,
            'ends' => $declared->ends->value,
            'signed_in' => $owner instanceof Model,
        ], AuditSource::Customer, $actor);

        if ($owner instanceof Model && $choice instanceof ContractChoice) {
            $this->carryOut($declaration, $owner, $choice, $actor);
        }

        Notification::route('mail', $declaration->confirmation_email)
            ->notify(new CancellationReceivedNotification($declaration->id)->locale($declaration->locale));

        DeclarationReview::announce($declaration);

        return $declaration;
    }

    /**
     * Record that a person dealt with a declaration, with the end it came to where they set one.
     *
     * The moment it arrived does not move: the cancellation counts from then, whenever somebody resolves it.
     */
    public function resolve(CancellationDeclaration $declaration, ?CarbonInterface $endsAt = null, ?Model $actor = null): CancellationDeclaration
    {
        $declaration->forceFill([
            'status' => DeclarationStatus::Resolved,
            'resolved_at' => CarbonImmutable::now(),
            'ends_at' => $endsAt ?? $declaration->ends_at,
        ])->save();

        $this->events->record('cancellation.resolved', $declaration, [
            'ends_at' => $declaration->ends_at?->toIso8601String(),
        ], AuditSource::Admin, $actor);

        return $declaration;
    }

    /** @throws DeclarationRefused */
    private function assertComplete(DeclaredCancellation $declared): void
    {
        if (trim($declared->name) === '') {
            throw DeclarationRefused::noName();
        }

        if (trim($declared->contract) === '') {
            throw DeclarationRefused::noContract();
        }

        if (filter_var(trim($declared->email), FILTER_VALIDATE_EMAIL) === false) {
            throw DeclarationRefused::invalidEmail();
        }

        if (filter_var(trim($declared->confirmationEmail), FILTER_VALIDATE_EMAIL) === false) {
            throw DeclarationRefused::invalidConfirmationEmail();
        }

        if ($declared->kind === CancellationKind::Extraordinary && trim((string) $declared->reason) === '') {
            throw DeclarationRefused::noReason();
        }

        if ($declared->ends !== CancellationTiming::OnDate) {
            return;
        }

        if (! $declared->endsOn instanceof CarbonImmutable) {
            throw DeclarationRefused::noDate();
        }

        // Compared as days in the zone the declaration arrives in, which is the day the person sees on the page.
        if ($declared->endsOn->toDateString() < CarbonImmutable::now(DeclarationStatement::zone())->toDateString()) {
            throw DeclarationRefused::datePassed();
        }
    }

    /** The contract the key names among the person's contracts now, asked again rather than trusted. */
    private function choiceOf(Model $owner, string $key): ?ContractChoice
    {
        foreach ($this->contracts->contractsOf($owner) as $choice) {
            if (hash_equals($choice->key, $key)) {
                return $choice;
            }
        }

        return null;
    }

    /** End the contract at once where the declaration leaves nothing to judge. */
    private function carryOut(CancellationDeclaration $declaration, Model $owner, ContractChoice $choice, ?Model $actor): void
    {
        if ($declaration->kind !== CancellationKind::Ordinary || $declaration->ends !== CancellationTiming::EarliestPossible) {
            return;
        }

        $subscription = $choice->subscription;
        $periodEnd = $subscription->current_period_end;

        // Without the initial term or the period, the earliest end cannot be worked out, and a guess could end the
        // contract on the wrong day.
        if (! $choice->initialTermEndsAt instanceof CarbonImmutable || $periodEnd === null) {
            $this->awaitReview($declaration, 'term_unknown', null, $actor);

            return;
        }

        $endsAt = GermanNoticePeriod::earliestEnd(
            CarbonImmutable::instance($declaration->declared_at)->setTimezone(DeclarationStatement::zone()),
            $choice->initialTermEndsAt,
        );
        $merchant = MerchantScope::fromUid($subscription->merchant_uid);

        $settlement = null;

        try {
            if ($endsAt->lessThan($periodEnd)) {
                $settlement = ($this->prorated ?? Container::getInstance()->make(ProratedCancellation::class))->cancel(
                    $owner,
                    $endsAt->utc(),
                    $merchant,
                    $subscription->type,
                    reason: 'An ordinary cancellation declared through the cancellation button, ended at the earliest end its notice period allows.',
                    actor: $actor,
                );
            } else {
                ($this->actions ?? Container::getInstance()->make(SubscriptionActions::class))->cancel($owner, null, $merchant, $subscription->type);
            }
        } catch (Throwable $failure) {
            $this->awaitReview($declaration, $failure::class, null, $actor);

            return;
        }

        $end = $settlement instanceof CancellationSettlement ? $settlement->endsAt : CarbonImmutable::instance($periodEnd);

        // The contract ends on the right day either way. A refund the provider refused still leaves the person owed
        // money, so the declaration waits for a person, with the payment a second attempt needs.
        if ($settlement instanceof CancellationSettlement && $settlement->refundRefused) {
            $declaration->forceFill(['ends_at' => $end])->save();
            $this->awaitReview($declaration, 'refund_refused', $settlement->chargeReference, $actor);

            return;
        }

        $declaration->forceFill(['status' => DeclarationStatus::CarriedOut, 'ends_at' => $end])->save();

        $this->events->record('cancellation.carried_out', $declaration, [
            'ends_at' => $end->toIso8601String(),
            'paid_back_minor' => $settlement?->refundable?->minorUnits,
            'currency' => $settlement?->refundable?->currency,
        ], AuditSource::System, $actor);
    }

    /** Leave the declaration for a person, saying why, with the class of a failure and never its message. */
    private function awaitReview(CancellationDeclaration $declaration, string $failure, ?string $chargeReference, ?Model $actor): void
    {
        $declaration->forceFill(['failure' => $failure, 'charge_reference' => $chargeReference])->save();

        $this->events->record('cancellation.awaits_review', $declaration, [
            'failure' => $failure,
            'charge' => $chargeReference,
        ], AuditSource::System, $actor);
    }
}
