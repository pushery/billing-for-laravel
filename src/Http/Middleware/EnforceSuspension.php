<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Contracts\SuspensionLadder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Locks a delinquent owner out of a named surface with HTTP 423 (Locked). Apply it per surface —
 * `->middleware('billing.suspend:api')` — so different parts of the app can be withdrawn at different
 * rungs of the dunning ladder (config `billing.suspension`). A guest, or an owner who is not
 * delinquent far enough for this surface, passes straight through.
 */
final readonly class EnforceSuspension
{
    public function __construct(
        private SuspensionLadder $ladder,
        private BillingEntityResolver $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $actor = Auth::user();

        // No merchant is passed, and that is a NAMED gap rather than an oversight.
        //
        // The ladder is scoped per merchant — arrears with one creator withdraw that creator's surfaces and
        // nothing else. This middleware guards a route, and a route does not say which merchant it belongs
        // to; deriving one from the request would be this package guessing at a consumer's URL shape. So the
        // scope stays null, which `Subscription::forMerchant()` reads as the platform's own row.
        //
        // For a single-seller install that is exactly right and byte-identical to what it always did. For a
        // marketplace it means this middleware guards the PLATFORM relationship only, and a creator-scoped
        // surface needs a caller that knows its merchant. That caller is the content-access layer, not this
        // one — withdrawing a creator's content is a question about the content, not about the route.
        //
        // Stated here because a scoped guard that nothing ever calls with a scope is the failure this
        // repository keeps finding: the mechanism exists, the reach does not, and nothing goes red.
        if ($actor instanceof Model && $this->ladder->isLockedOut($this->resolver->ownerFor($actor), $surface)) {
            throw new HttpException(423);
        }

        return $next($request);
    }
}
