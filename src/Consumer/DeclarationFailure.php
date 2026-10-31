<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Exceptions\CancellationUnavailable;
use Pushery\Billing\Exceptions\EndInsidePeriodIsFinal;
use Pushery\Billing\Exceptions\SubscriptionWithdrawalUnavailable;
use Pushery\Billing\Exceptions\WithdrawalWindowClosed;

/**
 * Why a cancellation or a withdrawal declaration waits for a person, in words, read from what the package wrote
 * into its `failure` column.
 *
 * The column holds a key the package chose, `term_unknown` or `refund_refused`, or the class of the exception that
 * kept the declaration from being carried out. Neither is something an operator should have to decode. The review
 * mail and the declarations of the admin console take the sentence from here, and a screen of your own can too. A
 * value the package does not know, an exception of a payment provider among them, gets a general sentence rather
 * than its class name.
 *
 * The sentence is a clause without a closing period, written to follow the colon of the review mail's reason and of
 * the console's line.
 */
final class DeclarationFailure
{
    /** The key under `billing::declarations.failure` for each value the package writes into `failure`. */
    private const array KEYS = [
        'term_unknown' => 'term_unknown',
        'refund_refused' => 'refund_refused',
        WithdrawalWindowClosed::class => 'withdrawal_window_closed',
        SubscriptionWithdrawalUnavailable::class => 'withdrawal_unavailable',
        CancellationUnavailable::class => 'cancellation_unavailable',
        EndInsidePeriodIsFinal::class => 'end_inside_period_is_final',
    ];

    /** The sentence for a value of `failure`, in the given locale or the current one. */
    public static function sentence(string $failure, ?string $locale = null): string
    {
        return (string) Lang::get('billing::declarations.failure.'.(self::KEYS[$failure] ?? 'other'), [], $locale);
    }
}
