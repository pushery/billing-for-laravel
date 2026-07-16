<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\DunningGuard;
use Pushery\Billing\Enums\SubscriptionState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an owner whose payment has failed (a hard-dunning `past_due`/`incomplete` state) from a
 * surface — `->middleware('billing.dunning')` — and sends them somewhere they can fix it.
 *
 * The response mirrors Laravel's own auth middleware: a browser request is REDIRECTED to the payment
 * recovery screen (so the customer lands on the "update your card" page, not a dead error), while an
 * API / JSON request gets an HTTP 402 (Payment Required) it can react to. The recovery screen itself is
 * never blocked — otherwise the redirect would loop the customer away from the one page that unblocks
 * them. A guest, or an owner who is not in a blocking dunning state, passes straight through.
 *
 * The blocking decision comes from {@see DunningGuard}: it reads only the local subscription row (no
 * provider call), so this stays cheap and outage-safe on the hot path.
 */
final readonly class EnforceDunning
{
    public function __construct(
        private DunningGuard $guard,
        private BillingEntityResolver $resolver,
        private Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = Auth::user();

        if ($actor instanceof Model
            && ! $request->routeIs('billing.account.recovery')
            && $this->guard->blockingState($this->resolver->ownerFor($actor)) instanceof SubscriptionState) {

            if (! $request->expectsJson() && Route::has('billing.account.recovery')) {
                return redirect()->route('billing.account.recovery');
            }

            abort($this->status());
        }

        return $next($request);
    }

    /** The configured block status for a non-browser request (402 Payment Required by default). */
    private function status(): int
    {
        // A dedicated key, not `billing.dunning.status`: `billing.dunning` is the ladder (a list), so a
        // nested `status` there would be unreadable — this HTTP status is a sibling of the ladder.
        $status = $this->config->get('billing.dunning_status', 402);

        return is_int($status) ? $status : 402;
    }
}
