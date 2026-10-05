<?php

declare(strict_types=1);

namespace Pushery\Billing\Seats;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use LogicException;
use Pushery\Billing\Contracts\ProvidesSeats;

/**
 * A reference implementation of {@see ProvidesSeats} for a team-owner model.
 *
 * The package does NOT own the membership table — that is the app's auth domain — so this trait reads seats
 * from a configurable relation (`billing.seats.membership_relation`, default `members`) rather than a table
 * it ships. A project with no teams adopts nothing and runs no migration.
 *
 * Only ACTIVE members occupy a seat: a pending invite is not a person you pay for. When the relation is not
 * already scoped to active members, set `billing.seats.active_status_column` and the trait filters on it.
 * The count is a COUNT query, never a hydrate-then-count, so a thousand-member team costs one small query.
 *
 * A model adopts it as: `class Team extends Model implements ProvidesSeats { use HasSeats; }`.
 */
trait HasSeats
{
    /** Seats to bill for: the occupied count, never below one — a team always holds at least its owner. */
    public function seatCount(): int
    {
        return max(1, $this->occupiedSeatCount());
    }

    /** Seats currently filled by an active member. */
    public function occupiedSeatCount(): int
    {
        return $this->activeSeatMembers()->count();
    }

    /**
     * The query for this owner's ACTIVE members. Override this to express a membership model the config
     * cannot (a pivot status, a soft-deleted scope); the default reads the configured relation and applies
     * the optional active-status filter.
     */
    protected function activeSeatMembers(): Builder
    {
        $configured = Config::get('billing.seats.membership_relation', 'members');
        $relation = is_string($configured) ? $configured : 'members';
        $query = $this->{$relation}();

        // Named rather than left to the count: a configured name that is not a relation would fail inside it
        // with a message about something else.
        if (! $query instanceof Builder) {
            throw new LogicException(sprintf(
                'billing.seats.membership_relation names [%s], which %s does not answer with a relation.',
                $relation,
                static::class,
            ));
        }

        $column = Config::get('billing.seats.active_status_column');

        // A column the host names in config rather than a property of a known model, so the condition goes to
        // the query underneath, which takes a column name as it is.
        if (is_string($column) && $column !== '') {
            $query->getQuery()->where($column, Config::get('billing.seats.active_status_value', 'active'));
        }

        return $query;
    }
}
