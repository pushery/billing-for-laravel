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

        if ($actor instanceof Model && $this->ladder->isLockedOut($this->resolver->ownerFor($actor), $surface)) {
            abort(423);
        }

        return $next($request);
    }
}
