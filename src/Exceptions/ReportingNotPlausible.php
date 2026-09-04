<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use Pushery\Billing\ValueObjects\PlausibilityFinding;
use RuntimeException;

/**
 * A period was asked to produce a filing while findings about it were still open.
 *
 * ## Why this is a refusal and not a warning
 *
 * The statutory check happens BEFORE the report, and the difference is what a failure costs. A check folded
 * into the export runs after numbers have been drawn and files written, so a failure leaves half a run
 * behind and an operator holding artifacts they cannot use and must not keep. A refusal here produces
 * nothing at all — the period is exactly as it was, and what the operator has instead is a list they can
 * work through.
 *
 * ## Why every finding is named
 *
 * Stopping at the first one turns a hundred sellers into a hundred runs. Every rule has already run by the
 * time this is raised; naming only one of them would throw that away at the last moment.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class ReportingNotPlausible extends RuntimeException
{
    /**
     * @param  list<PlausibilityFinding>  $findings  every open finding, so one pass is enough to work from
     */
    public function __construct(
        public readonly int $year,
        public readonly string $currency,
        public readonly array $findings,
    ) {
        $lines = array_map(
            static fn (PlausibilityFinding $finding): string => '- ['.$finding->key().'] '.$finding->detail,
            $findings,
        );

        parent::__construct(
            "The {$year} {$currency} reporting period has ".count($findings).' open finding(s) and cannot be '
            ."filed:\n".implode("\n", $lines)."\n"
            .'Resolve each, or acknowledge it with a reason. An acknowledgement covers this period only.'
        );
    }
}
