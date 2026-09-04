<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * The Mollie driver was selected, but its client cannot be built.
 *
 * There are exactly two reasons and they need different answers, so they get different messages. Sending
 * somebody to check their configuration when the package is missing — or to composer when the key is
 * blank — costs an afternoon each time.
 *
 * The reason this is loud at all: an install that selects the driver without a key does not fail at boot.
 * It fails at the first charge, inside a scheduled run nobody is watching, against a real subscriber, with
 * a stack trace from a third-party HTTP library. Naming the missing thing here is the difference between a
 * five-minute fix and an incident.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MollieNotConfigured extends RuntimeException
{
    public static function missingApiKey(): self
    {
        return new self(
            'The Mollie driver is selected but no API key is configured. Set `billing.mollie.api_key` '.
            '(BILLING_MOLLIE_API_KEY) to your Mollie key — the test key begins `test_`, the live one '.
            '`live_`. A blank value counts as missing: a set-but-empty variable is what a half-finished '.
            'deployment leaves behind, and it reads as configured to anybody looking at the file.'
        );
    }

    /**
     * The key is present but Mollie will not accept its shape.
     *
     * Translated rather than let through, because the SDK's own message arrives with a stack trace from
     * inside a third-party library and names none of our settings — which sends whoever reads it looking
     * for the problem in the wrong package.
     */
    public static function malformedApiKey(string $detail): self
    {
        return new self(
            'The Mollie API key in `billing.mollie.api_key` (BILLING_MOLLIE_API_KEY) was rejected by the '.
            "Mollie client: {$detail} A test key begins `test_`, a live one `live_`. This is a typo or a ".
            'truncated secret rather than a missing one — the value is set, it is simply not a key.'
        );
    }

    public static function missingPackage(string $client): self
    {
        return new self(
            "The Mollie driver is selected but {$client} is not installed. Run `composer require ".
            'mollie/mollie-api-php`. The package is a suggestion rather than a dependency on purpose: an '.
            'install that never selects this driver should not carry an HTTP client it will never call.'
        );
    }
}
