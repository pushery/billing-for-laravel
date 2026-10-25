<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/** What a contract confirmation confirms: a subscription to a tier, or the purchase of an add-on. */
enum ContractKind: string
{
    case Subscription = 'subscription';
    case Purchase = 'purchase';
}
