<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Adyen;

use Adyen\AdyenException;
use Adyen\Util\HmacSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Contracts\WebhookVerifier;

/**
 * Authenticates an Adyen notification by the HMAC each item carries, and by the endpoint's basic
 * auth where one is configured.
 *
 * Adyen differs from the two drivers already here, and the difference decides the shape of this
 * class. Stripe signs the request; Mollie signs it or sends a bare id. Adyen signs each
 * NotificationRequestItem SEPARATELY, inside the body, and posts several of them in one request.
 * So there is no single signature to check: a request is authentic only if EVERY item in it is,
 * and one forged item among nine genuine ones has to fail the whole request rather than be
 * dropped quietly. A partially trusted batch is the worse outcome, because the effects run per
 * item and nothing downstream would know which ones were vouched for.
 *
 * The signature is computed over the item with `additionalData` removed, so every field the
 * effects later read -- amount, merchant reference, event code, success flag -- is covered. That
 * is why nothing here looks at a field before the check passes: reading `eventCode` to decide
 * whether to verify would make the decision out of unverified input.
 *
 * The SDK is optional, guarded by an is_a check on an injectable class name, and declared in
 * suggest rather than require --
 * the same reasoning that keeps the Mollie client optional. An install that never selects Adyen
 * would otherwise carry a client it never calls. Without the SDK this verifier refuses
 * everything: a webhook endpoint that cannot check a signature must not be the one that decides
 * a payment succeeded.
 */
final readonly class AdyenWebhookVerifier implements WebhookVerifier
{
    /**
     * @param  class-string|string  $signatureClass  The SDK's HMAC helper.
     *
     * Injectable for the same reason MollieClientFactory takes its client class that way: the
     * absence branch below is the one an installation without the optional package walks, and a
     * hardcoded constant makes that branch unreachable from a suite where the package IS
     * installed. A guard nothing can enter is a guard nobody has checked.
     */
    public function __construct(
        private string $signatureClass = HmacSignature::class,
    ) {}

    public function verify(Request $request): bool
    {
        if (! $this->basicAuthHolds($request)) {
            return false;
        }

        $key = Config::get('billing.adyen.hmac_key');

        if (! is_string($key) || $key === '') {
            return false;
        }

        // `is_a` with the string flag rather than `class_exists`, because it answers BOTH questions
        // in one call: the class is present, AND it is the helper this code then calls a method on.
        // A name that resolves to some other class would pass an existence check and fail at the
        // call site with a fatal, which is a worse failure than the refusal this branch exists for.
        if (! is_a($this->signatureClass, HmacSignature::class, true)) {
            return false;
        }

        $items = $request->input('notificationItems');

        // An empty batch is not a signed batch. Returning true here would let an attacker post
        // `{"notificationItems":[]}` and be authenticated, which costs nothing to refuse.
        if (! is_array($items) || $items === []) {
            return false;
        }

        $signature = new $this->signatureClass;

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['NotificationRequestItem']) || ! is_array($item['NotificationRequestItem'])) {
                return false;
            }

            try {
                if (! $signature->isValidNotificationHMAC($key, $item['NotificationRequestItem'])) {
                    return false;
                }
            } catch (AdyenException) {
                // Thrown when the item carries no hmacSignature at all. An unsigned item is
                // refused exactly like a wrongly signed one: the operator configured a key, so
                // an unsigned request is a misconfiguration or somebody knocking.
                return false;
            }
        }

        return true;
    }

    /**
     * Adyen lets the notification endpoint carry HTTP basic auth on top of the HMAC. It is
     * checked first because it costs one comparison and rejects a whole class of traffic before
     * any body parsing, and it is skipped entirely when the operator configured no credentials --
     * requiring it unasked would refuse every install that relies on the HMAC alone, which is the
     * documented default.
     */
    private function basicAuthHolds(Request $request): bool
    {
        $user = Config::get('billing.adyen.notification_user');
        $password = Config::get('billing.adyen.notification_password');

        if (! is_string($user) || $user === '' || ! is_string($password) || $password === '') {
            return true;
        }

        $givenUser = (string) $request->getUser();
        $givenPassword = (string) $request->getPassword();

        // Both compared in constant time, and both compared even when the first already failed:
        // a short-circuit here leaks which half was wrong through timing.
        $userHolds = hash_equals($user, $givenUser);
        $passwordHolds = hash_equals($password, $givenPassword);

        return $userHolds && $passwordHolds;
    }
}
