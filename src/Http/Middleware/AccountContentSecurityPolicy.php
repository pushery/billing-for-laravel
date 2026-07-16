<?php

declare(strict_types=1);

namespace Pushery\Billing\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Pushery\Billing\Contracts\PaymentCsp;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits a scoped Content-Security-Policy on the account-hub responses only, so the active driver's
 * payment element (js.stripe.com and friends) can load HERE and nowhere else in the host app. The
 * policy starts from a self-only, Livewire/Alpine-safe base, then whitelists the driver's origins and
 * any extra sources the host configures.
 *
 * It is on by default for the package's own shipped views. A host that frames the hub in its own
 * layout with external assets can whitelist those via config('account.csp.additional') or turn the
 * header off entirely with config('account.csp.enabled') — the browser enforces the intersection of
 * every CSP header, so a package-set policy must never fight the host's own.
 */
final readonly class AccountContentSecurityPolicy
{
    public function __construct(
        private PaymentCsp $csp,
        private Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->enabled() && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('account.csp.enabled', true);
    }

    /** Compose the header value: the Livewire-safe base, plus driver origins, plus host additions. */
    private function policy(): string
    {
        $directives = $this->base();

        foreach ([$this->csp->directives(), $this->additional()] as $extra) {
            foreach ($extra as $directive => $sources) {
                $directives[$directive] = [...($directives[$directive] ?? []), ...$sources];
            }
        }

        $lines = [];

        foreach ($directives as $directive => $sources) {
            $lines[] = $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $lines);
    }

    /**
     * The self-only base every account-hub response gets. Scripts and styles allow inline + eval
     * because Livewire and Alpine need them; external origins are added per driver, not opened here.
     *
     * @return array<string, list<string>>
     */
    private function base(): array
    {
        return [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'frame-src' => ["'self'"],
            'connect-src' => ["'self'"],
            // Refuse to be framed by another origin — the money-moving hub must not be clickjacked.
            'frame-ancestors' => ["'self'"],
        ];
    }

    /**
     * Extra CSP sources the host app whitelists for its own account layout, keyed by directive. Anything
     * that is not a directive-name → list-of-string-origins entry is dropped rather than trusted.
     *
     * @return array<string, array<array-key, string>>
     */
    private function additional(): array
    {
        $configured = $this->config->get('account.csp.additional', []);

        if (! is_array($configured)) {
            return [];
        }

        $out = [];

        foreach ($configured as $directive => $sources) {
            if (is_string($directive) && is_array($sources)) {
                $out[$directive] = array_filter($sources, is_string(...));
            }
        }

        return $out;
    }
}
