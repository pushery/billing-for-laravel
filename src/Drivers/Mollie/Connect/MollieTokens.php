<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Connect;

use Carbon\CarbonImmutable;
use LogicException;
use SensitiveParameter;

/**
 * The tokens Mollie issues for a connected merchant account.
 *
 * Mollie's access token expires after an hour; the refresh token does not expire on its own and buys a new pair.
 * The application stores both, encrypted, wherever it keeps the merchant's account, and refreshes before
 * {@see self::expiresWithin()} says the access token runs out. Neither token shows in a debugger, and the object
 * refuses to be serialized.
 */
final readonly class MollieTokens
{
    /**
     * @param  list<string>  $scopes  the permissions the merchant granted
     */
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}

    /** Whether the access token runs out within the given number of seconds, measured from now or the given moment. */
    public function expiresWithin(int $seconds, ?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->addSeconds($seconds)->greaterThanOrEqualTo($this->expiresAt);
    }

    /**
     * What a debugger shows: the expiry and the scopes, never a token.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: string, scopes: list<string>}
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken' => '(hidden)',
            'refreshToken' => '(hidden)',
            'expiresAt' => $this->expiresAt->toIso8601String(),
            'scopes' => $this->scopes,
        ];
    }

    /**
     * Refused: serialized tokens would reach a queue, a cache or a session in the clear.
     *
     * @return array<string, mixed>
     *
     * @throws LogicException always
     */
    public function __serialize(): array
    {
        throw new LogicException('MollieTokens are never serialized. Store the two tokens encrypted, each on its own.');
    }

    /**
     * Refused as well.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('MollieTokens are never unserialized.');
    }
}
