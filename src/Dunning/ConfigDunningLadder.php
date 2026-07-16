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

            $afterDays = $rung['after_days'] ?? null;

            if (! is_int($afterDays)) {
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
        $amount = $fee['amount'] ?? null;

        return is_int($amount) ? Money::of($amount, $currency) : Money::zero($currency);
    }

    private function currency(): string
    {
        $currency = $this->config->get('billing.currency', 'EUR');

        return is_string($currency) ? $currency : 'EUR';
    }
}
