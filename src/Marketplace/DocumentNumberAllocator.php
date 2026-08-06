<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Enums\DocumentSeries;
use Pushery\Billing\Exceptions\InvalidBillingConfig;
use Pushery\Billing\Support\InvoiceNumberSequence;

/**
 * Mints a document number from one of the platform's own series — for the documents no provider numbers.
 *
 * A number is `PREFIX-YYYY-#######`: the series' configured prefix, the four-digit year, and a seven-digit
 * running number that restarts at 1 each year within that series. The running part comes from the shared
 * sequence under its row lock, so two documents finalizing at the same instant in the same series never
 * receive the same number and the sequence only ever advances. Gaps are harmless — a caller's surrounding
 * transaction may roll back after a number was drawn — but a duplicate cannot happen, and a written number
 * is frozen by the record it lands on, so it can never be renumbered.
 *
 * The prefix is resolved from config, never hard-coded: the series is a role, its letter is a jurisdiction's
 * choice. A role with no configured prefix is refused here rather than numbered with a blank — a malformed
 * number issued is itself a numbered event that would then need a correction.
 */
final readonly class DocumentNumberAllocator
{
    public function __construct(
        private InvoiceNumberSequence $sequence,
        private Repository $config,
    ) {}

    public function allocate(DocumentSeries $series, int $year): string
    {
        if ($year < 1_000 || $year > 9_999) {
            throw new InvalidArgumentException(
                "A document number carries a four-digit year; got {$year}. The year is the document's own, "
                .'passed by the caller, not a default this allocator invents.'
            );
        }

        // The scope carries the series AND the year, so each series restarts its run every year without ever
        // touching the previous year's counter. It is also exactly the number's own leading segment, so the
        // finished number is the scope plus the running part — no second place for the prefix to disagree.
        $scope = sprintf('%s-%04d', $this->prefixFor($series), $year);

        return sprintf('%s-%07d', $scope, $this->sequence->next($scope));
    }

    private function prefixFor(DocumentSeries $series): string
    {
        $prefix = $this->config->get('billing.marketplace.numbering.series.'.$series->value);

        if (! is_string($prefix) || $prefix === '') {
            throw InvalidBillingConfig::forKey(
                'billing.marketplace.numbering.series.'.$series->value,
                'a non-empty prefix for every document series the platform numbers itself',
            );
        }

        return $prefix;
    }
}
