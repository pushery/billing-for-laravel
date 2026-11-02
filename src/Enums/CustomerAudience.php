<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Consumer\CustomerDeclarations;
use Pushery\Billing\Exceptions\InvalidBillingConfig;

/**
 * Who an application sells to, set once as `billing.audience`.
 *
 * The answer decides whether the consumer rules apply at all. An application that sells only to businesses says so
 * here and then treats every customer as one: no question about the customer type at checkout, no consumer regime,
 * and an annual subscription renews for another year. A consumer who registers there anyway is treated as a business,
 * which holds where the application states the restriction clearly and has it confirmed at the order button.
 * {@see CustomerDeclarations} keeps that confirmation with its wording and its moment.
 */
enum CustomerAudience: string
{
    /** Businesses only. Every customer is treated as a business, and no consumer rule applies. */
    case BusinessOnly = 'business_only';

    /** Businesses and consumers. Each customer states which they are, and a consumer gets the consumer rules. */
    case BusinessAndConsumer = 'business_and_consumer';

    private const string KEY = 'billing.audience';

    /**
     * The audience the configuration names.
     *
     * Unset and empty read as both, the side on which no consumer loses a right. Any other value that is not one of
     * the cases is refused rather than read as either: a typo read as businesses only would switch off every consumer
     * rule, and one read as both would put a question to the customers of an application that sells to businesses.
     *
     * @throws InvalidBillingConfig when the value names neither audience
     */
    public static function configured(Repository $config): self
    {
        $value = $config->get(self::KEY);

        if ($value === null || $value === '') {
            return self::BusinessAndConsumer;
        }

        $audience = is_string($value) ? self::tryFrom($value) : null;

        if (! $audience instanceof self) {
            throw InvalidBillingConfig::forKey(self::KEY, 'must be business_only or business_and_consumer, and it is '
                .json_encode($value, JSON_THROW_ON_ERROR));
        }

        return $audience;
    }
}
