<?php

declare(strict_types=1);

namespace Pushery\Billing\Preflight\Checkpoints;

use Pushery\Billing\Contracts\GoLiveCheckpoint;
use Pushery\Billing\Contracts\JurisdictionProfile;
use Pushery\Billing\Enums\GoLiveStep;
use Pushery\Billing\ValueObjects\CheckpointOutcome;

/**
 * Whether any jurisdiction's obligations are being checked at all.
 *
 * Without a profile the first two stages of the checklist hold no points, and a report whose terms and
 * registration stages are simply empty is the most flattering thing this command could print: everything
 * that is missing is also invisible. This line makes the absence say its own name, once, in words.
 *
 * It warns rather than blocks. The package genuinely does not know which jurisdictions need a profile, and
 * a consumer who has worked their obligations out elsewhere is not wrong — they are just not being helped
 * here, and should be told so rather than stopped.
 */
final readonly class JurisdictionProfileCheckpoint implements GoLiveCheckpoint
{
    public function __construct(private ?JurisdictionProfile $profile) {}

    public function key(): string
    {
        return 'terms.jurisdiction_profile';
    }

    public function step(): GoLiveStep
    {
        return GoLiveStep::Terms;
    }

    public function isBlocking(): bool
    {
        return false;
    }

    public function isWaivable(): bool
    {
        return false;
    }

    public function evaluate(): CheckpointOutcome
    {
        if (! $this->profile instanceof JurisdictionProfile) {
            return CheckpointOutcome::warn(
                'No jurisdiction profile is active (billing.tax_profile is null), so this checklist contains '.
                'no terms or registration points beyond the ones you added yourself. Your own jurisdiction\'s '.
                'obligations are not being checked here.'
            );
        }

        $count = count($this->profile->checkpoints());

        return CheckpointOutcome::pass(
            "The [{$this->profile->key()}] jurisdiction profile is active and contributes {$count} point(s)."
        );
    }
}
