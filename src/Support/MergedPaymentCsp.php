<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Pushery\Billing\Contracts\PaymentCsp;

/**
 * The CSP sources of several providers together, for a page that offers the payments of more than one, such as a
 * shop with a Mollie and a Stripe account.
 *
 * Each source appears once per directive, in the order the providers named it.
 */
final readonly class MergedPaymentCsp implements PaymentCsp
{
    /** @var list<PaymentCsp> */
    private array $sources;

    public function __construct(PaymentCsp ...$sources)
    {
        $this->sources = array_values($sources);
    }

    public function directives(): array
    {
        $merged = [];

        foreach ($this->sources as $source) {
            foreach ($source->directives() as $directive => $hosts) {
                $merged[$directive] = array_values(array_unique([...$merged[$directive] ?? [], ...$hosts]));
            }
        }

        return $merged;
    }
}
