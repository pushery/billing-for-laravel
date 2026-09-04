<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;
use Pushery\Billing\Contracts\WebhookVerifier;

/**
 * Authenticates a Mollie webhook — by signature where the account signs, and by the shape of the ping
 * where it does not.
 *
 * Mollie runs two generations of webhook. The next generation carries an HMAC-SHA256 in
 * `X-Mollie-Signature`; the legacy one carries nothing at all and is authenticated by the fetch the mapper
 * does, since an attacker cannot invent a status Mollie will confirm.
 *
 * **Which generation an account runs is a dashboard setting**, so the configured secret is the switch
 * rather than a guess in code. Both halves matter:
 *
 * - **A secret is configured** → the signature is checked with the SDK's own validator, which knows the
 *   header format and accepts a list of secrets so a key can be rotated without losing webhooks. An
 *   UNSIGNED ping is then refused: the operator told us their account signs, so an unsigned request is
 *   either a misconfiguration or somebody knocking, and both deserve the same answer.
 * - **No secret is configured** → the legacy path. This fallback is not optional; without it every
 *   install still on legacy webhooks would start refusing every ping on the day it updated.
 *
 * Fetching back stays a real defense either way, but it is the WEAKER one, and that is worth saying
 * plainly because this class used to claim the opposite: it lets anybody who can reach the endpoint drive
 * unbounded processing and API calls against the account by posting real ids. A signature throws that away
 * before anything happens.
 */
final readonly class MollieWebhookVerifier implements WebhookVerifier
{
    public function verify(Request $request): bool
    {
        $id = $request->input('id');

        // A ping naming no resource cannot be followed up whatever its signature says, so it is refused
        // before anything else. Housekeeping rather than security — the signature below is the security.
        if (! is_string($id) || trim($id) === '') {
            return false;
        }

        $secrets = $this->signingSecrets();

        if ($secrets === []) {
            return true;
        }

        return $this->signatureHolds($request, $secrets);
    }

    /**
     * Whether the request carries a signature made with one of the configured secrets.
     *
     * The SDK's validator answers in three ways and each maps to a different decision here: true for a
     * valid signature, false when the request carries NO signature at all, and an exception when
     * signatures are present but none matches. The middle case is a legacy ping — which on an install
     * that configured a secret is not legitimate, so it is refused along with the tampered one.
     *
     * @param  list<string>  $secrets
     */
    private function signatureHolds(Request $request, array $secrets): bool
    {
        $signature = $request->header(SignatureValidator::SIGNATURE_HEADER);

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        // Only the documented exception is caught. Anything ELSE the validator might throw surfaces
        // deliberately: refusing quietly on an unexpected library error would write "somebody sent us a
        // bad signature" into the log for what is actually our own bug — an attack that never happened,
        // hiding a fault that did. The request is refused either way, because the endpoint never reaches
        // its effects.
        try {
            return new SignatureValidator($secrets)->validatePayload($request->getContent(), $signature);
        } catch (InvalidSignatureException) {
            return false;
        }
    }

    /**
     * The configured signing secrets, as a list.
     *
     * A list rather than one string because rotation is a period where BOTH are live. Without that, an
     * operator has to choose between rotating a secret and losing webhooks, and what they choose is not
     * rotating.
     *
     * @return list<string>
     */
    private function signingSecrets(): array
    {
        $configured = Config::get('billing.mollie.webhook_secret');

        if (is_string($configured)) {
            return trim($configured) === '' ? [] : [trim($configured)];
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $secret): string => is_string($secret) ? trim($secret) : '', $configured),
            static fn (string $secret): bool => $secret !== '',
        ));
    }
}
