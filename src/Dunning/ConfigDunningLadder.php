<?php

declare(strict_types=1);

namespace Pushery\Billing\Dunning;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\ValueObjects\DunningLevel;
use Pushery\Billing\ValueObjects\Money;

/**
 * The config-driven multi-level dunning ladder (config('billing.dunning')). Each rung has an
 * after_days offset and an optional fee. Given when the delinquency clock started, it reports the
 * highest rung reached now — the timestamp is the source of truth, never a gateway status.
 */
final readonly class ConfigDunningLadder
{
    public function __construct(private Repository $config) {}

    /** @return list<DunningLevel> the ladder, in configured order. */
    public function levels(): array
    {
        $configured = $this->config->get('billing.dunning');

        if (! is_array($configured)) {
            return [];
        }

        $levels = [];
        $position = 1;

        foreach ($configured as $rung) {
            if (! is_array($rung)) {
                continue;
            }

            $afterDays = self::wholeNumber($rung['after_days'] ?? null);

            if ($afterDays === null) {
                continue;
            }

            $rawLabel = $rung['label'] ?? null;
            $label = is_string($rawLabel) ? $rawLabel : "Level {$position}";
            $levels[] = new DunningLevel($position, $afterDays, $this->fee($rung), $label);
            $position++;
        }

        return $levels;
    }

    /** The highest rung reached at $now given delinquency began at $since, or null if none. */
    public function currentLevel(DateTimeInterface $since, DateTimeInterface $now): ?DunningLevel
    {
        $reached = null;

        foreach ($this->levels() as $level) {
            if ($level->isReachedAt($since, $now)) {
                $reached = $level;
            }
        }

        return $reached;
    }

    /** @param array<array-key, mixed> $rung */
    private function fee(array $rung): Money
    {
        $fee = $rung['fee'] ?? null;

        if (! is_array($fee)) {
            return Money::zero($this->currency());
        }

        $rawCurrency = $fee['currency'] ?? null;
        $currency = is_string($rawCurrency) ? $rawCurrency : $this->currency();
        $amount = self::wholeNumber($fee['amount'] ?? null);

        return $amount !== null ? Money::of($amount, $currency) : Money::zero($currency);
    }

    /**
     * A number of days or minor units as configured: an integer, or a string of digits, which is how env()
     * delivers one. Read as anything else, a rung written as "3" would drop out of the ladder and a fee
     * written as "500" would be zero.
     *
     * @internal shared with BillingConfigValidator, which checks the ladder's order the same way.
     */
    public static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 ? (int) trim($value) : null;
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.currency', 'EUR');

        return is_string($currency) ? $currency : 'EUR';
    }
}
