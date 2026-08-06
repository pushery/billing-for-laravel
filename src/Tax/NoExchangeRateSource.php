<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonImmutable;
use Override;
use Pushery\Billing\Contracts\ExchangeRateSource;
use Pushery\Billing\Enums\ExchangeRateBasis;
use Pushery\Billing\Exceptions\ExchangeRateUnavailable;

/**
 * The shipped default: a source that holds no rates and says so.
 *
 * This package ships no exchange rates on purpose. Which rate is the correct one is jurisdiction knowledge,
 * and the rules contradict each other — the German domestic rule takes the ministry's monthly average while
 * OSS expressly excludes monthly averages, on the same turnover. A package-level default would therefore be
 * wrong for somebody by law, not by oversight.
 *
 * So the seam is bound to a refusal rather than left unbound. The difference matters at the moment it
 * fires: an unbound contract produces a container resolution error naming an interface, which reads as a
 * wiring mistake in the consumer's application. This produces a sentence saying no rates are configured,
 * that the package ships none, and what to do about it.
 *
 * A single-currency install never reaches this. It never converts, so it never asks — which is also why
 * binding a refusal costs such an install nothing.
 */
final readonly class NoExchangeRateSource implements ExchangeRateSource
{
    #[Override]
    public function rateFor(string $from, string $to, CarbonImmutable $on, ExchangeRateBasis $basis): FrozenExchangeRate
    {
        throw ExchangeRateUnavailable::noSourceConfigured();
    }
}
