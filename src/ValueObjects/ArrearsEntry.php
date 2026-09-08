<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Contracts\ArrearsRoster;

/**
 * One relationship that is behind on what it owes — who, to which merchant, and since when.
 *
 * The unit an {@see ArrearsRoster} answers in. It is a RELATIONSHIP rather than a customer, and that is the
 * whole shape: a fan behind with creator A keeps creator B, so a reminder is owed per relationship and a
 * message about "your payment" is one the recipient cannot act on.
 *
 * ## Why the subscription is optional
 *
 * An application that keeps its own view of a subscription has no row in this package's table to hand over
 * — that is the reason the roster seam exists. Where this package IS the storage, the row is carried along
 * so a listener written before the seam existed keeps working untouched.
 *
 * ## The key is opaque, and deliberately so
 *
 * `key` is whatever the roster needs to find this relationship again when the reminder has been sent. This
 * package never interprets it: for the local roster it is the subscription's id, for an application's own
 * roster it is whatever their storage keys on. Typing it as something more specific would be this package
 * deciding how somebody else's table is shaped.
 */
final readonly class ArrearsEntry
{
    public function __construct(
        public Model $owner,
        public MerchantScope $merchant,
        public DateTimeInterface $since,
        public int|string $key,
        public mixed $subscription = null,
    ) {}
}
