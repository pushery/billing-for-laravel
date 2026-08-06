<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A jurisdiction profile that will not let an unclear tax standing be traded through.
 *
 * It is a marker a profile opts into, not a method on every profile, so adding it breaks no existing
 * implementation. What it buys is a hard one: where a profile declares this, the two hold switches in
 * configuration stop having any effect at all.
 *
 * That override exists because the switches would otherwise be a back door to the very thing the hold
 * prevents. An operator who turned payouts back on under a profile that requires the hold would be
 * choosing a default tax standing for people whose standing nobody knows — quietly, by config, with no
 * guard between them and the consequence.
 */
interface RequiresTaxStatusHold {}
