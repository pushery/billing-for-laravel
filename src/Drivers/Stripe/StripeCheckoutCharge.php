<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Pushery\Billing\Support\SafeExternalUrl;
use Pushery\Billing\ValueObjects\ChargeNarrative;
use Pushery\Billing\ValueObjects\ChargeOptions;
use Pushery\Billing\ValueObjects\ChargeResult;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentLine;
use Stripe\StripeClient;

/**
 * A charge of a Stripe payment account on Stripe's hosted Checkout page, for a charge that names no payment method:
 * the customer picks one there, as on Mollie's checkout.
 *
 * The session is opened with the account's key, so it is the merchant's own and stands on the merchant's custom
 * domain where the account has one. Its id is the charge's reference and its page the client secret, as Mollie hands
 * out its checkout, and the result asks for the customer's action. Stripe creates the payment intent only when the
 * customer pays, so the intent carries the charge's billing reference and metadata, and the account's webhooks name
 * the order with them.
 */
final readonly class StripeCheckoutCharge
{
    /** The languages Stripe's Checkout page speaks, as Stripe's API reference lists them for `locale`. */
    private const array LOCALES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'en-GB', 'es', 'es-419', 'et', 'fi', 'fil', 'fr', 'fr-CA', 'hr', 'hu', 'id', 'it',
        'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi',
        'zh', 'zh-HK', 'zh-TW',
    ];

    /** What the one line of a session is called where the charge names no narrative. */
    private const string PAYMENT = 'Payment';

    /** The length a narrative is cut to where it names the one line of a session. */
    private const int NAME_LIMIT = 250;

    /**
     * @param  list<string>  $pageHosts  the hosts the account serves Stripe's pages from beside Stripe's own, its
     *                                   custom domain
     */
    public function __construct(
        private StripeClient $stripe,
        private Repository $config,
        private array $pageHosts = [],
    ) {}

    /**
     * Open the session and hand out its page.
     *
     * The return address of the charge's options is where Stripe sends the customer after paying, and without one
     * `billing.stripe.return_url`. The lines are the session's line items where each states its total as its unit
     * price times its quantity; a line with a discount or below zero cannot be one, and then the session has a single
     * line for the amount that names the lines. The language of the options becomes the page's where Stripe speaks
     * it, and the methods of the options are the ones offered, all the account has turned on without them.
     *
     * @throws InvalidArgumentException when there is nowhere to send the customer back to, or the lines of the charge
     *                                  do not add up to its amount
     */
    public function charge(Money $amount, ?string $idempotencyKey = null, ?ChargeNarrative $narrative = null, ?ChargeOptions $options = null): ChargeResult
    {
        $returnUrl = $options->returnUrl ?? $this->config->get('billing.stripe.return_url');

        if (! is_string($returnUrl) || $returnUrl === '') {
            throw new InvalidArgumentException('A payment on Stripe\'s checkout page needs an address to send the customer back to: the return address of the charge, or `billing.stripe.return_url`.');
        }

        $metadata = [
            ...($options instanceof ChargeOptions ? $options->metadata : []),
            ...($idempotencyKey === null ? [] : [ChargeOptions::RESERVED_METADATA_KEY => $idempotencyKey]),
        ];
        $intent = array_filter(
            ['metadata' => $metadata, 'description' => $narrative?->statement(StripePaymentRails::DESCRIPTION_LIMIT)],
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );
        $payload = array_filter([
            'mode' => 'payment',
            'line_items' => $this->lineItems($amount, $options instanceof ChargeOptions ? $options->lines : [], $narrative),
            'success_url' => $returnUrl,
            'cancel_url' => $options?->cancelUrl,
            'locale' => $this->locale($options?->locale),
            'payment_method_types' => $options instanceof ChargeOptions ? $options->methods : [],
            'metadata' => $metadata,
            'payment_intent_data' => $intent,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        // @phpstan-ignore argument.type (the SDK's generated shape cannot express a payload assembled at run time)
        $session = $this->stripe->checkout->sessions->create($payload, $idempotencyKey === null ? [] : ['idempotency_key' => $idempotencyKey]);

        // A page on a host neither Stripe nor the account serves from counts as none, so nobody is sent there, and the
        // payment waits, as Mollie's does when its checkout is refused.
        $page = SafeExternalUrl::onHosts($session->url ?? null, [...StripeHostedPages::HOSTS, ...$this->pageHosts]);

        return new ChargeResult(
            successful: false,
            reference: $session->id,
            amount: $amount,
            requiresAction: $page !== null,
            pending: $page === null,
            clientSecret: $page,
        );
    }

    /**
     * The session's line items: the lines of the charge where each can be one, otherwise one line for the amount.
     *
     * @param  list<PaymentLine>  $lines
     * @return list<array{price_data: array{currency: string, unit_amount: int, product_data: array{name: string, description?: string}}, quantity: int}>
     *
     * @throws InvalidArgumentException when the lines do not add up to the amount
     */
    private function lineItems(Money $amount, array $lines, ?ChargeNarrative $narrative): array
    {
        $name = $narrative instanceof ChargeNarrative ? $narrative->statement(self::NAME_LIMIT) : self::PAYMENT;

        if ($lines === []) {
            return [$this->lineItem($amount, 1, $name)];
        }

        $total = array_reduce($lines, static fn (Money $sum, PaymentLine $line): Money => $sum->plus($line->totalAmount), Money::zero($amount->currency));

        if (! $total->equals($amount)) {
            throw new InvalidArgumentException("The lines of a charge add up to {$total->format()}, and the charge is {$amount->format()}.");
        }

        $itemized = array_all(
            $lines,
            static fn (PaymentLine $line): bool => ! $line->unitPrice->isNegative() && $line->unitPrice->multipliedBy($line->quantity)->equals($line->totalAmount),
        );

        if ($itemized) {
            $items = [];

            foreach ($lines as $line) {
                $items[] = $this->lineItem($line->unitPrice, $line->quantity, $line->description);
            }

            return $items;
        }

        return [$this->lineItem($amount, 1, $name, implode('; ', array_map(
            static fn (PaymentLine $line): string => $line->quantity.' x '.$line->description,
            $lines,
        )))];
    }

    /**
     * One line item at a unit price.
     *
     * @return array{price_data: array{currency: string, unit_amount: int, product_data: array{name: string, description?: string}}, quantity: int}
     */
    private function lineItem(Money $unitPrice, int $quantity, string $name, ?string $description = null): array
    {
        return [
            'price_data' => [
                'currency' => strtolower($unitPrice->currency),
                'unit_amount' => StripeAmount::of($unitPrice),
                'product_data' => $description === null ? ['name' => $name] : ['name' => $name, 'description' => $description],
            ],
            'quantity' => $quantity,
        ];
    }

    /**
     * The language of Stripe's page for the charge's locale: the language and country where Stripe has that variant,
     * the language where it has the language, and Stripe's own choice from the browser otherwise.
     */
    private function locale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $language = substr($locale, 0, 2);
        $regional = $language.'-'.substr($locale, 3, 2);

        return match (true) {
            in_array($regional, self::LOCALES, true) => $regional,
            in_array($language, self::LOCALES, true) => $language,
            default => 'auto',
        };
    }
}
