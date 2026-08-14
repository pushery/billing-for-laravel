<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use RuntimeException;

/**
 * A month's transactions for one creator arrived in more than one currency — refused rather than summed.
 *
 * A collective document carries ONE currency. The totals behind it are accumulated as minor units, and minor
 * units of two currencies add up to a number that means nothing: 100 cents plus 100 cents is 200 of neither.
 * Stamping the result with whichever currency arrived first turns that into a document a creator is paid
 * against.
 *
 * `Money` refuses this everywhere else — `plus()`, `minus()` and `compareTo()` all raise `CurrencyMismatch`,
 * which is why nothing has quietly broken so far. This refusal restores that guarantee where the accumulation
 * bypasses `Money` for speed.
 *
 * The boundary matches its sibling one category up (`CollectiveSettlementSpansTaxCategories`): a collective
 * document is homogeneous or it is not issued. Refusing costs a month's settlement that somebody has to look
 * at; issuing costs money that is wrong in a document nobody re-reads.
 */
final class CollectiveSettlementSpansCurrencies extends RuntimeException
{
    public static function make(string $period, string $first, string $second): self
    {
        return new self(
            "The collective settlement for period [{$period}] spans more than one currency — it holds both "
            ."[{$first}] and [{$second}]. One collective document states a single currency, and its totals are "
            .'summed in minor units, so mixing them would produce an amount in no currency at all. Settle each '
            .'currency in its own document rather than issuing one whose total is meaningless.'
        );
    }
}
