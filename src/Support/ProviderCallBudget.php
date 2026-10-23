<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A payer's budget of calls to the payment provider, shared by every screen that spends from it: the account
 * screens of this package and any screen an application builds on the same seams, such as `Invoices::recent()`
 * or `SubscriptionActions::cancel()`. Each spends before it asks the provider, so a payer has one count, not one
 * per screen.
 *
 * One Livewire request may carry up to 50 calls on each of up to 200 component entries, the framework's
 * `payload.max_calls` and `max_components`, and the provider limits the account, not the payer. Without a budget a
 * single request drives thousands of provider calls in one payer's name. A spent budget refuses with a 429 before
 * the provider is asked, and the refusal ends the request rather than answering each further call with nothing, so
 * the rest of the batch never runs.
 *
 * The budget is `billing.provider_budget`: `calls` in every `seconds`, 30 in 10 by default. A `calls` of null
 * switches it off for an application that limits these requests itself; anything else that is not a positive
 * whole number reads as the shipped value.
 */
final readonly class ProviderCallBudget
{
    /** The calls a payer may spend in one window when the config names no positive whole number. */
    public const int CALLS = 30;

    /** The length of that window in seconds when the config names no positive whole number. */
    public const int SECONDS = 10;

    public function __construct(
        private Repository $config,
        private RateLimiter $limiter,
    ) {}

    /**
     * Take one call from the payer's budget, or refuse the request with a 429 that says when a call is free again.
     *
     * `$within` adds windows of one action on top of the shared budget, and they count only the calls spent under
     * the same `$action`. A minute does not bound a month: the provider allots an account its reads per month, and
     * ten plan-change previews a minute held all month come to over 400,000 reads for one owner, so the preview also
     * carries an hour and a day.
     *
     * @param  array<int, int>  $within  how many calls of this action each further window allows, keyed by the window's length in seconds
     *
     * @throws TooManyRequestsHttpException when the budget or one of the windows is spent
     */
    public function spend(Model $billable, string $action = 'call', array $within = []): void
    {
        $id = $billable->getKey();
        $payer = $billable->getMorphClass().':'.(is_scalar($id) ? (string) $id : '');
        $windows = [];
        $budget = $this->budget();

        if ($budget !== null) {
            $windows["billing:provider-budget:{$payer}:{$budget[1]}"] = $budget;
        }

        foreach ($within as $seconds => $allowed) {
            $windows["billing:{$action}:{$payer}:{$seconds}"] = [$allowed, $seconds];
        }

        // Each window is charged first and judged by the count its charge returns. Asked first and charged after, two
        // requests that arrive together both read the last free call and both spend it; the count the cache returns
        // to a charge is that request's own. A refusal gives back every window it charged, the refusing one included,
        // so it spends nothing, not even in the windows it passed.
        $charged = [];

        foreach ($windows as $key => [$allowed, $seconds]) {
            $charged[] = $key;

            if ($this->limiter->hit($key, $seconds) > $allowed) {
                $this->giveBack($charged);

                throw new TooManyRequestsHttpException($this->limiter->availableIn($key));
            }
        }
    }

    /**
     * Give back the call a refused spend charged in each of these windows.
     *
     * The limiter stores a count that falls back to one again, with the decay it is handed, so the decay is the time
     * left in the window rather than its length. Handed the length, the count would outlive the window, and the next
     * window would start counting where the refused one stopped. In a window of one call, a payer who asks again
     * within each window would then be refused for good.
     *
     * @param  list<string>  $keys
     */
    private function giveBack(array $keys): void
    {
        foreach ($keys as $key) {
            $this->limiter->decrement($key, max(1, $this->limiter->availableIn($key)));
        }
    }

    /**
     * The shared budget as calls and the length of their window, or null where the application switched it off.
     *
     * @return array{int, int}|null
     */
    private function budget(): ?array
    {
        $calls = $this->config->get('billing.provider_budget.calls', self::CALLS);

        if ($calls === null) {
            return null;
        }

        $seconds = $this->config->get('billing.provider_budget.seconds', self::SECONDS);

        return [
            is_int($calls) && $calls >= 1 ? $calls : self::CALLS,
            is_int($seconds) && $seconds >= 1 ? $seconds : self::SECONDS,
        ];
    }
}
