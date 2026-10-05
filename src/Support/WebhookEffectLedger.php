<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\WebhookEffectRun;
use Pushery\Billing\Webhooks\RepeatableEffect;

/**
 * Tracks whether an effect has already done its work for a given reference — the dedup that keeps
 * at-least-once provider delivery from running an effect twice, and the record of what still owes work.
 *
 * THE ORDER IS THE DESIGN: claim → run → mark handled, all inside ONE transaction (see
 * HandleWebhookEffect). A claim that is never marked rolls back with the effect that failed, so the work
 * is re-claimable and the provider's retry (or a replay) does it again. Recording the claim as DONE
 * before running the effect — which is what the package used to do — is how a payment-failure notice
 * gets lost forever: the marker survives, the mail does not, and no retry will ever send it.
 *
 * A HANDLED run is never re-claimed. A FAILED or still-PENDING one is: pending means a worker died
 * mid-run without committing, which is indistinguishable from never having run.
 */
final class WebhookEffectLedger
{
    /**
     * Claim the right to run $effect for $reference, returning false when it is already handled. Call
     * this INSIDE the transaction that also runs the effect and marks it handled.
     */
    public function claim(string $provider, string $reference, string $effect, ?int $deliveryId = null): bool
    {
        $run = LockedRow::take($this->runOf($provider, $reference, $effect), [
            'provider' => $provider,
            'reference' => $reference,
            'effect' => $effect,
            'delivery_id' => $deliveryId,
            'status' => WebhookEventState::Pending->value,
            'attempts' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], $created);

        // A fresh claim. An ignored insert, not create: two workers racing the same run must not both get a
        // unique violation — the loser falls through and reads the row the winner wrote.
        if ($created) {
            return true;
        }

        if (! $run->status->isReplayable()) {
            return false; // already handled — the effect's work is done
        }

        // Failed, or pending from a worker that died before committing: re-claim it.
        $run->forceFill([
            'status' => WebhookEventState::Pending,
            'attempts' => $run->attempts + 1,
            'delivery_id' => $deliveryId ?? $run->delivery_id,
        ])->save();

        return true;
    }

    /** Mark the claimed run done. Called inside the same transaction as the effect, never before it. */
    public function markHandled(string $provider, string $reference, string $effect): void
    {
        WebhookEffectRun::model()::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->where('effect', $effect)
            ->update([
                'status' => WebhookEventState::Handled,
                'last_error' => null,
                'handled_at' => Carbon::now(),
            ]);
    }

    /**
     * Let a handled run be claimed again, for an effect that can repeat what it did ({@see RepeatableEffect}).
     *
     * The run goes back to pending, as if its worker had died before committing, so the next claim takes it. A run that
     * is not handled is left as it is, because it can be claimed already.
     *
     * @return bool whether a handled run was released
     */
    public function release(string $provider, string $reference, string $effect): bool
    {
        return WebhookEffectRun::model()::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->where('effect', $effect)
            ->where('status', WebhookEventState::Handled)
            ->update(['status' => WebhookEventState::Pending, 'handled_at' => null]) > 0;
    }

    /**
     * Record that the run failed. Called OUTSIDE the rolled-back transaction — the claim rolled back with
     * it, so this writes the failure fresh, and it is what an operator (and `billing:webhooks:replay`)
     * looks for.
     *
     * The error is fitted to its column first, so the record of a failure cannot fail on its own text.
     */
    public function markFailed(string $provider, string $reference, string $effect, string $error, ?int $deliveryId = null): void
    {
        $error = RedactedError::fit($error);

        DB::transaction(function () use ($provider, $reference, $effect, $error, $deliveryId): void {
            $run = LockedRow::take($this->runOf($provider, $reference, $effect), [
                'provider' => $provider,
                'reference' => $reference,
                'effect' => $effect,
                'delivery_id' => $deliveryId,
                'status' => WebhookEventState::Failed->value,
                'attempts' => 1,
                'last_error' => $error,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            // Never overwrite a handled run with a failure: a late-arriving error from a superseded
            // attempt must not resurrect work that has since succeeded.
            if ($run->status !== WebhookEventState::Handled) {
                $run->forceFill(['status' => WebhookEventState::Failed, 'last_error' => $error])->save();
            }
        }, LockedRow::ATTEMPTS);
    }

    /**
     * The key a delivery's effect runs under where the effect does not choose one.
     *
     * The event id inside the account the delivery came from: a provider's event ids are unique within the
     * account that produced them, and a run keyed on the bare id took two genuine events from two accounts that
     * share an id for one, so the second account's effects were skipped as already done. A platform delivery has
     * no account and keeps the bare id. So does a delivery that already has a run under the bare id, recorded
     * before the key carried the account, so that its retry or replay meets that run instead of starting a
     * second one beside it.
     */
    public function referenceFor(string $provider, string $eventId, ?string $account, string $effect, ?int $deliveryId): string
    {
        if ($account === null || $account === '') {
            return $eventId;
        }

        $recordedBare = $deliveryId !== null
            && $this->runOf($provider, $eventId, $effect)->where('delivery_id', $deliveryId)->exists();

        return $recordedBare ? $eventId : $account.':'.$eventId;
    }

    /** @return Builder<WebhookEffectRun> */
    private function runOf(string $provider, string $reference, string $effect): Builder
    {
        return WebhookEffectRun::model()::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->where('effect', $effect);
    }
}
