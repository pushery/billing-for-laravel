<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Exceptions\RetentionBelowStatutoryMinimum;

/**
 * A boot-time guard that stops a developer shortening the financial-record retention below what the law
 * requires — the "protection" half of the erasure story, machine-enforced rather than merely documented.
 *
 * EU law leads: the right to erasure yields to a legal retention obligation (GDPR Art. 17(3)(b)), and a
 * German invoice must be kept eight years (§14b Abs. 1 UStG n. F.). `billing:prune` deletes an erased
 * owner's retained invoices once billing.retention.erased_financial_days passes — so a window set too short
 * prunes tax records too early, silently. This refuses to boot instead.
 *
 * It guards a FLOOR, never a ceiling: a longer window is always fine and never checked. Only a window below
 * the statutory minimum is refused, and only until an operator deliberately opts in
 * (billing.retention.allow_below_statutory_minimum) for a jurisdiction whose minimum really is shorter.
 *
 * This is the INVOICE window (§14b UStG). The longer book/batch window (§147 AO, §257 HGB) is separate —
 * billing.retention.audit_days — and keeping the two apart is deliberate: an invoice must not be over-
 * retained to the book window, because that keeps an erased owner's personal data two years past its
 * obligation, in breach of storage limitation (Art. 5(1)(e)).
 *
 * ONE floor covers every axis, and that is a finding rather than an omission. The obligation attaches to the
 * DOCUMENT, not to whose name is on it: a merchant's payout statement or commission invoice is the
 * platform's own accounting record under the same statutes as a buyer's invoice. So a merchant's retained
 * records age out on this window too, and `billing:prune` walks every axis to apply it. A second,
 * merchant-specific floor would be a second number to keep in step with the law — and the one that fell
 * behind would prune tax records early, silently.
 */
final readonly class RetentionFloorGuard
{
    /** The eight-year German statutory floor for keeping invoices, in days (§14b Abs. 1 UStG n. F.). */
    public const int FINANCIAL_FLOOR_DAYS = 2920;

    /** The ten-year floor for books and posting batches (§147 AO, §257 HGB) — a LONGER, separate window. */
    public const int BOOKS_FLOOR_DAYS = 3650;

    /**
     * Every window that has a floor, and the floor it has. Kept as a map rather than as branches so a new
     * window is guarded by adding a line, not by remembering to add an if.
     *
     * @var array<string, int>
     */
    private const array FLOORS = [
        'erased_financial_days' => self::FINANCIAL_FLOOR_DAYS,
        'audit_days' => self::BOOKS_FLOOR_DAYS,
    ];

    public function __construct(private Repository $config) {}

    public function verify(): void
    {
        if ((bool) $this->config->get('billing.retention.allow_below_statutory_minimum', false)) {
            return;
        }

        // Every window with a floor, not only the documents one. A guard that checked a single number would
        // leave the others shortenable to nothing — and the book window is the LONGER obligation, so it is
        // the easier of the two to shorten by mistake while "harmonizing" them.
        foreach (self::FLOORS as $key => $floor) {
            $configured = $this->config->get('billing.retention.'.$key);
            // Only a real, usable value can be too short. A null/unusable value means "use the package
            // default", which is at least the floor — nothing to refuse.
            if (! is_int($configured)) {
                continue;
            }
            if ($configured <= 0) {
                continue;
            }

            if ($configured < $floor) {
                throw RetentionBelowStatutoryMinimum::forFinancialRecords($configured, $floor);
            }
        }
    }
}
