<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View as ViewFacade;
use Livewire\Component;
use Override;
use Pushery\Billing\Contracts\BillingEntityResolver;
use Pushery\Billing\Enums\ToastLevel;
use Pushery\Billing\Events\Concerns\BroadcastsToOwner;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        if (! (Auth::user() instanceof Model)) {
            throw new HttpException(403);
        }
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

        // The two key names differ on purpose, and this line is the translation between them.
        // Inbound, over our own broadcast channel, the key is `level` — that is this package's
        // event contract ({@see AccountToastNotified::broadcastWith()}). Outbound, the reader is
        // WireKit's toast region, and it reads `detail.variant`; `level` is a key it looks at
        // nowhere. Sending `level` therefore did not fail — it fell through to the default
        // variant, so every severity rendered as `info` and a `danger` toast was announced on
        // the polite live region instead of the assertive one. Silently, in both channels.
        $this->dispatch(
            'wirekit-toast',
            message: $message,
            variant: ToastLevel::fromWire($payload['level'] ?? null)->value,
        );
    }

    public function render(): View
    {
        return ViewFacade::make('billing::livewire.account-realtime');
    }

    private function owner(): Model
    {
        // Fail-closed, and not redundant with mount()'s identical check. Its caller is
        // getListeners(), which Livewire runs on every HYDRATION — and hydration does not re-run
        // mount(). So a session that ends between two requests reaches this method with the mount
        // gate long past. Answering then would mean deriving a PRIVATE channel name from a guess,
        // and somebody else may be listening on it.
        $actor = Auth::user();

        if (! ($actor instanceof Model)) {
            throw new HttpException(403);
        }

        return Container::getInstance()->make(BillingEntityResolver::class)->ownerFor($actor);
    }
}
