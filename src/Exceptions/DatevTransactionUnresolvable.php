<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\Enums\DatevTransaction;
use RuntimeException;

/**
 * A DATEV business transaction has no account configured, so the export cannot book it.
 *
 * It aborts the export rather than falling back to a default account, because a booking on the wrong
 * account is a silent error: the file imports cleanly and the mistake surfaces only when a tax advisor or an
 * auditor reads the postings. Fail-closed is the only safe direction for an accounting export.
 */
final class DatevTransactionUnresolvable extends RuntimeException
{
    public static function forTransaction(DatevTransaction $transaction, ?string $country = null): self
    {
        $where = $country === null ? '' : " for country '{$country}'";

        return new self(
            "No DATEV account is configured for the '{$transaction->value}' transaction{$where}. The export ".
            'is refused rather than booking it to a default account, which would be a silent accounting '.
            'error. Configure the account under billing.datev.accounts for the active chart of accounts.'
        );
    }
}
