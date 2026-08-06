<?php

declare(strict_types=1);

namespace Pushery\Billing\ContentOwnership;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ContentAccessReader;
use Pushery\Billing\ValueObjects\AccessDecision;
use Pushery\Billing\ValueObjects\ContentReference;
use Pushery\Billing\ValueObjects\OwnedWork;

/**
 * What the seam resolves to while `billing.content_ownership.enabled` is off: no, to everything.
 *
 * This is what makes the switch load-bearing rather than decorative. The alternative — leaving the contract
 * unbound — would throw a resolution error at whoever asked, which reads as a wiring bug rather than as an
 * answer, and would make the difference between "off" and "broken" invisible at the call site.
 *
 * It answers rather than throws for the same reason the enabled reader does: a delivery path that had to
 * catch an exception to render "you do not own this" would catch the storage failures along with it.
 */
final readonly class DisabledContentAccessReader implements ContentAccessReader
{
    public function accessFor(Model $principal, ContentReference $content, ?CarbonInterface $on = null): AccessDecision
    {
        return AccessDecision::denied();
    }

    /** @return array<string, OwnedWork> */
    public function grantsFor(Model $principal, ?CarbonInterface $on = null): array
    {
        return [];
    }
}
