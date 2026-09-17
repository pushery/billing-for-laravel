<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\ReadsProviderComputedTax;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\ProviderComputedTax;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

/**
 * The tax Stripe computed for a Checkout sale, read off the session's line items.
 *
 * ## Why a second call, when the event already carried a figure
 *
 * `checkout.session.completed` carries `total_details.amount_tax` and no rate at all. A tax document
 * has to name the applicable rate, so the amount alone leaves the document one field short — and the
 * missing field is the one nobody may invent. The rate lives one call away, on the line items, beside
 * the exact base and the exact tax. So the call buys the difference between a document and no
 * document.
 *
 * ## `effective_percentage`, NEVER `percentage` — and the difference is silent
 *
 * Stripe's own field documentation separates them, and this lane is exactly the case where they
 * diverge. `percentage` is the statutory rate and "includes the statutory tax rate of NON-TAXABLE
 * jurisdictions"; `effective_percentage` is, in Stripe's words, "the rate actually used to calculate
 * tax based on the product's taxability and whether the user is registered to collect taxes in the
 * corresponding jurisdiction" — and it is the one defined for `automatic_tax`, which is the only
 * setting that reaches this class.
 *
 * On an exempt, zero-rated or reverse-charge supply the two disagree: the statutory field still names
 * the country's headline rate while nothing was charged. Reading it would put a rate on the document
 * that was never applied, beside amounts that contradict it — the same defect this whole seam exists
 * to avoid, from a source that looks authoritative.
 *
 * ## What it refuses, and refusing is the feature
 *
 * Each of these returns null, and the caller then issues nothing at all:
 *
 * - **Several rates on one sale.** A document states one rate against one base. Two lines taxed
 *   differently are two supplies, and averaging them would describe neither.
 * - **A rate that is not a percentage.** Stripe's `rate_type` may be `flat_amount`, and its own
 *   documentation says `percentage` then varies by transaction and the field is null. There is no
 *   rate to state.
 * - **A rate finer than the document can carry.** The stored rate is an integer count of basis
 *   points, so 19% and 8.5% fit and 8.875% does not. Rounding it would state a rate that does not
 *   reproduce the amounts printed beside it.
 * - **A provider that cannot be reached.** A failed read is loud and leaves the sale exactly as
 *   visible as it was; a fallback computation would be a plausible figure nobody questions.
 *
 * ## A 429 is NOT one of those refusals, and the hierarchy is why
 *
 * Stripe makes `RateLimitException` a subclass of `InvalidRequestException`, which is itself an
 * `ApiErrorException`. So the ordinary "could not read it" catch below would also swallow the one
 * failure that means *ask again in a second*, and file it as a permanent "no tax here". The caller
 * would issue no document, the job would return normally and never be re-driven, and every
 * provider-taxed sale inside a rate-limit window would quietly lose its receipt — this class's own
 * defect, reintroduced one layer further down. It is rethrown, so the job retries.
 */
final readonly class StripeCheckoutSessionTax implements ReadsProviderComputedTax
{
    public function __construct(private StripeClient $stripe) {}

    public function forSale(string $saleReference): ?ProviderComputedTax
    {
        try {
            // Every line, not the first page. A sale with more lines than one page is rare and a
            // document written off a prefix of them would be wrong by exactly the amount nobody
            // looked at.
            $lines = $this->stripe->checkout->sessions->allLineItems($saleReference, ['limit' => 100]);
        } catch (RateLimitException $rateLimited) {
            // Ask again; not an answer about this sale. See the class docblock.
            throw $rateLimited;
        } catch (ApiErrorException) {
            return null;
        }

        $currency = null;
        $total = 0;
        $tax = 0;
        $rates = [];

        foreach ($lines->autoPagingIterator() as $line) {
            $lineCurrency = strtoupper((string) $line->currency);

            // Two currencies in one sale is not a thing Stripe produces, and a reading that saw one
            // has misunderstood what it is holding rather than found an exotic sale.
            if ($currency !== null && $lineCurrency !== $currency) {
                return null;
            }

            $currency = $lineCurrency;
            $total += (int) $line->amount_total;
            $tax += (int) $line->amount_tax;

            foreach ($this->taxesOf($line) as $applied) {
                $bps = $this->ratePointsOf($applied);

                if ($bps === null) {
                    return null;
                }

                $rates[$bps] = true;
            }
        }

        // Exactly one rate, and the sale really was taxed. Zero rates where a tax was charged means
        // the reading and the event disagree about the same sale, which is a reason to say nothing.
        if ($currency === null || count($rates) !== 1 || $tax === 0) {
            return null;
        }

        return new ProviderComputedTax(
            rateBps: array_key_first($rates),
            net: new Money($total - $tax, $currency),
            tax: new Money($tax, $currency),
        );
    }

    /**
     * The taxes applied to one line, as a plain list.
     *
     * @return list<object>
     */
    private function taxesOf(object $line): array
    {
        $taxes = $line->taxes ?? null;

        if (! is_iterable($taxes)) {
            return [];
        }

        $applied = [];

        foreach ($taxes as $entry) {
            if (! is_object($entry)) {
                continue;
            }

            $amount = $entry->amount ?? null;

            // A zero-amount entry is a jurisdiction that taxed nothing. Counting its rate would make an
            // ordinary exempt line look like a second rate and lose the whole sale to the refusal above.
            //
            // An amount that is not a whole number is not skipped for tidiness either: it means the entry
            // is not the shape this class reads, and dropping it can only reduce the rates found — which
            // ends in the refusal, never in a rate that was picked because the others were unreadable.
            if (is_int($amount) && $amount !== 0) {
                $applied[] = $entry;
            }
        }

        return $applied;
    }

    /**
     * One applied tax as a whole number of basis points, or null when it cannot be stated as one.
     */
    private function ratePointsOf(object $applied): ?int
    {
        $rate = $applied->rate ?? null;

        // Unexpanded, the field is the id string rather than the object. Refused rather than fetched:
        // a second round trip per line would turn one call into many, and Stripe returns it inline.
        if (! is_object($rate)) {
            return null;
        }

        $percentage = $rate->effective_percentage ?? null;

        if (! is_int($percentage) && ! is_float($percentage)) {
            return null;
        }

        $points = (float) $percentage * 100.0;

        // Exactly representable, or nothing. A rate the column rounds would no longer reproduce the
        // amounts printed beside it, and the pair would contradict each other on the document.
        return $points === floor($points) ? (int) $points : null;
    }
}
