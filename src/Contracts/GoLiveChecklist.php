<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Pushery\Billing\Preflight\CheckpointRegistry;

/**
 * Where the go-live checklist's points come from.
 *
 * The runner enforces the ORDER of a checklist; it has no opinion on what is in one. Keeping the source
 * behind a contract is what makes that true rather than merely intended — and it leaves room for a consumer
 * whose points do not live in code at all (a policy table, an external compliance system) to supply them
 * without reimplementing the ordering rules.
 *
 * The shipped implementation is {@see CheckpointRegistry}, which collects the
 * package's own structural points, the active jurisdiction profile's, and whatever the consumer added.
 */
interface GoLiveChecklist
{
    /**
     * Every point on the checklist, in any order: the runner sorts them.
     *
     * @return list<GoLiveCheckpoint>
     */
    public function all(): array;
}
