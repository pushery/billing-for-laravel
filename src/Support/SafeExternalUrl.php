<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

/**
 * A payment driver hands the package a URL to send the customer to — the hosted checkout, the hosted card
 * page, the billing portal — and the package redirects to it as a full-page navigation. That makes the URL
 * a security boundary: it must be a real, navigable web address and nothing else.
 *
 * This gate lets ONLY an absolute http/https URL with a host through, and refuses everything else:
 *  - a `javascript:` / `data:` / `vbscript:` scheme — script injection on redirect,
 *  - a scheme-relative `//evil.example` or a bare `/path` or `?query` — an open redirect to an attacker's
 *    origin, or a same-origin bounce that defeats the point of a hosted flow,
 *  - anything `parse_url` cannot resolve to a scheme + host.
 *
 * So a tampered payload, a misconfigured driver, or a compromised provider response can never turn a billing
 * link-out into an XSS or an open redirect. A refused URL yields null, and the caller declines to redirect
 * (a Livewire screen simply does not navigate; the portal controller 404s) rather than sending the customer
 * somewhere unsafe.
 */
final class SafeExternalUrl
{
    /** @var list<string> The only schemes a browser navigates as a normal web address. */
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    /** The URL when it is a safe absolute http(s) URL with a host; null otherwise (missing, empty, or unsafe). */
    public static function orNull(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
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
}
