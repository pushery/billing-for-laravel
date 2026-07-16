<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Override;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Enums\ToastLevel;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;

/**
 * The headless realtime bridge: mounted ONCE in the account-hub shell, it listens on the owner's private
 * `.account.toast` channel and relays each broadcast toast to the client as a `wirekit-toast` browser event
 * (which the shell's WireKit toast region renders). It has no visible output of its own.
 *
 * A WebSocket payload is untrusted, so the message must be a non-empty string and the level is clamped to a
 * known variant before it is dispatched. The SHELL decides WHETHER to mount this (only when broadcasting is on
 * and not a native runtime); the component only guarantees it is safe when it is.
 */
final class AccountRealtime extends Component
{
    use BroadcastsToOwner;

    public function mount(): void
    {
        // Self-gated: the bridge only ever runs for a real, signed-in owner.
        abort_unless(Auth::user() instanceof Model, 403);
    }

    /** @return array<string, string> */
    #[Override]
    public function getListeners(): array
    {
        $channel = $this->ownerChannelName($this->owner());

        return ["echo-private:{$channel},.account.toast" => 'pushToast'];
    }

    /** @param array<string, mixed> $payload */
    public function pushToast(array $payload): void
    {
        $message = $payload['message'] ?? null;

        // Untrusted WebSocket payload: ignore anything without a real message rather than toasting an empty box.
        if (! is_string($message) || $message === '') {
            return;
        }

        $this->dispatch(
            'wirekit-toast',
            message: $message,
            level: ToastLevel::fromWire($payload['level'] ?? null)->value,
        );
    }

    public function render(): View
    {
        return view('billing::livewire.account-realtime');
    }

    private function owner(): Model
    {
        $actor = Auth::user();

        abort_unless($actor instanceof Model, 403);

        return app(BillingEntityResolver::class)->ownerFor($actor);
    }
}
