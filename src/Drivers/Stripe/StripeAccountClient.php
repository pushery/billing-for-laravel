<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use InvalidArgumentException;
use Pushery\Billing\ValueObjects\PaymentAccount;
use Stripe\StripeClient;

/**
 * The Stripe client of one payment account, built from the account's secret or restricted key.
 *
 * Stripe decides the mode by the key: `sk_test_` and `rk_test_` reach test mode, `sk_live_` and `rk_live_`
 * reach live mode. An account whose `testmode` disagrees with its key is refused, because the key would win
 * and the account would charge in the mode nobody asked for. The messages name the account and never quote
 * the key.
 */
final class StripeAccountClient
{
    /**
     * @throws InvalidArgumentException when the credential is no Stripe key, or its mode disagrees with the account
     */
    public static function for(PaymentAccount $account, string $apiVersion): StripeClient
    {
        $key = trim($account->credential);

        if (preg_match('/^[sr]k_(test|live)_\S+$/', $key, $mode) !== 1) {
            throw new InvalidArgumentException("The credential of the Stripe payment account `{$account->key}` is no secret or restricted key. A Stripe key begins `sk_test_`, `sk_live_`, `rk_test_` or `rk_live_`.");
        }

        if (($mode[1] === 'test') !== $account->testmode) {
            throw new InvalidArgumentException($account->testmode
                ? "The Stripe payment account `{$account->key}` asks for test mode, and its key is a live key. Stripe decides the mode by the key, so the account would charge real money."
                : "The Stripe payment account `{$account->key}` asks for live mode, and its key is a test key. Stripe decides the mode by the key, so no payment would ever be real.");
        }

        return new StripeClient([
            'api_key' => $key,
            'stripe_version' => $apiVersion,
        ]);
    }
}
