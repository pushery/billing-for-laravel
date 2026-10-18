<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * Mollie refused or garbled a step of connecting a merchant's account: a code, a refresh, a revocation or a
 * client link.
 *
 * The message names the OAuth app by its client id and quotes Mollie's own error, never a token or a secret.
 * Where Mollie answered with an error, its HTTP status is the exception's code, and {@see self::refused()} and
 * {@see self::unavailable()} tell a refusal that stays from an answer Mollie could not give just now.
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
        return new self("Mollie refused to issue tokens to the OAuth app `{$clientId}` (HTTP {$status}): {$error}. A code is valid once and for ten minutes, and a refresh token stops working once the merchant revokes the connection.", $status);
    }

    /** Mollie answered the token endpoint without the tokens it promises. */
    public static function unreadableTokens(string $clientId): self
    {
        return new self("Mollie answered the token request of the OAuth app `{$clientId}` without an access token, a refresh token and an expiry, so there is nothing to store.");
    }

    /** Mollie refused to revoke a token. */
    public static function revocationRefused(string $clientId, int $status, string $error): self
    {
        return new self("Mollie refused to revoke a token of the OAuth app `{$clientId}` (HTTP {$status}): {$error}.", $status);
    }

    /** The HTTP status Mollie answered with, or null where the step failed on an answer Mollie gave successfully. */
    public function status(): ?int
    {
        return $this->getCode() >= 400 ? $this->getCode() : null;
    }

    /**
     * Whether Mollie refused the step: a 4xx other than 429. Asking again gets the same answer, because a code
     * was used or has expired, or the merchant ended the connection.
     */
    public function refused(): bool
    {
        $status = $this->status();

        return $status !== null && $status < 500 && $status !== 429;
    }

    /** Whether Mollie could not answer just now: a 429 or a 5xx. The same step can succeed later. */
    public function unavailable(): bool
    {
        $status = $this->status();

        return $status === 429 || ($status !== null && $status >= 500);
    }

    /** Mollie created no client link. */
    public static function noClientLink(string $partner): self
    {
        return new self("Mollie created no client link for the partner account `{$partner}`, so there is no onboarding address to send the merchant to.");
    }
}
