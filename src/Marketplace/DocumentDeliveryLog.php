<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Enums\DocumentDeliveryEvent;
use Pushery\Billing\Models\DocumentDelivery;

/**
 * The record of a settlement document reaching the person it settles with.
 *
 * A document in a database is not a delivered document. What delivers it is that the recipient can reach it
 * AND has been told it is there — and the only thing that can ever evidence either is a record written when
 * it happened. Without one there is no answer to "when did they get it", which is the question every dispute
 * about a deduction date or an objection window turns on.
 *
 * ## Fetching it is proof on top, never the proof itself
 *
 * A recipient who never opens what they were handed has still been handed it. Nothing here treats an
 * unfetched document as undelivered, and that restraint is deliberate rather than an omission: a system that
 * waited for a fetch would re-issue documents that were already valid, and would make an already-running
 * objection window look as though it had never started.
 *
 * ## A failed notification is an event, not a gap
 *
 * An absent notification cannot be told apart from one that was never attempted, and those need opposite
 * responses — one a retry, the other someone finding out why nothing tried. So a failure is written down,
 * and the document is simply not yet delivered.
 *
 * The log is channel-neutral. A portal with a notification is one way to put a document within reach; an
 * email is another. Both record the same three things, and nothing here names either.
 */
final readonly class DocumentDeliveryLog
{
    /** The document is where its recipient can reach it. */
    public function provided(string $documentNumber, ?Model $merchant = null, ?string $channel = null, ?Carbon $at = null): DocumentDelivery
    {
        return $this->record($documentNumber, DocumentDeliveryEvent::Provided, $merchant, $channel, at: $at);
    }

    /** The recipient was told it is there. */
    public function notified(string $documentNumber, ?Model $merchant = null, ?string $channel = null, ?string $recipient = null, ?Carbon $at = null): DocumentDelivery
    {
        return $this->record($documentNumber, DocumentDeliveryEvent::Notified, $merchant, $channel, $recipient, at: $at);
    }

    /** Telling them did not work. The document is not delivered, and the reason is on the record. */
    public function notificationFailed(string $documentNumber, ?Model $merchant = null, ?string $channel = null, ?string $recipient = null, ?string $reason = null, ?Carbon $at = null): DocumentDelivery
    {
        return $this->record($documentNumber, DocumentDeliveryEvent::NotificationFailed, $merchant, $channel, $recipient, $reason, $at);
    }

    /** They fetched it. Every fetch is kept, because a count nobody can audit is not evidence. */
    public function retrieved(string $documentNumber, ?Model $merchant = null, ?Carbon $at = null): DocumentDelivery
    {
        return $this->record($documentNumber, DocumentDeliveryEvent::Retrieved, $merchant, at: $at);
    }

    /**
     * Whether this document has been delivered: within reach AND its recipient told.
     *
     * Both, because either alone is not delivery — a document nobody was told about is one nobody knows to
     * look for, and a notification about a document that is not there yet points at nothing.
     */
    public function delivered(string $documentNumber): bool
    {
        $events = $this->eventsFor($documentNumber);

        return in_array(DocumentDeliveryEvent::Provided, $events, true)
            && in_array(DocumentDeliveryEvent::Notified, $events, true);
    }

    /** When the document became delivered — the later of the two events that together deliver it. */
    public function deliveredAt(string $documentNumber): ?Carbon
    {
        if (! $this->delivered($documentNumber)) {
            return null;
        }

        return DocumentDelivery::query()
            ->where('document_number', $documentNumber)
            ->whereIn('event', [DocumentDeliveryEvent::Provided->value, DocumentDeliveryEvent::Notified->value])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first()?->occurred_at;
    }

    /** How many times it was fetched. Zero says nothing about whether it was delivered. */
    public function retrievalCount(string $documentNumber): int
    {
        return DocumentDelivery::query()
            ->where('document_number', $documentNumber)
            ->where('event', DocumentDeliveryEvent::Retrieved->value)
            ->count();
    }

    /**
     * Every event for one document, oldest first — the log as it would be produced.
     *
     * @return list<DocumentDelivery>
     */
    public function trailFor(string $documentNumber): array
    {
        return array_values(
            DocumentDelivery::query()
                ->where('document_number', $documentNumber)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    /** @return list<DocumentDeliveryEvent> */
    private function eventsFor(string $documentNumber): array
    {
        return array_map(
            fn (DocumentDelivery $delivery): DocumentDeliveryEvent => $delivery->event,
            $this->trailFor($documentNumber),
        );
    }

    private function record(
        string $documentNumber,
        DocumentDeliveryEvent $event,
        ?Model $merchant = null,
        ?string $channel = null,
        ?string $recipient = null,
        ?string $detail = null,
        ?Carbon $at = null,
    ): DocumentDelivery {
        return DocumentDelivery::query()->create([
            'document_number' => $documentNumber,
            'merchant_type' => $merchant?->getMorphClass(),
            'merchant_id' => $merchant?->getKey(),
            'event' => $event,
            'channel' => $channel,
            'recipient' => $recipient,
            'detail' => $detail,
            'occurred_at' => $at ?? Carbon::now(),
        ]);
    }
}
