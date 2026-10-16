<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Mollie refused or garbled a step of connecting a merchant's account: a code, a refresh, a revocation or a
 * client link.
 *
 * The message names the OAuth app by its client id and quotes Mollie's own error, never a token or a secret.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping a fragment
 * measures where the line was wrapped, not what a test asserts. The values themselves are held by a dedicated
 * guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class MollieConnectFailed extends RuntimeException
{
    /** Mollie answered the token endpoint with an error. */
    public static function tokenRefused(string $clientId, int $status, string $error): self
    {
        return new self("Mollie refused to issue tokens to the OAuth app `{$clientId}` (HTTP {$status}): {$error}. A code is valid once and for ten minutes, and a refresh token stops working once the merchant revokes the connection.");
    }

    /** Mollie answered the token endpoint without the tokens it promises. */
    public static function unreadableTokens(string $clientId): self
    {
        return new self("Mollie answered the token request of the OAuth app `{$clientId}` without an access token, a refresh token and an expiry, so there is nothing to store.");
    }

    /** Mollie refused to revoke a token. */
    public static function revocationRefused(string $clientId, int $status, string $error): self
    {
        return new self("Mollie refused to revoke a token of the OAuth app `{$clientId}` (HTTP {$status}): {$error}.");
    }

    /** Mollie created no client link. */
    public static function noClientLink(string $partner): self
    {
        return new self("Mollie created no client link for the partner account `{$partner}`, so there is no onboarding address to send the merchant to.");
    }
}
