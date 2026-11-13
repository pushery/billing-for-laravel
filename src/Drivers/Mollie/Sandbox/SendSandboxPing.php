<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Pushery\Billing\Support\Concerns\BacksOffBetweenAttempts;
use Pushery\Billing\Webhooks\WebhookReceiver;

/**
 * The ping Mollie sends when a payment's status moved: `id=tr_…` posted as a form, unsigned, to the payment's webhook
 * URL. The sandbox hands it to the package's own webhook receiver in this process rather than over the network, so it
 * reaches the same verifier, mapper and effects a ping from Mollie reaches, and needs no address the install can call
 * itself on.
 *
 * Queued on the connection and queue of the webhook effects, after the transaction that wrote the payment commits,
 * so the driver has the payment's answer before its ping arrives, as with Mollie.
 */
final class SendSandboxPing implements ShouldQueueAfterCommit
{
    use BacksOffBetweenAttempts;
    use Queueable;

    public function __construct(public readonly string $payment, public readonly string $webhookUrl)
    {
        $config = Config::array('billing.webhooks', []);

        $this->onConnection(is_string($config['connection'] ?? null) ? $config['connection'] : null);
        $this->onQueue(is_string($config['queue'] ?? null) ? $config['queue'] : null);
    }

    public function handle(): void
    {
        $receiver = Container::getInstance()->make(WebhookReceiver::class);

        $receiver(Request::create($this->webhookUrl, 'POST', ['id' => $this->payment]));
    }
}
