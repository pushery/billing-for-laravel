<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The kind of cancellation a person declares through the cancellation button: an ordinary one, which ends the
 * contract on its own terms, or an extraordinary one, which ends it for a reason the person gives.
 *
 * The labels live in the translation layer, under `billing::declarations.cancellation.kinds`.
 */
enum CancellationKind: string
{
    case Ordinary = 'ordinary';
    case Extraordinary = 'extraordinary';
}
