<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Connect;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mollie\Api\Http\Data\Owner;
use Mollie\Api\Http\Data\OwnerAddress;
use Mollie\Api\Http\Requests\CreateClientLinkRequest;
use Mollie\Api\Http\Requests\GetPaginatedProfilesRequest;
use Mollie\Api\Resources\ClientLink;
use Mollie\Api\Resources\CursorCollection;
use Mollie\Api\Resources\Profile;
use Pushery\Billing\Drivers\Mollie\MollieClientFactory;
use Pushery\Billing\Drivers\Mollie\MollieValue;
use Pushery\Billing\Exceptions\MollieConnectFailed;
use Pushery\Billing\ValueObjects\PaymentAccount;
use SensitiveParameter;
use stdClass;

/**
 * The building blocks for connecting a merchant's own Mollie account to the platform's OAuth app.
 *
 * The merchant stays the merchant at Mollie: no money is routed through the platform. The application keeps
 * the app's credentials and the merchant's tokens wherever it keeps secrets, and the package holds them for the
 * length of a call.
 *
 * The flow, in the order the application runs it:
 *
 * 1. {@see self::authorizeUrl()} sends the merchant to Mollie with a `state` the application remembers, or
 *    {@see self::clientLink()} sends a merchant without an account to Mollie's onboarding, linked to the
 *    platform's partner account.
 * 2. Mollie sends the merchant back with a code, which {@see self::exchangeCode()} turns into tokens.
 * 3. {@see self::profiles()} lists the merchant's website profiles, and the merchant picks the one payments are
 *    created on.
 * 4. Before the access token runs out, after an hour, {@see self::refresh()} buys a new one.
 * 5. {@see self::revoke()} ends the connection from the platform's side.
 */
final readonly class MollieConnect
{
    /** Where Mollie asks the merchant to grant the app access. */
    public const string AUTHORIZE_URL = 'https://my.mollie.com/oauth2/authorize';

    /** Where Mollie issues and revokes tokens. */
    public const string TOKENS_URL = 'https://api.mollie.com/oauth2/tokens';

    /** How many pages of profiles a listing reads at most. */
    private const int MAX_PROFILE_PAGES = 10;

    public function __construct(
        private MollieClientFactory $clients = new MollieClientFactory,
    ) {}

    /**
     * The address that asks the merchant to connect their Mollie account to the app.
     *
     * @param  list<string>  $scopes  the permissions asked for, such as `payments.write`, `refunds.write`, `profiles.read` and `methods.read`
     * @param  string  $state  a value the application generated for this attempt and checks when Mollie sends the merchant back
     *
     * @throws InvalidArgumentException when no scope is asked for, or the state is shorter than 16 characters
     */
    public function authorizeUrl(MollieOAuthApp $app, array $scopes, string $state, bool $forcePrompt = false, ?string $locale = null): string
    {
        $this->guard($scopes, $state);

        $query = [
            'client_id' => $app->clientId,
            'redirect_uri' => $app->redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scopes),
            'response_type' => 'code',
            'approval_prompt' => $forcePrompt ? 'force' : 'auto',
        ];

        if ($locale !== null) {
            $query['locale'] = $locale;
        }

        return self::AUTHORIZE_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The tokens for the code Mollie sent the merchant back with.
     *
     * @throws MollieConnectFailed when Mollie refuses the code or answers without the tokens
     * @throws ConnectionException when Mollie is out of reach
     */
    public function exchangeCode(MollieOAuthApp $app, #[SensitiveParameter] string $code): MollieTokens
    {
        return $this->tokens($app, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $app->redirectUri,
        ]);
    }

    /**
     * A new access token for a connected merchant, and a refresh token: the new one where Mollie issues one, the
     * given one otherwise.
     *
     * @throws MollieConnectFailed when Mollie refuses the refresh token or answers without the tokens
     * @throws ConnectionException when Mollie is out of reach
     */
    public function refresh(MollieOAuthApp $app, #[SensitiveParameter] string $refreshToken): MollieTokens
    {
        return $this->tokens($app, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $refreshToken);
    }

    /**
     * End a connection by revoking one of its tokens. Revoking the refresh token ends the connection; revoking an
     * access token ends that token only.
     *
     * @throws MollieConnectFailed when Mollie refuses the revocation
     * @throws ConnectionException when Mollie is out of reach
     */
    public function revoke(MollieOAuthApp $app, #[SensitiveParameter] string $token, bool $isRefreshToken = true): void
    {
        $response = Http::asForm()
            ->withBasicAuth($app->clientId, $app->clientSecret)
            ->delete(self::TOKENS_URL, [
                'token_type_hint' => $isRefreshToken ? 'refresh_token' : 'access_token',
                'token' => $token,
            ]);

        if ($response->failed()) {
            throw MollieConnectFailed::revocationRefused($app->clientId, $response->status(), $this->errorOf($response));
        }
    }

    /**
     * The website profiles of a connected account, for the merchant to pick the one payments are created on.
     *
     * @return list<MollieProfile>
     */
    public function profiles(PaymentAccount $account): array
    {
        $client = $this->clients->forAccount($account);
        $page = MollieValue::narrow($client->send(new GetPaginatedProfilesRequest(limit: 250)), CursorCollection::class);
        $profiles = [];

        for ($read = 1; $page instanceof CursorCollection && $read <= self::MAX_PROFILE_PAGES; $read++) {
            foreach ($page as $profile) {
                if ($profile instanceof Profile) {
                    $profiles[] = new MollieProfile(
                        $profile->id,
                        $profile->name,
                        $profile->mode,
                        MollieValue::of($profile->status),
                        $profile->website,
                    );
                }
            }

            $page = $page->hasNext() ? MollieValue::narrow($page->next(), CursorCollection::class) : null;
        }

        return $profiles;
    }

    /**
     * The address that sends a merchant without a Mollie account to Mollie's onboarding, linked to the platform's
     * partner account, and connects the new account to the app once it exists.
     *
     * @param  PaymentAccount  $partner  the platform's partner account, with its organization access token
     * @param  list<string>  $scopes  the permissions the connection asks for
     * @param  string  $state  a value the application generated for this attempt and checks on the way back
     *
     * @throws InvalidArgumentException when no scope is asked for, or the state is shorter than 16 characters
     * @throws MollieConnectFailed when Mollie creates no client link
     */
    public function clientLink(
        PaymentAccount $partner,
        Owner $owner,
        string $name,
        OwnerAddress $address,
        MollieOAuthApp $app,
        array $scopes,
        string $state,
        ?string $registrationNumber = null,
        ?string $vatNumber = null,
    ): string {
        $this->guard($scopes, $state);

        $link = MollieValue::narrow($this->clients->forAccount($partner)->send(new CreateClientLinkRequest($owner, $name, $address, $registrationNumber, $vatNumber)), ClientLink::class);

        // The address is checked before the SDK builds on it: without one it would read a property of null and
        // hand back a query string with nothing in front of it.
        if (! $link instanceof ClientLink || $this->clientLinkOf($link) === null) {
            throw MollieConnectFailed::noClientLink($partner->key);
        }

        // `force`, because the merchant is new to Mollie and has never granted the app anything to skip past.
        return $link->getRedirectUrl($app->clientId, $state, $scopes, 'force');
    }

    /** The onboarding address a client link carries, or null where Mollie sent none. */
    private function clientLinkOf(ClientLink $link): ?string
    {
        // The SDK declares the links an object, and a response that carries none leaves the property null.
        $links = MollieValue::narrow($link->_links, stdClass::class);

        if (! $links instanceof stdClass || ! property_exists($links, 'clientLink') || ! is_object($links->clientLink) || ! property_exists($links->clientLink, 'href')) {
            return null;
        }

        return is_string($links->clientLink->href) && $links->clientLink->href !== '' ? $links->clientLink->href : null;
    }

    /**
     * @param  array<string, string>  $form
     *
     * @throws MollieConnectFailed when Mollie refuses or answers without the tokens
     * @throws ConnectionException when Mollie is out of reach
     */
    private function tokens(MollieOAuthApp $app, array $form, ?string $currentRefreshToken = null): MollieTokens
    {
        $response = Http::asForm()->withBasicAuth($app->clientId, $app->clientSecret)->post(self::TOKENS_URL, $form);

        if ($response->failed()) {
            throw MollieConnectFailed::tokenRefused($app->clientId, $response->status(), $this->errorOf($response));
        }

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token') ?? $currentRefreshToken;
        $expiresIn = $response->json('expires_in');
        $scope = $response->json('scope');

        if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '' || ! is_int($expiresIn)) {
            throw MollieConnectFailed::unreadableTokens($app->clientId);
        }

        return new MollieTokens(
            $access,
            $refresh,
            CarbonImmutable::now()->addSeconds($expiresIn),
            is_string($scope) ? array_values(array_filter(explode(' ', $scope), static fn (string $granted): bool => $granted !== '')) : [],
        );
    }

    /**
     * @param  list<string>  $scopes
     *
     * @throws InvalidArgumentException when no scope is asked for, or the state is shorter than 16 characters
     */
    private function guard(array $scopes, string $state): void
    {
        if ($scopes === []) {
            throw new InvalidArgumentException('A connection to Mollie asks for at least one scope.');
        }

        // The state is what ties the merchant coming back to the attempt the application started. A short one can be
        // guessed, and a guessed state lets somebody else's code finish the connection.
        if (strlen($state) < 16) {
            throw new InvalidArgumentException('The state of a connection to Mollie has at least 16 characters, so it cannot be guessed.');
        }
    }

    /** Mollie's error and its description, as one line. */
    private function errorOf(Response $response): string
    {
        $error = $response->json('error');
        $description = $response->json('error_description');

        return trim((is_string($error) ? $error : 'unknown_error').(is_string($description) ? " ({$description})" : ''));
    }
}
