<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use InvalidArgumentException;

/**
 * The text a Checkout Session shows beside its order button, as Stripe's `custom_text.submit.message`.
 *
 * Stripe shows at most 1200 characters there and refuses a session that sends more. The length is checked before
 * the session is created, so the refusal names the tier or the add-on whose text is too long rather than arriving
 * as a provider error about a field.
 */
final class StripeSubmitMessage
{
    /** The most characters Stripe shows beside the order button. */
    public const int MAX_LENGTH = 1200;

    /**
     * The `custom_text` block of a session for $message, where $subject names what is being bought.
     *
     * @return array{submit: array{message: string}}
     */
    public static function customText(string $message, string $subject): array
    {
        $length = mb_strlen($message);

        if ($length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The text beside the order button of %s has %d characters, and Stripe shows at most %d.',
                $subject,
                $length,
                self::MAX_LENGTH,
            ));
        }

        return ['submit' => ['message' => $message]];
    }
}
