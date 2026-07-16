<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Pushery\Billing\Enums\AuditSource;
use Pushery\Billing\Models\BillingEvent;

/**
 * Appends to the billing audit ledger. A thin seam so domain effects record what happened without
 * knowing the storage shape.
 *
 * Two levels, `billing.audit.level`:
 *   - `money` (default) writes the FLOOR — every money movement and entitlement/state change, the events an
 *     auditor or a "why is this customer on free?" question needs. Always on, never noisy.
 *   - `all` also writes the high-volume, navigational and read-side events (a customer opening checkout, a
 *     card added) — opt-in, because on a busy app they are a firehose.
 *
 * The default keeps rather than drops: an event type nobody classified writes at `money`. Only the types
 * listed below as high-volume are held back at `money` level.
 */
final readonly class BillingEventLog
{
    /**
     * Event types that write only at `all` level — navigation, credential and high-volume read-side
     * events. Everything NOT listed here is a money/entitlement/state change and always writes.
     *
     * @var list<string>
     */
    private const array ALL_LEVEL_TYPES = [
        'checkout.started',
        'payment_method.added',
        'payment_method.removed',
        'payment_method.default_changed',
        'provider.portal_opened',
        'usage.recorded',
        'webhook.received',
        'invoice.persisted',
    ];

    public function __construct(private Repository $config) {}

    /**
     * Append one audited event. The source is the category of actor (customer / admin / webhook / system)
     * and $actor is the specific user or agent, when there is one — a webhook effect has a source but no
     * actor. Both default so every existing caller keeps working.
     *
     * @param  array<string,mixed>  $payload
     */
    public function record(
        string $type,
        ?Model $subject = null,
        array $payload = [],
        AuditSource $source = AuditSource::System,
        ?Model $actor = null,
    ): ?BillingEvent {
        if (! $this->shouldWrite($type)) {
            return null;
        }

        return BillingEvent::query()->create([
            'type' => $type,
            'source' => $source,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'payload' => $payload,
        ]);
    }

    /** Whether the configured audit level records this type. The money floor is always written. */
    private function shouldWrite(string $type): bool
    {
        if ($this->level() === 'all') {
            return true;
        }

        return ! in_array($type, self::ALL_LEVEL_TYPES, true);
    }

    private function level(): string
    {
        $level = $this->config->get('billing.audit.level', 'money');

        return $level === 'all' ? 'all' : 'money';
    }
}
