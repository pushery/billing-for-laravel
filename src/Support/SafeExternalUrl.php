<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;

/**
 * A payment driver hands the package a URL to send the customer to — the hosted checkout, the hosted card
 * page, the billing portal — and the package redirects to it as a full-page navigation. That makes the URL
 * a security boundary: it must be a real, navigable web address and nothing else.
 *
 * This gate lets only an absolute http/https URL with a host through, and refuses everything else:
 *  - a `javascript:` / `data:` / `vbscript:` scheme — script injection on redirect,
 *  - a scheme-relative `//evil.example` or a bare `/path` or `?query` — an open redirect to an attacker's
 *    origin, or a same-origin bounce that defeats the point of a hosted flow,
 *  - credentials in the authority, a backslash, a control character or a space — none belongs in a web
 *    address, and a browser reads some of them otherwise than `parse_url` does,
 *  - anything `parse_url` cannot resolve to a scheme + host.
 *
 * A page of the payment provider passes a second check where the driver reads it from the provider's answer:
 * `onHosts()` also requires `https` and a host the driver serves its pages from. So a tampered payload, a
 * misconfigured driver, or a compromised provider response can never turn a billing link-out into an XSS or
 * an open redirect. A refused URL yields null, and the caller declines to redirect (a Livewire screen simply
 * does not navigate; the portal controller 404s) rather than sending the customer somewhere unsafe.
 */
final class SafeExternalUrl
{
    /** @var list<string> The only schemes a browser navigates as a normal web address. */
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    /** The URL when it is a safe absolute http(s) URL with a host; null otherwise (missing, empty, or unsafe). */
    public static function orNull(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || self::mayOpenAnotherHost($url)) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }

    /**
     * A page of the payment provider, as its answer names it: the URL when `orNull()` lets it through, it is
     * `https`, and its host is one of `$hosts` or one the application lists in `billing.redirect_hosts`.
     * Otherwise null. A URL that was there and is refused is logged with its host, because the customer sees
     * nothing but a button that does not take them anywhere.
     *
     * @param  list<string>  $hosts  the hosts the driver's pages are served from
     */
    public static function onHosts(mixed $url, array $hosts): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $safe = self::orNull($url);

        if ($safe !== null && self::servedFrom($safe, [...$hosts, ...self::listedHosts()])) {
            return $safe;
        }

        Log::warning('billing: a page the payment provider named is not on a host its driver serves pages from, so nobody is sent there', [
            'host' => $safe === null ? null : parse_url($safe, PHP_URL_HOST),
        ]);

        return null;
    }

    /**
     * Whether a browser could open another host than `parse_url` reads. A browser takes a backslash in an
     * http(s) URL for a slash and drops tabs and line breaks, so `https://evil.example\@checkout.stripe.com/`
     * names `checkout.stripe.com` to PHP and opens `evil.example`. Credentials in the authority go with them:
     * no page of a provider carries any, and `https://checkout.stripe.com@evil.example/` reads like the
     * provider to a person.
     */
    private static function mayOpenAnotherHost(string $url): bool
    {
        return str_contains($url, '\\')
            || preg_match('/[[:cntrl:] ]/', $url) === 1
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null;
    }

    /**
     * Whether `$url` is served over https from one of `$hosts`. A host compares without regard to case, as
     * DNS does, and as a whole: `evilcheckout.stripe.com` and `checkout.stripe.com.evil.example` are other
     * hosts than `checkout.stripe.com`.
     *
     * @param  list<string>  $hosts
     */
    private static function servedFrom(string $url, array $hosts): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && strtolower($scheme) === 'https'
            && is_string($host) && in_array(strtolower($host), array_map(strtolower(...), $hosts), true);
    }

    /**
     * The hosts the application adds in `billing.redirect_hosts`, such as the custom domain a Stripe account
     * serves Checkout and the portal from.
     *
     * @return list<string>
     */
    private static function listedHosts(): array
    {
        $configured = Container::getInstance()->make(Repository::class)->get('billing.redirect_hosts', []);
        $listed = [];

        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host) && $host !== '') {
                $listed[] = $host;
            }
        }

        return $listed;
    }
}
