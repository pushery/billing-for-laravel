<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Pushery\Billing\Enums\RetentionAction;
use Pushery\Billing\Enums\RetentionClock;
use Pushery\Billing\Enums\RetentionExecutor;
use Pushery\Billing\ValueObjects\ErasureAxis;
use Pushery\Billing\ValueObjects\RetentionRule;

/**
 * Every retention rule the package holds, derived from the erasure map rather than written beside it.
 *
 * Deriving is the point. The map already decides what happens to each table when a person is erased, and a
 * second hand-written list of the same tables would drift from it — silently, because both would look
 * complete. Here a table's classification IS its rule, and a table that gains a classification gains a rule
 * in the same commit.
 *
 * The three periods stay in configuration and keep their current meanings: a rule set that changed a number
 * while it changed the shape would be impossible to review, and the shape is what is changing.
 */
final class RetentionMatrix
{
    /** Which column holds a document's issue date, per table. A table not listed is dated by its creation. */
    private const array ISSUE_COLUMN = [
        'billing_invoices' => 'issued_at',
    ];

    /** @var list<RetentionRule> */
    private array $custom = [];

    /** @var array<string,literal-string> */
    private array $declaredIssueColumns = [];

    public function __construct(private readonly Repository $config) {}

    /**
     * Add rules for objects the package does not own.
     *
     * A consumer stores things this matrix has never heard of, and those things have retention duties too.
     * Without a way to declare them the consumer keeps a second list — which is precisely the drift the
     * derivation above exists to avoid, reintroduced one level up. A rule here overrides a derived one for
     * the same object, so a consumer can also lengthen a window their own obligations require.
     */
    public function extendWith(RetentionRule ...$rules): void
    {
        foreach ($rules as $rule) {
            $this->custom[] = $rule;
        }
    }

    /**
     * Every rule, ordered by object so two runs read identically.
     *
     * @return list<RetentionRule>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (OwnerScopedTables::axes() as $axis) {
            foreach ($this->rulesForAxis($axis) as $rule) {
                $rules[$rule->object] = $rule;
            }
        }

        // The audit ledger is keyed to a subject rather than to an erasure axis, so the loop above cannot
        // see it. It keeps the LONGER of the two book-keeping windows deliberately: the shorter one governs
        // documents, and merging them would prune books years early under a number that looks like a typo.
        $rules['billing_events'] = new RetentionRule(
            object: 'billing_events',
            action: RetentionAction::Delete,
            clock: RetentionClock::CreatedAt,
            days: $this->days('audit_days', 3650),
            basisKey: 'billing::retention.basis.books',
            // Not the plain time pruner: a ledger row goes only when its owner was erased AND the window
            // ran out, and either alone would delete books that are still owed.
            executor: RetentionExecutor::DedicatedPruner,
        );

        // Place evidence outlives the documents it supports, and that is deliberate rather than an oversight.
        // The document window and the evidence window come from different obligations and are different
        // lengths; merging them would prune the evidence years before the return it justifies stops being
        // examinable, leaving a filed figure with nothing behind it. Set as its own rule so the two windows
        // can never be tidied into one.
        $rules['billing_place_evidence'] = new RetentionRule(
            object: 'billing_place_evidence',
            action: RetentionAction::Delete,
            clock: RetentionClock::CreatedAt,
            days: $this->days('place_evidence_days', 3650),
            basisKey: 'billing::retention.basis.books',
            // The erasure axis already carries this table (it is in an axis's `retained` list). The rule
            // here only lengthens its window; it does not add a second deletion path, and a pruner that
            // inferred one from the Delete/CreatedAt signature would touch these rows behind the axis.
            executor: RetentionExecutor::ErasureAxis,
        );

        // A produced tax-return file is a book-keeping document scoped to nobody: it names a period, not a
        // person, so the erasure axes above cannot see it. It keeps the full book-keeping window rather than
        // the shorter erased-subject one — that shorter window exists because a person asked to be forgotten,
        // and there is no person here to ask.
        $rules['billing_tax_return_exports'] = new RetentionRule(
            object: 'billing_tax_return_exports',
            action: RetentionAction::Delete,
            clock: RetentionClock::CreatedAt,
            days: $this->days('audit_days', 3650),
            basisKey: 'billing::retention.basis.books',
            executor: RetentionExecutor::TimePruner,
        );

        // A produced seller-reporting record is the same kind of document and keeps the same window, for
        // the same reason: it names a PERIOD rather than a person, so no erasure axis reaches it. The
        // sellers it describes are erased where they actually live; deleting this would leave a filing
        // whose evidence of what was reported has gone while the filing itself stands.
        $rules['billing_reporting_exports'] = new RetentionRule(
            object: 'billing_reporting_exports',
            action: RetentionAction::Delete,
            clock: RetentionClock::CreatedAt,
            days: $this->days('audit_days', 3650),
            basisKey: 'billing::retention.basis.books',
            executor: RetentionExecutor::TimePruner,
        );

        // A consumer's own rules last, so one may override a derived rule for the same object rather than
        // sitting beside it as a second answer to "how long".
        foreach ($this->custom as $rule) {
            $rules[$rule->object] = $rule;
        }

        ksort($rules);

        return array_values($rules);
    }

    public function ruleFor(string $object): ?RetentionRule
    {
        foreach ($this->rules() as $rule) {
            if ($rule->object === $object) {
                return $rule;
            }
        }

        return null;
    }

    /** The issue-date column for a table, or its creation date. */
    public function issueColumnFor(string $table): string
    {
        return $this->issueColumns()[$table] ?? 'created_at';
    }

    /**
     * Say which column dates a table, for one the package does not own.
     *
     * The companion to `extendWith()`, and the half that was missing. A consumer could already declare a
     * retention RULE for their own table; there was no way to say the table is dated by anything other than
     * its creation, so such a table silently ran on the wrong clock — by up to a year, in the direction that
     * deletes too early.
     *
     * The column must be a LITERAL, and that is a security boundary rather than a style preference: the
     * pruner interpolates it into raw SQL (`COALESCE(<column>, created_at) < ?` in SubjectScopedRecords),
     * where a value that came from a request or a database row would be an injection. Requiring a
     * literal-string is what keeps that impossible to reach from outside the source file that declares it.
     *
     * @param  literal-string  $column
     */
    public function datesBy(string $table, string $column): void
    {
        $this->declaredIssueColumns[$table] = $column;
    }

    /**
     * Every issue-date column, shipped and declared.
     *
     * This is the ONE map. It used to be two: the pruner carried a private copy of the same table-to-column
     * list under a different name, in the very command that already had this object injected. Both held the
     * same single entry, so no value was ever wrong — but a public accessor answering consumers from one map
     * while the package pruned by the other is a disagreement waiting for a second dated table.
     *
     * @return array<string,literal-string>
     */
    public function issueColumns(): array
    {
        return [...self::ISSUE_COLUMN, ...$this->declaredIssueColumns];
    }

    /**
     * @return list<RetentionRule>
     */
    private function rulesForAxis(ErasureAxis $axis): array
    {
        $rules = [];

        foreach ($axis->purged as $table) {
            // No clock of its own: there is no reason to keep it once its person is gone, and no reason to
            // remove it while they are here.
            $rules[] = new RetentionRule(
                object: $table,
                action: RetentionAction::Delete,
                clock: RetentionClock::SubjectErasure,
                days: null,
                basisKey: 'billing::retention.basis.no_obligation',
                executor: RetentionExecutor::ErasureAxis,
            );
        }

        foreach ($axis->retained as $table) {
            $rules[] = new RetentionRule(
                object: $table,
                action: RetentionAction::RetainUnlinked,
                clock: RetentionClock::IssueYearEnd,
                days: $this->days('erased_financial_days', RetentionFloorGuard::FINANCIAL_FLOOR_DAYS),
                basisKey: 'billing::retention.basis.documents',
                executor: RetentionExecutor::ErasureAxis,
            );
        }

        foreach ($axis->scrubbed as $table => $columns) {
            $rules[] = new RetentionRule(
                object: $table,
                action: RetentionAction::Scrub,
                clock: RetentionClock::CreatedAt,
                days: $this->days('webhook_payload_days', 90),
                basisKey: 'billing::retention.basis.delivery_replay',
                columns: $columns,
                // Its own step in `billing:prune`: a scrub blanks named columns rather than removing rows,
                // so it cannot share the deletion loop.
                executor: RetentionExecutor::DedicatedPruner,
            );
        }

        return $rules;
    }

    private function days(string $key, int $default): int
    {
        $days = $this->config->get('billing.retention.'.$key, $default);

        return is_int($days) && $days > 0 ? $days : $default;
    }
}
