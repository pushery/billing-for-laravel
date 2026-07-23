<?php

declare(strict_types=1);

namespace Pushery\Billing\Invoicing;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\DatevAccountResolver;
use Pushery\Billing\Enums\DatevTransaction;
use Pushery\Billing\Exceptions\DatevTransactionUnresolvable;
use Pushery\Billing\ValueObjects\DatevAccount;

/**
 * Resolves a business transaction to a DATEV account from the configured chart of accounts.
 *
 * This class carries NO account numbers of its own — they all live in config (a chart of accounts is
 * jurisdiction-specific, and putting a number in code would bury a German-only value in the neutral core).
 * When no chart is selected it falls back to the single-seller revenue account, which is what keeps the
 * shipped export byte-for-byte unchanged. A transaction with no configured account is refused, never booked
 * to a default — a wrong account is a silent error that surfaces only at audit.
 */
final readonly class ConfigDatevAccountResolver implements DatevAccountResolver
{
    public function __construct(private Repository $config) {}

    public function resolve(DatevTransaction $transaction, ?string $country = null): DatevAccount
    {
        $chart = $this->config->get('billing.datev.chart');

        // No chart selected: the single-seller path. Only fan revenue is booked here (the marketplace
        // transactions do not exist without the marketplace), and it books to the one configured revenue
        // account, exactly as the shipped export always has.
        if (! is_string($chart) || $chart === '') {
            return $this->singleSeller($transaction);
        }

        if ($transaction === DatevTransaction::OssRevenue) {
            return $this->ossAccount($chart, $country);
        }

        $entry = $this->config->get("billing.datev.accounts.{$chart}.{$transaction->value}");

        return $this->account($entry) ?? throw DatevTransactionUnresolvable::forTransaction($transaction);
    }

    private function singleSeller(DatevTransaction $transaction): DatevAccount
    {
        if ($transaction !== DatevTransaction::FanRevenueStandard && $transaction !== DatevTransaction::FanRevenueReduced) {
            throw DatevTransactionUnresolvable::forTransaction($transaction);
        }

        $revenue = $this->config->get('billing.datev.revenue_account');

        // The single-seller revenue account is treated as automatic: the export has never set a
        // BU-Schlüssel, and that stays the correct behavior. An UNSET account resolves to an empty number,
        // NOT an error — the shipped export deliberately emits a blank Gegenkonto for an unconfigured install
        // (a valid template to fill in later), and that byte-for-byte behavior must be preserved. Fail-closed
        // is for a SELECTED chart with a missing account, not for the single-seller template.
        return new DatevAccount(is_scalar($revenue) ? (string) $revenue : '', automatic: true);
    }

    private function ossAccount(string $chart, ?string $country): DatevAccount
    {
        if ($country === null) {
            throw DatevTransactionUnresolvable::forTransaction(DatevTransaction::OssRevenue);
        }

        $entry = $this->config->get("billing.datev.accounts.{$chart}.oss_revenue.".strtoupper($country));

        return $this->account($entry)
            ?? throw DatevTransactionUnresolvable::forTransaction(DatevTransaction::OssRevenue, $country);
    }

    private function account(mixed $entry): ?DatevAccount
    {
        if (! is_array($entry)) {
            return null;
        }

        $number = $entry['account'] ?? null;

        if (! is_scalar($number) || (string) $number === '') {
            return null;
        }

        return new DatevAccount((string) $number, automatic: (bool) ($entry['automatic'] ?? true));
    }
}
