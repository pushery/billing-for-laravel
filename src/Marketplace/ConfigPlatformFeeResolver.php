<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\PlatformFeeResolver;
use Pushery\Billing\Enums\RoundingResidual;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\ValueObjects\PlatformFee;

/**
 * The platform's commission, read from configuration.
 *
 * The default is zero on both parts, and that is a position rather than a placeholder: a package that
 * shipped a take rate would be choosing a consumer's commercial terms for them, and a rate nobody set is
 * far more likely to be an oversight than an intention.
 *
 * The rounding direction is read here rather than passed by argument order. Which side keeps the leftover
 * cent of an uneven split is a disclosed contract term — at volume it is real money, every uneven
 * transaction — so it must be a value somebody set and can point at, not a consequence of which parameter
 * happened to come first at a call site.
 */
final readonly class ConfigPlatformFeeResolver implements PlatformFeeResolver
{
    public function __construct(private Repository $config) {}

    public function feeFor(Model $merchant): PlatformFee
    {
        return new PlatformFee(
            bps: $this->intValue('default_bps'),
            flatMinor: $this->intValue('default_flat_minor'),
            residual: $this->residual(),
        );
    }

    /**
     * A configured integer, refused rather than coerced when it is not one.
     *
     * A rate that arrived as a string or an array would silently become 0 under a cast, and a zero rate is
     * indistinguishable from a platform that deliberately takes nothing. That is the wrong kind of quiet:
     * the operator would see sales settle in full to their creators and have no reason to look.
     */
    private function intValue(string $key): int
    {
        $value = $this->config->get("billing.marketplace.fee.{$key}", 0);

        if (! is_int($value)) {
            throw InvalidBillingConfig::forKey(
                "billing.marketplace.fee.{$key}",
                'must be an integer; a fee that cannot be read is not a fee of zero',
            );
        }

        return $value;
    }

    /** Which side of an uneven split keeps the leftover minor unit. */
    private function residual(): RoundingResidual
    {
        $value = $this->config->get('billing.marketplace.fee.rounding', 'platform_first');

        return RoundingResidual::fromConfigured($value) ?? throw InvalidBillingConfig::forKey(
            'billing.marketplace.fee.rounding',
            "must be 'platform_first' or 'creator_first'",
        );
    }
}
