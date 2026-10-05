<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * What the CUSTOMER should read on the charge — the service they bought and the period it covers.
 *
 * The money seams carry an amount and a mandate, which is everything the provider needs and nothing a
 * person needs. A charge described by those alone reads as a generic word or a bare number, and neither
 * answers the question that produces a chargeback: what is this, and for when.
 *
 * ## Where the customer reads it
 *
 * It travels as the payment's `description`, and the providers show that field in different places. Mollie
 * passes it on to the customer's bank statement where the method allows, and a SEPA direct debit carries it
 * as the remittance information. Stripe shows it on the payment, its receipt and the dashboard; a card
 * statement carries Stripe's statement descriptor instead, at most 22 characters made of the account's
 * prefix and a suffix this package does not set.
 *
 * ## Why a value object rather than a string parameter
 *
 * A `?string $description` would have been one line shorter and is the wrong shape twice. Each caller
 * would format the text itself, so the wording drifts between the cycle charge and whatever calls the
 * rails next; and the length limits differ, 1000 characters at Stripe, 255 at Mollie and 140 where Mollie
 * collects a SEPA direct debit, so a pre-rendered string is either truncated for the strictest of them
 * everywhere or left for the provider to cut at its limit, mid-word, on the line the customer reads.
 * Holding the parts lets each driver render to its own limit, and lets the trimming spend the SERVICE name
 * rather than the period (see {@see statement()}).
 *
 * ## No translator, deliberately
 *
 * `PaymentRails` is the lowest layer and resolves nothing — no config, no container, no locale. The
 * service name arrives already resolved by the caller, which is the layer that knows whose subscription
 * this is and therefore which locale applies. Putting `Lang::get()` in here would give the rails a
 * dependency they have kept out on purpose, and would resolve against whatever locale the queue worker
 * happened to be running in rather than the customer's.
 *
 * ## The dates are ISO, and that is a decision
 *
 * `2026-03-01` cannot be misread; `01/03/2026` is March 1st or January 3rd depending on the reader, and a
 * statement line has no room to say which. The rendering is therefore locale-free by construction rather
 * than by omission — there is no format here for a locale to change.
 */
final readonly class ChargeNarrative
{
    /**
     * Separates the service from its period. A plain hyphen: Mollie puts the line on the customer's bank
     * statement where it can, and the SEPA character set has a hyphen but no en dash.
     */
    private const string PERIOD_SEPARATOR = ' - ';

    /**
     * @param  string  $service  the human name of what was bought, already resolved by the caller
     * @param  ?CarbonInterface  $periodStart  first day the charge covers, or null where it covers no span
     * @param  ?CarbonInterface  $periodEnd  last day the charge covers, or null
     *
     * @throws InvalidArgumentException when the service name is blank
     */
    public function __construct(
        public string $service,
        public ?CarbonInterface $periodStart = null,
        public ?CarbonInterface $periodEnd = null,
    ) {
        // REFUSED rather than defaulted, and the reason is that this field exists to be read by a person.
        // A blank name renders as a bare period in brackets — "(2026-03-01 - 2026-03-31)" — which is worse
        // than the `Subscription` it replaced: it looks deliberate, so nobody goes looking for the name.
        // The caller has a fallback available (the tier key, and past that a generic word) and should use
        // it knowingly.
        if (trim($service) === '') {
            throw new InvalidArgumentException(
                'A charge narrative needs a service name; it is the half of the line the customer is '
                .'actually looking for. Pass the tier label, or the tier key where no label is configured.'
            );
        }
    }

    /**
     * The line to send, trimmed to fit `$limit` characters.
     *
     * ## What gets spent when it does not fit
     *
     * The SERVICE name, never the period. The period is short and fixed-width, so trimming it saves almost
     * nothing; and it is the half that distinguishes this charge from the eleven others that look exactly
     * like it. A truncated name is still recognizable — `Acme Professional Pl` — while a truncated period
     * is a date that reads as complete and is wrong.
     *
     * A service name so long that the period alone would not fit is not trimmed cleverly: the whole line is
     * cut. Nothing sensible survives that case and inventing a rule for it would be a branch no real
     * installation enters.
     *
     * `mb_*` throughout: the limits are the providers' CHARACTER limits, and cutting a multi-byte name with
     * `substr` would end the line in half a character. That is not valid UTF-8, so it cannot be encoded as the
     * JSON Mollie's API takes.
     */
    public function statement(int $limit): string
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(
                "A charge narrative cannot be rendered into {$limit} characters. The limit is the provider's "
                .'field length and is always positive; a non-positive one means it was computed, not read.'
            );
        }

        $period = $this->period();

        if ($period === null) {
            return mb_substr($this->service, 0, $limit);
        }

        $suffix = ' ('.$period.')';
        $room = $limit - mb_strlen($suffix);

        // Not even the period fits. Cut the whole line rather than emit a bracket that never closes.
        if ($room < 1) {
            return mb_substr($this->service.$suffix, 0, $limit);
        }

        return mb_substr($this->service, 0, $room).$suffix;
    }

    /**
     * The period as one string, or null when neither end is known.
     *
     * A half-known period is rendered as the end that IS known rather than dropped. "from 2026-03-01" says
     * something true; saying nothing loses it, and inventing the other end would put a date on the
     * customer's statement that no row in this package holds.
     */
    private function period(): ?string
    {
        $start = $this->periodStart?->format('Y-m-d');
        $end = $this->periodEnd?->format('Y-m-d');

        return match (true) {
            $start !== null && $end !== null => $start.self::PERIOD_SEPARATOR.$end,
            $start !== null => 'from '.$start,
            $end !== null => 'until '.$end,
            default => null,
        };
    }
}
