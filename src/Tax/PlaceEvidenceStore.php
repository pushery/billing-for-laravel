<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Models\PlaceEvidence;
use Pushery\Billing\ValueObjects\CountryEvidence;
use RuntimeException;

/**
 * Writes down which country a sale was taxed in, and what said so.
 *
 * ## Written at the sale or not at all
 *
 * The signals exist only in that moment. An address gets edited, a card gets replaced, a connection closes —
 * none of it is recoverable, and the resolved country is what an entire return is built on. There is no
 * later opportunity to establish it, which is why this is not a reporting concern that can wait.
 *
 * ## Unsettled evidence is not stored, and the sale does not happen
 *
 * The tempting alternative is to store what was found and attribute the sale to the seller's own country.
 * That produces a sale taxed in the wrong place with nothing in the record admitting it — the record would
 * even look complete. So an unsettled resolution is refused here, loudly, rather than written down as a
 * decision nobody made.
 *
 * ## Country codes only
 *
 * What each signal ANSWERED, never what it was derived from. That is everything a later reader needs, and
 * the raw inputs have no business surviving the moment they were resolved.
 */
final readonly class PlaceEvidenceStore
{
    public function __construct(private Repository $config) {}

    /**
     * Record the evidence for a sale.
     *
     * @param  string  $reference  the sale this belongs to — one evidence record per sale, never two
     *
     * @throws RuntimeException when the evidence did not settle on a country
     */
    public function record(
        CountryEvidence $evidence,
        string $reference,
        CarbonInterface $resolvedAt,
        ?Model $owner = null,
    ): PlaceEvidence {
        if (! $evidence->isSettled()) {
            throw new RuntimeException(
                'A sale with no settled country cannot be recorded, and must not proceed. Attributing it to '
                .'the seller\'s own country would tax it in the wrong place with nothing in the record '
                .'admitting it — ask the buyer instead, which is what unresolved evidence is asking for.'
            );
        }

        return PlaceEvidence::query()->create([
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $this->ownerKey($owner),
            'reference' => $reference,
            'declared_country' => $evidence->signals->declared,
            'payment_country' => $evidence->signals->payment,
            'ip_country' => $evidence->signals->ip,
            'resolved_country' => (string) $evidence->country,
            // Null wherever the country has no subdivisions in scope, no source supplied one, or the sources
            // that named the country named different states. Written beside the country because it belongs
            // to the same decision and is kept and erased on exactly the same terms.
            'resolved_subdivision' => $evidence->subdivision,
            'policy_version' => $evidence->policyVersion,
            'required_signals' => $this->requiredSignals(),
            'resolved_at' => $resolvedAt,
        ]);
    }

    /** An owner's key as a string, or null where there is no owner — a sale can be recorded without one. */
    private function ownerKey(?Model $owner): ?string
    {
        $key = $owner?->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    /** The country a sale was taxed in, or null where none was recorded. */
    public function countryFor(string $reference): ?string
    {
        $country = PlaceEvidence::query()->where('reference', $reference)->value('resolved_country');

        return is_string($country) ? $country : null;
    }

    /**
     * The subdivision that sale settled on, or null where none was.
     *
     * The sibling of {@see self::countryFor()}, and the one a subdivision-level counter reads. Null is the
     * ordinary answer and an honest one — most countries have no subdivisions in scope, and a sale whose
     * sources disagreed about the state settled on none. A caller writes what this returns onto the
     * document; what it must never do is fall back to the country or to a raw signal, because a guessed
     * subdivision raises a threshold in a place nobody sold into.
     */
    public function subdivisionFor(string $reference): ?string
    {
        $subdivision = PlaceEvidence::query()->where('reference', $reference)->value('resolved_subdivision');

        return is_string($subdivision) && $subdivision !== '' ? $subdivision : null;
    }

    /**
     * How many agreeing signals this operator requires.
     *
     * Two by default. One is enough below a turnover figure and two are required above it; which applies is
     * the operator's situation, not something the package can read. Two is the safe direction — requiring
     * more evidence than necessary costs a checkout question, requiring less costs the evidence itself.
     */
    public function requiredSignals(): int
    {
        // THE SAME KEY THE POLICY GATES ON, and that is the whole point of this method. It used to read
        // `billing.tax_oss.required_signals` while `PaymentCountryLeadsPolicy` decided on
        // `billing.tax_evidence.required_signals` -- two keys for one idea, with no alias between them.
        //
        // Both default to 2, so a default install was accidentally consistent and nothing was ever red. The
        // divergence only appeared once an operator configured, which is exactly when this record starts
        // being worth something: a one-signal sale, correctly settled under a one-signal standard, was
        // stamped "two required" and kept that way forever -- the row is immutable and outlives the
        // documents built on it.
        //
        // Read through the policy rather than re-derived, so the record cannot state a standard the decision
        // was not made under. There is no expression here that could round it either: the old
        // `=== 1 ? 1 : 2` had no branch for 3, a valid standard, and wrote 2 for it.
        return new RequiredCountrySignals($this->config)->count();
    }
}
