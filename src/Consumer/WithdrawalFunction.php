<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Contracts\ListsContracts;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Enums\DeclarationStatus;
use Pushery\Billing\Exceptions\DeclarationRefused;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Pushery\Billing\Notifications\WithdrawalReceivedNotification;
use Pushery\Billing\Support\BillingEventLog;
use Pushery\Billing\ValueObjects\ContractChoice;
use Pushery\Billing\ValueObjects\DeclaredWithdrawal;
use Pushery\Billing\ValueObjects\MerchantScope;
use Pushery\Billing\ValueObjects\WithdrawalSettlement;
use Throwable;

/**
 * The withdrawal function of § 356a BGB: a withdrawal sent through the business's own pages, kept with its content
 * and the moment it arrived, and confirmed in text form at once, whether or not it can be carried out.
 *
 * ## The order is the point
 *
 * Keeping the declaration, stamping when it arrived and confirming its receipt are what the law asks for, and none of
 * them may depend on a payment provider being reachable. So the declaration is written first, the confirmation goes
 * second, and the withdrawal is carried out last: every way that can fail leaves the declaration where it was,
 * waiting for a person, with the moment it arrived unchanged. Your page shows the contracts {@see ListsContracts}
 * lists, posts the person's entries as a {@see DeclaredWithdrawal}, and shows the receipt.
 *
 * ## What is carried out at once
 *
 * A signed-in person withdrawing from one of their own contracts: {@see ConsumerWithdrawal::withdrawSubscription()}
 * ends it and refunds the period less the value of what was already provided. A closed withdrawal window, a contract
 * that is gone, a provider that cannot be reached and a refund the provider refused all leave the declaration for a
 * person, with the class of the failure on the row and never the provider's message, which can carry the customer's
 * details. A refused refund is one of them although the contract has ended: the person is still owed the money, and
 * marking the declaration done would take the one case that needs somebody off the list. A declaration made without
 * signing in names its contract in free text, which nothing may turn into somebody's subscription, so it waits too.
 */
final readonly class WithdrawalFunction
{
    public function __construct(
        private ListsContracts $contracts,
        private BillingEventLog $events,
        /** Resolved at the point of use, so a declaration that waits for a person reads no driver at all. */
        private ?ConsumerWithdrawal $withdrawals = null,
    ) {}

    /**
     * Take a withdrawal in: keep it, confirm it, and then carry it out where it can be.
     *
     * @param  ?Model  $owner  the signed-in person, or null for somebody who declared without signing in
     * @param  ?Model  $actor  who pressed the button, where that is somebody other than the owner
     *
     * @throws DeclarationRefused when the page passed the declaration incomplete; nothing is stored then
     */
    public function declare(DeclaredWithdrawal $declared, ?Model $owner = null, ?Model $actor = null): WithdrawalDeclaration
    {
        $this->assertComplete($declared);

        $choice = $owner instanceof Model && $declared->contractKey !== null ? $this->choiceOf($owner, $declared->contractKey) : null;
        $now = CarbonImmutable::now();

        $declaration = WithdrawalDeclaration::model()::query()->create([
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'subscription_id' => $choice?->subscription->getKey(),
            'name' => trim($declared->name),
            'contract' => trim($declared->contract),
            'confirmation_email' => trim($declared->confirmationEmail),
            'locale' => Lang::getLocale(),
            'declared_at' => $now,
            'status' => DeclarationStatus::AwaitingReview,
            'owner_erased_at' => $owner instanceof Model ? null : $now,
        ]);

        $this->events->record('withdrawal.declared', $declaration, [
            'signed_in' => $owner instanceof Model,
        ], AuditSource::Customer, $actor);

        Notification::route('mail', $declaration->confirmation_email)
            ->notify(new WithdrawalReceivedNotification($declaration->id)->locale($declaration->locale));

        if ($owner instanceof Model && $choice instanceof ContractChoice) {
            $this->carryOut($declaration, $owner, $choice, $actor);
        }

        return $declaration;
    }

    /**
     * Record that a person dealt with a declaration.
     *
     * The moment it arrived does not move: the withdrawal counts from when it was sent, whenever somebody resolves it.
     */
    public function resolve(WithdrawalDeclaration $declaration, ?Model $actor = null): WithdrawalDeclaration
    {
        $declaration->forceFill([
            'status' => DeclarationStatus::Resolved,
            'resolved_at' => CarbonImmutable::now(),
        ])->save();

        $this->events->record('withdrawal.resolved', $declaration, [], AuditSource::Admin, $actor);

        return $declaration;
    }

    /** @throws DeclarationRefused */
    private function assertComplete(DeclaredWithdrawal $declared): void
    {
        if (trim($declared->name) === '') {
            throw DeclarationRefused::noName();
        }

        if (trim($declared->contract) === '') {
            throw DeclarationRefused::noContract();
        }

        if (filter_var(trim($declared->confirmationEmail), FILTER_VALIDATE_EMAIL) === false) {
            throw DeclarationRefused::invalidConfirmationEmail();
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

    /** End the contract and settle it, and leave the declaration for a person where either cannot be done. */
    private function carryOut(WithdrawalDeclaration $declaration, Model $owner, ContractChoice $choice, ?Model $actor): void
    {
        $subscription = $choice->subscription;

        try {
            $settlement = ($this->withdrawals ?? Container::getInstance()->make(ConsumerWithdrawal::class))->withdrawSubscription(
                $owner,
                MerchantScope::fromUid($subscription->merchant_uid),
                reason: 'A withdrawal declared through the withdrawal function.',
                actor: $actor,
                type: $subscription->type,
            );
        } catch (Throwable $failure) {
            $this->awaitReview($declaration, $failure::class, null, $actor);

            return;
        }

        if ($settlement instanceof WithdrawalSettlement && $settlement->refundRefused) {
            $this->awaitReview($declaration, 'refund_refused', $settlement->chargeReference, $actor);

            return;
        }

        $declaration->forceFill(['status' => DeclarationStatus::CarriedOut])->save();

        // Null throughout when no payment covered the period: the contract ended and nothing moved, and zeroes here
        // would read as a refund of nothing rather than as the absence of one.
        $this->events->record('withdrawal.carried_out', $declaration, [
            'paid_back_minor' => $settlement?->refundable->minorUnits,
            'kept_back_minor' => $settlement?->retained->minorUnits,
            'currency' => $settlement?->refundable->currency,
        ], AuditSource::System, $actor);
    }

    /** Leave the declaration for a person, saying why, with the class of a failure and never its message. */
    private function awaitReview(WithdrawalDeclaration $declaration, string $failure, ?string $chargeReference, ?Model $actor): void
    {
        $declaration->forceFill(['failure' => $failure, 'charge_reference' => $chargeReference])->save();

        $this->events->record('withdrawal.awaits_review', $declaration, [
            'failure' => $failure,
            'charge' => $chargeReference,
        ], AuditSource::System, $actor);
    }
}
