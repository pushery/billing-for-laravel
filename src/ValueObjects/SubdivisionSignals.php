<?php

declare(strict_types=1);

namespace Pushery\Billing\ValueObjects;

use Pushery\Billing\Enums\SignalSource;

/**
 * What each source said about the buyer's SUBDIVISION — a US state, a Canadian province.
 *
 * ## Why this is a separate object and not three more fields on CountrySignals
 *
 * A country is required and a subdivision is not, and the two are answered by different questions. Folding
 * them into one object would make every caller who has no subdivision — which is every caller outside the
 * handful of countries where one matters — pass nulls through a constructor that reads as though they had
 * forgotten something.
 *
 * It mirrors {@see CountrySignals} deliberately: same three sources, same order, so a subdivision is always
 * read from the source that named the country rather than from whichever one happened to know a state. That
 * is the whole rule — a state from a source the country did not come from is a state about a different
 * place.
 *
 * ## What is NOT here, and it is the point
 *
 * No postcode, no city, no coordinates, no raw IP. The package discards the raw IP on purpose and stores
 * only as coarsely as the question needs; a subdivision is already one step finer than everything else in
 * the evidence, and it is carried for exactly one reason — a US nexus threshold is measured per state and
 * the history cannot be reconstructed afterwards. Anything finer would be collection with no question
 * behind it.
 *
 * The suffix only: `CA`, not `US-CA`. The country is already in the record, and repeating it invites two
 * places to disagree.
 */
final readonly class SubdivisionSignals
{
    public function __construct(
        /** The subdivision the buyer said they are in. */
        public ?string $declared = null,
        /** The subdivision the payment instrument's billing address names. */
        public ?string $payment = null,
        /** The subdivision the connection appeared to be in, already resolved — never a raw address. */
        public ?string $ip = null,
    ) {}

    /**
     * The subdivision named by one source, normalized, or null.
     *
     * Uppercased and trimmed of a country prefix, so a caller handing `us-ca` and one handing `CA` are the
     * same answer. Anything longer than an ISO 3166-2 suffix is refused rather than truncated: a value this
     * object cannot recognize is not a subdivision, and storing its first three characters would invent one.
     */
    public function normalized(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $clean = strtoupper(trim($value));

        // `US-CA` and `CA` are the same claim. The prefix is dropped rather than kept, because the country
        // is already a column of its own and two copies of one fact eventually disagree.
        if (preg_match('/^[A-Z]{2}-([A-Z0-9]{1,3})$/', $clean, $matched) === 1) {
            return $matched[1];
        }

        return preg_match('/^[A-Z0-9]{1,3}$/', $clean) === 1 ? $clean : null;
    }

    /** The subdivision this source named, or null where it named none this object can read. */
    public function from(SignalSource $source): ?string
    {
        return $this->normalized(match ($source) {
            SignalSource::Declared => $this->declared,
            SignalSource::Payment => $this->payment,
            SignalSource::Ip => $this->ip,
        });
    }
}
