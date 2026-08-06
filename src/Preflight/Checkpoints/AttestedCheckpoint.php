<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * A point the package cannot check, recorded instead as a dated, versioned statement by the operator.
 *
 * Some go-live conditions are real-world acts — terms published on a website, a registration filed with an
 * authority. No amount of local state proves them, and a checkpoint that reached out to try would be both
 * unreliable and forbidden from the boot path. So the operator states them, in configuration:
 *
 *     'attestations' => [
 *         'registrations.oss' => ['version' => '2026-01', 'attested_at' => '2026-02-14', 'reference' => '…'],
 *     ],
 *
 * Two properties make this more than a checkbox. The DATE is mandatory: an undated confirmation is one
 * nobody can audit and nobody remembers making. And the VERSION is compared against the version the point
 * currently requires — when a later release changes what the terms must contain, the required version moves,
 * every recorded attestation stops matching, and the point goes red until somebody re-reads and re-attests.
 * An attestation without that expiry is a tick nobody ever looks at again.
 */
final readonly class AttestedCheckpoint implements GoLiveCheckpoint
{
    public function __construct(
        private Repository $config,
        private string $key,
        private GoLiveStep $step,
        private bool $blocking,
        private string $requiredVersion,
        private string $subject,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function step(): GoLiveStep
    {
        return $this->step;
    }

    public function isBlocking(): bool
    {
        return $this->blocking;
    }

    /**
     * Always waivable. An attestation states an obligation of the operator's own jurisdiction and contract,
     * and the package is in no position to insist that a consumer somewhere else owes it.
     */
    public function isWaivable(): bool
    {
        return true;
    }

    public function evaluate(): CheckpointOutcome
    {
        // The whole map is read and indexed by hand, never `get('…attestations.'.$key)`. A checkpoint key is
        // dotted, and a dotted key inside a config path is read as a PATH: the lookup would descend into a
        // 'terms' array that does not exist and answer "nothing recorded" for every attestation there is.
        $attestations = $this->config->get('billing.marketplace.preflight.attestations', []);
        $recorded = is_array($attestations) ? ($attestations[$this->key] ?? null) : null;

        if (! is_array($recorded)) {
            return CheckpointOutcome::fail(
                "{$this->subject} — no attestation is recorded. Once it is done, record it under ".
                "billing.marketplace.preflight.attestations.{$this->key} with version '{$this->requiredVersion}' ".
                'and the date it was completed.'
            );
        }

        $version = $recorded['version'] ?? null;
        $date = $recorded['attested_at'] ?? null;

        if (! is_string($date) || $this->parseDate($date) === null) {
            return CheckpointOutcome::fail(
                "{$this->subject} — the attestation has no usable `attested_at` date (expected YYYY-MM-DD). ".
                'An undated attestation records that somebody agreed, not when, so it cannot be audited.'
            );
        }

        if (! is_string($version) || $version !== $this->requiredVersion) {
            $was = is_string($version) && $version !== '' ? "version '{$version}'" : 'no version';

            return CheckpointOutcome::fail(
                "{$this->subject} — the attestation records {$was}, but the current requirement is ".
                "version '{$this->requiredVersion}'. What has to be attested has changed since: re-read it, ".
                'then record the new version and today as the date.'
            );
        }

        $reference = $recorded['reference'] ?? null;
        $suffix = is_string($reference) && $reference !== '' ? " (reference: {$reference})" : '';

        return CheckpointOutcome::pass(
            "{$this->subject} — attested on {$date} for version '{$this->requiredVersion}'{$suffix}."
        );
    }

    /** A strict YYYY-MM-DD read: a value that only half-parses is not a date somebody chose. */
    private function parseDate(string $value): ?string
    {
        $parsed = date_create_immutable_from_format('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
