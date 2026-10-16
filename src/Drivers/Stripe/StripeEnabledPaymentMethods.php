<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Stripe;

use Pushery\Billing\Contracts\EnabledPaymentMethods;
use Pushery\Billing\ValueObjects\EnabledPaymentMethod;
use Pushery\Billing\ValueObjects\Money;
use Stripe\StripeClient;

/**
 * The payment methods a Stripe account has turned on, from its default payment method configuration.
 *
 * A method counts where Stripe reports it available to the account and the configuration shows it. The
 * configuration answers for the account as a whole: it states no amounts and no countries and carries no names or
 * logos. So the three filters do not narrow this list, the names are Stripe's own product names in English, and
 * Stripe applies the limits of a method when it creates the payment. A restricted key needs read access to payment
 * method configurations.
 */
final readonly class StripeEnabledPaymentMethods implements EnabledPaymentMethods
{
    /** The names Stripe gives its payment methods, by the id the configuration uses. */
    private const array NAMES = [
        'acss_debit' => 'Canadian pre-authorized debits',
        'affirm' => 'Affirm',
        'afterpay_clearpay' => 'Afterpay / Clearpay',
        'alipay' => 'Alipay',
        'amazon_pay' => 'Amazon Pay',
        'apple_pay' => 'Apple Pay',
        'au_becs_debit' => 'BECS Direct Debit',
        'bacs_debit' => 'Bacs Direct Debit',
        'bancontact' => 'Bancontact',
        'billie' => 'Billie',
        'blik' => 'BLIK',
        'card' => 'Card',
        'cartes_bancaires' => 'Cartes Bancaires',
        'cashapp' => 'Cash App Pay',
        'customer_balance' => 'Bank transfer',
        'eps' => 'EPS',
        'giropay' => 'giropay',
        'google_pay' => 'Google Pay',
        'ideal' => 'iDEAL',
        'klarna' => 'Klarna',
        'link' => 'Link',
        'mobilepay' => 'MobilePay',
        'multibanco' => 'Multibanco',
        'p24' => 'Przelewy24',
        'pay_by_bank' => 'Pay by Bank',
        'paypal' => 'PayPal',
        'revolut_pay' => 'Revolut Pay',
        'satispay' => 'Satispay',
        'sepa_debit' => 'SEPA Direct Debit',
        'sofort' => 'Sofort',
        'swish' => 'Swish',
        'twint' => 'TWINT',
        'us_bank_account' => 'ACH Direct Debit',
        'wechat_pay' => 'WeChat Pay',
        'zip' => 'Zip',
    ];

    public function __construct(private StripeClient $stripe) {}

    public function all(?Money $amount = null, ?string $country = null, ?string $locale = null): array
    {
        $enabled = [];

        foreach ($this->defaultConfiguration() as $id => $setting) {
            if (is_string($id) && $this->isShown($setting)) {
                $enabled[] = new EnabledPaymentMethod($id, self::NAMES[$id] ?? ucwords(str_replace('_', ' ', $id)));
            }
        }

        return $enabled;
    }

    /**
     * The account's default configuration, or nothing where it has none.
     *
     * @return array<array-key, mixed>
     */
    private function defaultConfiguration(): array
    {
        foreach ($this->stripe->paymentMethodConfigurations->all(['limit' => 100])->data as $configuration) {
            $read = $configuration->toArray();

            if (($read['is_default'] ?? false) === true) {
                return $read;
            }
        }

        return [];
    }

    /** Whether an entry of the configuration is a method Stripe makes available to the account and the configuration shows. */
    private function isShown(mixed $setting): bool
    {
        return is_array($setting)
            && ($setting['available'] ?? false) === true
            && is_array($setting['display_preference'] ?? null)
            && ($setting['display_preference']['value'] ?? null) === 'on';
    }
}
