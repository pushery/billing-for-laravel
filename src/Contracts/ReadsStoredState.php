<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A go-live checkpoint that reads the DATABASE, and therefore does not run at boot.
 *
 * {@see GoLiveCheckpoint} says a checkpoint must be cheap and pure and "must not open a socket, query a
 * provider or touch the database", and it gives the reason: the same checklist runs as an operator command
 * AND at boot on every request that starts the framework with the marketplace on.
 *
 * One shipped checkpoint has to break that rule to do its job -- a duplicate buyer receipt is a fact about
 * stored rows and cannot be read from configuration. Marking it here is how the rule survives the
 * exception: the runner excludes these outside the console, so the boot path keeps the promise the contract
 * makes, and the command keeps the whole checklist.
 *
 * The failure this prevents is worse than the cost it saves. A checkpoint that declares itself NON-blocking
 * could still take the entire application down, because an exception out of `evaluate()` does not care what
 * `isBlocking()` returned -- and on an install whose schema is not yet migrated the query throws. The first
 * effect of adopting this package was an application that would not boot, `route:list` included, with the
 * two adoption steps silently order-dependent.
 *
 * What it costs, stated rather than discovered: a marketplace carrying a duplicate receipt now boots. It
 * always did -- the checkpoint is non-blocking and never refused anything -- so what changes is only WHEN
 * the report is produced. A deploy runs console commands, so it is still produced there.
 */
interface ReadsStoredState {}
