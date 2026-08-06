<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\CreatorTaxStatus;

/**
 * The one way to read a creator's tax standing.
 *
 * It takes a MOMENT and never has a "current status" method, because a current-status method is the bug
 * this design exists to prevent: every caller that needs the status is producing a document about a supply
 * that happened at a particular time, and asking "what is it now" answers a different question that happens
 * to be right most of the time. A status recorded today must not change how a document from January reads.
 */
interface CreatorTaxStatusResolver
{
    /** The creator's standing at that moment — never null: an unestablished status is a status. */
    public function statusAt(Model $merchant, CarbonImmutable $moment): CreatorTaxStatus;
}
