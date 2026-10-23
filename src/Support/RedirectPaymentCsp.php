<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\PaymentCsp;

/**
 * The one source a page needs that sends a customer to a provider's hosted page by submitting a form and following
 * the redirect it gets back, and mounts nothing of the provider's: `form-action` with the origins of the hosted
 * pages. A browser checks a redirect that follows a form submission against the `form-action` of the page that
 * submitted it. Nothing here lets the provider run script, open a frame or connect from the page.
 */
final readonly class RedirectPaymentCsp implements PaymentCsp
{
    /** @var list<string> */
    private array $hosts;

    /**
     * @param  list<string>  $hosts  the hosts of the provider's hosted pages, without a scheme
     */
    public function __construct(array $hosts)
    {
        $this->hosts = array_values(array_unique($hosts));
    }

    public function directives(): array
    {
        return $this->hosts === [] ? [] : ['form-action' => array_map(static fn (string $host): string => 'https://'.$host, $this->hosts)];
    }
}
