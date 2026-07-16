<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The external-billing "link-out" surface for a No-/external-Merchant-of-Record mode. When a consumer runs
 * billing through an external MoR — an app store's subscription management, a Lane/Fuel external portal —
 * `config('billing.link_out')` holds that portal's URL, and the account hub links OUT to it instead of
 * offering in-app checkout (which would move money the external MoR, not this app, is the merchant of
 * record for).
 *
 * The URL passes through {@see SafeExternalUrl}, so a tampered or misconfigured value can never become an
 * open redirect: an unsafe or missing value simply turns link-out OFF (url() is null), and the hub falls
 * back to its in-app flow rather than sending the customer somewhere unsafe.
 */
final readonly class LinkOut
{
    public function __construct(private Repository $config) {}

    /** The safe external billing URL when link-out is configured, or null (link-out off, or an unsafe value). */
    public function url(): ?string
    {
        return SafeExternalUrl::orNull($this->config->get('billing.link_out'));
    }

    /** Whether the hub is in external-billing link-out mode — a safe external URL is configured. */
    public function active(): bool
    {
        return $this->url() !== null;
    }
}
