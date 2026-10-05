<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks;

/**
 * A webhook effect that can run again over a delivery it already handled and change nothing an earlier run did.
 *
 * The effect ledger runs each effect once per delivery, and `billing:webhooks:replay` skips a run that is done. An
 * effect that implements this finds what an earlier run produced and stops there, so the replay runs it again when it
 * is named with `--rerun`: after a fix to what the effect reads, a run that did nothing can then do its work. Every
 * other effect is refused, because a second run of it could credit, charge or mail twice.
 */
interface RepeatableEffect {}
