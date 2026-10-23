<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie;

use Mollie\Api\Http\Requests\GetEnabledMethodsRequest;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;
use Mollie\Api\Resources\MethodCollection;
use Pushery\Billing\Contracts\EnabledPaymentMethods;
use Pushery\Billing\ValueObjects\EnabledPaymentMethod;
use Pushery\Billing\ValueObjects\Money;
use Pushery\Billing\ValueObjects\PaymentMethodIssuer;

/**
 * The payment methods a Mollie account has turned on, from Mollie's Methods API.
 *
 * Mollie applies all three filters: an amount returns the methods that take it in its currency (without one, Mollie
 * answers for the euro), a billing country the methods offered there, and a locale translates the names. In live
 * mode the list holds the methods that are fully enabled; in test mode Mollie adds the ones still pending, so a test
 * checkout can try them. The banks and brands come along for the methods that have them. The wallets a checkout
 * names go to Mollie as `includeWallets`, the way it learns that the checkout can show them; without any the
 * request asks for none.
 */
final readonly class MollieEnabledPaymentMethods implements EnabledPaymentMethods
{
    /**
     * @param  MollieApiClient  $client  the account's client, with its credential and test mode
     * @param  ?string  $profileId  the profile the methods are read on, which Mollie requires with an OAuth access token
     */
    public function __construct(
        private MollieApiClient $client,
        private ?string $profileId = null,
    ) {}

    public function all(?Money $amount = null, ?string $country = null, ?string $locale = null, array $wallets = []): array
    {
        $methods = MollieValue::narrow($this->client->send(new GetEnabledMethodsRequest(
            locale: $locale,
            amount: $amount instanceof Money ? MollieAmount::toMollie($amount) : null,
            billingCountry: $country,
            includeWallets: $wallets === [] ? null : $wallets,
            profileId: $this->profileId,
            includeIssuers: true,
        )), MethodCollection::class);

        $enabled = [];

        foreach ($methods ?? [] as $item) {
            // Version 3 of the SDK yields what its collection holds untyped, version 4 a Method.
            $method = MollieValue::narrow($item, Method::class);

            if ($method instanceof Method) {
                $enabled[] = new EnabledPaymentMethod(
                    $method->id,
                    $method->description,
                    $this->imageOf($method->image),
                    $this->limitOf($method->minimumAmount),
                    $this->limitOf($method->maximumAmount),
                    $this->issuersOf($method->issuers),
                );
            }
        }

        return $enabled;
    }

    /**
     * A limit of a method, or null where Mollie states none. Version 4 of the SDK hands a limit over as its money
     * object, version 3 as the decoded JSON object, and both carry `value` and `currency`.
     */
    private function limitOf(mixed $amount): ?Money
    {
        return is_object($amount) ? MollieAmount::fromResource($amount) : null;
    }

    /** The address of a logo, the vector version where Mollie has one, or null where it sent none. */
    private function imageOf(mixed $image): ?string
    {
        if (! is_object($image)) {
            return null;
        }

        $address = array_find(
            [
                property_exists($image, 'svg') ? $image->svg : null,
                property_exists($image, 'size2x') ? $image->size2x : null,
                property_exists($image, 'size1x') ? $image->size1x : null,
            ],
            static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '',
        );

        return is_string($address) ? $address : null;
    }

    /**
     * The issuers of a method, which both majors of the SDK hand over as the decoded JSON objects.
     *
     * @return list<PaymentMethodIssuer>
     */
    private function issuersOf(mixed $issuers): array
    {
        if (! is_iterable($issuers)) {
            return [];
        }

        $found = [];

        foreach ($issuers as $issuer) {
            if (is_object($issuer) && property_exists($issuer, 'id') && property_exists($issuer, 'name')
                && is_string($issuer->id) && is_string($issuer->name)) {
                $found[] = new PaymentMethodIssuer($issuer->id, $issuer->name, $this->imageOf(property_exists($issuer, 'image') ? $issuer->image : null));
            }
        }

        return $found;
    }
}
