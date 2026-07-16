<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pushery\Billing\Enums\WebhookEventState;
use Pushery\Billing\Models\WebhookEffectRun;

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
        $inserted = WebhookEffectRun::query()->insertOrIgnore([
            'provider' => $provider,
            'reference' => $reference,
            'effect' => $effect,
            'delivery_id' => $deliveryId,
            'status' => WebhookEventState::Pending->value,
            'attempts' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // A fresh claim. insertOrIgnore, not create: two workers racing the same run must not both get a
        // unique violation — the loser falls through and reads the row the winner wrote.
        if ($inserted === 1) {
            return true;
        }

        $run = $this->locked($provider, $reference, $effect);

        if (! $run instanceof WebhookEffectRun || ! $run->status->isReplayable()) {
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
        WebhookEffectRun::query()
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
     * Record that the run failed. Called OUTSIDE the rolled-back transaction — the claim rolled back with
     * it, so this writes the failure fresh, and it is what an operator (and `billing:webhooks:replay`)
     * looks for.
     */
    public function markFailed(string $provider, string $reference, string $effect, string $error, ?int $deliveryId = null): void
    {
        DB::transaction(function () use ($provider, $reference, $effect, $error, $deliveryId): void {
            WebhookEffectRun::query()->insertOrIgnore([
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

            $run = $this->locked($provider, $reference, $effect);

            // Never overwrite a handled run with a failure: a late-arriving error from a superseded
            // attempt must not resurrect work that has since succeeded.
            if ($run instanceof WebhookEffectRun && $run->status !== WebhookEventState::Handled) {
                $run->forceFill(['status' => WebhookEventState::Failed, 'last_error' => $error])->save();
            }
        });
    }

    private function locked(string $provider, string $reference, string $effect): ?WebhookEffectRun
    {
        return WebhookEffectRun::query()
            ->where('provider', $provider)
            ->where('reference', $reference)
            ->where('effect', $effect)
            ->lockForUpdate()
            ->first();
    }
}
