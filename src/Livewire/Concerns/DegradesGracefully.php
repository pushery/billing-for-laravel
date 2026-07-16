<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A per-panel error boundary for account-hub screens. A billing screen assembles its data from things
 * that can fail for reasons outside the app's control — a provider API blip, a project's own UsageProvider
 * throwing. Without a boundary, one failing panel 500s the whole account page; a customer who came to
 * cancel cannot even reach the button because the usage gauge could not load.
 *
 * `orDegrade()` runs a panel's data assembly and, on an UNEXPECTED failure, returns the given fallback and
 * marks the screen degraded so the view shows an inline "temporarily unavailable" notice while everything
 * else renders. Two things it deliberately does NOT do:
 *
 * - It never softens an authorization failure. A `403`/`404` (a Symfony {@see HttpExceptionInterface}) or a
 *   Gate {@see AuthorizationException} is RE-THROWN, so a security decision reaches the customer as the
 *   status it is — never quietly redrawn as a "temporarily unavailable" card.
 * - It never logs the exception's message, stack, or any provider detail (a Stripe error carries the
 *   customer's email and the request that failed). Only the exception's CLASS NAME is logged — enough to
 *   see what kind of failure degraded a panel, without spilling PII into the log.
 *
 * The `$degraded` flag is reset before every render (a Livewire `boot` hook), so a `$refresh` that now
 * succeeds clears the notice on its own — there is no stale flag to reset by hand.
 */
trait DegradesGracefully
{
    /** Whether a panel degraded to its fallback this render. Public so the Blade view can read it. */
    public bool $degraded = false;

    /** Livewire calls this before every request's render, so a recovered refresh starts clean. */
    public function bootDegradesGracefully(): void
    {
        $this->degraded = false;
    }

    /**
     * Resolve a panel's data; on an unexpected failure, mark the screen degraded and return $fallback so the
     * rest of the screen still renders. Authorization failures propagate — they are not degraded.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $resolve
     * @param  TValue  $fallback
     * @return TValue
     */
    protected function orDegrade(callable $resolve, mixed $fallback = null): mixed
    {
        try {
            return $resolve();
        } catch (HttpExceptionInterface|AuthorizationException $e) {
            // A 403/404 is a decision, not an outage — it must never soften to a "try again" card.
            throw $e;
        } catch (Throwable $e) {
            // Class name only: an exception message can carry the customer's email or the failing request.
            Log::warning('A billing account-hub panel degraded to its fallback.', ['exception' => $e::class]);

            $this->degraded = true;

            return $fallback;
        }
    }
}
