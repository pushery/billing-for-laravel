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
 */
final readonly class RetentionFloorGuard
{
    /** The eight-year German statutory floor for keeping invoices, in days (§14b Abs. 1 UStG n. F.). */
    public const int FINANCIAL_FLOOR_DAYS = 2920;

    public function __construct(private Repository $config) {}

    public function verify(): void
    {
        if ((bool) $this->config->get('billing.retention.allow_below_statutory_minimum', false)) {
            return;
        }

        $configured = $this->config->get('billing.retention.erased_financial_days');

        // Only a real, usable value can be too short. A null/unusable value means "use the package default",
        // which is the floor itself — nothing to refuse.
        if (! is_int($configured) || $configured <= 0) {
            return;
        }

        if ($configured < self::FINANCIAL_FLOOR_DAYS) {
            throw RetentionBelowStatutoryMinimum::forFinancialRecords($configured, self::FINANCIAL_FLOOR_DAYS);
        }
    }
}
