<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mollie\Api\Exceptions\InvalidSignatureException;
use Mollie\Api\Webhooks\SignatureValidator;
use Pushery\Billing\Contracts\WebhookVerifier;

/**
 * Authenticates a Mollie webhook — by signature where Mollie signs, and by the shape of the ping where it
 * does not.
 *
 * Mollie runs two generations of webhook, and one account receives both at once:
 *
 * - **The classic ping** answers the `webhookUrl` the driver sets on every payment it creates. Mollie posts
 *   the payment's id and nothing else, and never signs it, whatever the account has set up in the
 *   dashboard. It is authenticated by the fetch the mapper does, since an attacker cannot invent a status
 *   Mollie will confirm.
 * - **The next-generation event** is subscribed in the dashboard. Its body names a `type` and an
 *   `entityId`, and it carries an HMAC-SHA256 in `X-Mollie-Signature`.
 *
 * So the configured secret decides what can be checked, not which channel exists:
 *
 * - **A secret is configured** → a request that carries a signature has to carry a valid one, checked
 *   with the SDK's own validator, which knows the header format and accepts a list of secrets so a key
 *   can be rotated without losing webhooks. An unsigned request is accepted in the classic shape only. A
 *   body that announces an event claims to come from the generation that signs every delivery, so
 *   arriving unsigned it is not Mollie's. Refusing the unsigned classic ping as well would refuse every
 *   payment status the classic channel carries.
 * - **No secret is configured** → nothing can be checked, and every ping goes on to the fetch.
 *
 * Either way an unsigned classic ping has to name a payment, `tr_` and letters and digits, because the
 * driver sets its `webhookUrl` on payments and on nothing else. Anything else is refused before it is
 * recorded, so a made-up id writes no row of its own.
 *
 * Fetching back is the WEAKER defense: it lets anybody who can reach the endpoint drive processing and API
 * calls against the account by posting real ids. A signature throws a forged event away before anything
 * happens, which is why a request that carries one is never let through on its shape.
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
        $signature = $request->header(SignatureValidator::SIGNATURE_HEADER);

        if ($secrets !== [] && is_string($signature) && $signature !== '') {
            return $this->signatureHolds($request, $signature, $secrets);
        }

        if ($this->isClassicPing($request)) {
            return $this->namesAPayment($id);
        }

        // An event with no signature that could be checked. Without a secret nothing can be checked, and the
        // fetch authenticates it as it does the classic ping. With one, the event claims to come from the
        // generation that signs every delivery, so arriving unsigned it is not Mollie's.
        return $secrets === [];
    }

    /** Whether the id has the shape of a Mollie payment id, the only resource a classic ping here names. */
    private function namesAPayment(string $id): bool
    {
        return preg_match('/\Atr_[A-Za-z0-9]+\z/', $id) === 1;
    }

    /**
     * Whether an unsigned request has the shape of the classic ping: a resource id and no event.
     *
     * The same two fields tell the mapper which generation it reads, so a request let through here as a
     * classic ping is read as one there.
     */
    private function isClassicPing(Request $request): bool
    {
        return $request->input('type') === null && $request->input('entityId') === null;
    }

    /**
     * Whether the signature the request carries was made with one of the configured secrets.
     *
     * @param  list<string>  $secrets
     */
    private function signatureHolds(Request $request, string $signature, array $secrets): bool
    {
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

        // A COMMA-SEPARATED string is the only rotation an env file can express, and env is the channel
        // this config actually reads (`config/billing.php` calls `env()` for one string). So the array
        // branch below was reachable only by hand-editing the published config, and the rotation this
        // docblock promises was, through the normal channel, not reachable at all.
        //
        // Worse than absent: the obvious attempt broke everything. Mollie's own package documents a
        // comma-separated list, so an operator who knows that writes `old,new` here -- and before this
        // split that became ONE secret literally named "old,new", matching no signature Mollie ever
        // produces. Every webhook was then refused, starting at the moment of rotation, under time
        // pressure. Splitting hands both halves to the same normalization the array branch already had.
        if (is_string($configured)) {
            $configured = explode(',', $configured);
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
