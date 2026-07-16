<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Support\UsageGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a request that would take the owner past a BLOCKING metered allowance, with a 429 by default —
 * `->middleware('billing.quota:emails')` (or `billing.quota:emails,5` to check five units). A degrade or
 * fair-use meter is never blocked here (those are not enforcement decisions the transport can make); only
 * a hard-stop / refuse meter past its allowance is refused. A guest passes through — there is no owner to
 * meter.
 *
 * The block is a plain abort so the app renders it however it renders any other error; the richer
 * QuotaExceeded (with the meter, the policy and the remaining allowance) is available to a caller that
 * uses UsageGate directly.
 */
final readonly class EnforceQuota
{
    public function __construct(
        private UsageGate $gate,
        private BillingEntityResolver $resolver,
        private Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $meterKey, string $quantity = '1'): Response
    {
        $actor = Auth::user();

        if ($actor instanceof Model && $this->gate->allows($this->resolver->ownerFor($actor), $meterKey, $this->units($quantity))->blocked()) {
            abort($this->status());
        }

        return $next($request);
    }

    /** The configured block status (429 Too Many Requests by default). */
    private function status(): int
    {
        $status = $this->config->get('billing.quota.status', 429);

        return is_int($status) ? $status : 429;
    }

    /** A positive unit count from the middleware argument, defaulting to 1 for anything malformed. */
    private function units(string $quantity): int
    {
        $units = (int) $quantity;

        return $units > 0 ? $units : 1;
    }
}
