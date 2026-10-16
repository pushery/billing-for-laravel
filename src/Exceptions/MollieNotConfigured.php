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

    /** The credential of a payment account is neither an API key nor an OAuth access token. */
    public static function malformedAccountCredential(string $account): self
    {
        return new self(
            "The credential of the Mollie payment account `{$account}` was rejected by the Mollie client. An API key begins ".
            '`test_` or `live_`, an OAuth access token `access_`. The value is set, it is simply neither.'
        );
    }

    /** A payment account asks for one mode while its API key belongs to the other. */
    public static function accountModeDisagrees(string $account, bool $testmode): self
    {
        return $testmode
            ? new self("The Mollie payment account `{$account}` asks for test mode, and its API key is a live key. Mollie decides the mode by the key, so the account would charge real money: give it a `test_` key, or set its testmode to false.")
            : new self("The Mollie payment account `{$account}` asks for live mode, and its API key is a test key. Mollie decides the mode by the key, so no payment would ever be real: give it a `live_` key, or set its testmode to true.");
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
