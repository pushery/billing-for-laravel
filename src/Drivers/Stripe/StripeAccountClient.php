<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Pushery\Billing\ValueObjects\PaymentAccount;

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
    public static function for(PaymentAccount $account, string $apiVersion, ?bool $servingRequest = null): TimedStripeClient
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

        $http = self::accountHttp($servingRequest ?? self::insideAWebRequest());
        $config = ['api_key' => $key, 'stripe_version' => $apiVersion];

        if ($http['retries'] !== null) {
            $config['max_network_retries'] = $http['retries'];
        }

        return new TimedStripeClient($config, $http['timeout'], $http['connect_timeout']);
    }

    /**
     * The timeouts and retries of `billing.stripe.account_http`: the `request` set inside a web request, the
     * `background` set in a queue worker, the scheduler and Artisan. A value that is not a usable number keeps
     * the SDK's own.
     *
     * @return array{timeout: int|null, connect_timeout: int|null, retries: int|null}
     */
    private static function accountHttp(bool $servingRequest): array
    {
        $set = Config::get('billing.stripe.account_http.'.($servingRequest ? 'request' : 'background'));
        $set = is_array($set) ? $set : [];
        $timeout = $set['timeout'] ?? null;
        $connectTimeout = $set['connect_timeout'] ?? null;
        $retries = $set['retries'] ?? null;

        return [
            'timeout' => is_int($timeout) && $timeout > 0 ? $timeout : null,
            'connect_timeout' => is_int($connectTimeout) && $connectTimeout > 0 ? $connectTimeout : null,
            'retries' => is_int($retries) && $retries >= 0 ? $retries : null,
        ];
    }

    /** Whether the client is built inside a web request, as the application tells it. */
    private static function insideAWebRequest(): bool
    {
        $application = Container::getInstance();

        return $application instanceof Application && ! $application->runningInConsole();
    }
}
