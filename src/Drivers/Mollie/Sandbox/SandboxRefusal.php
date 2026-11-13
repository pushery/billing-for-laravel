<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use RuntimeException;

/**
 * A request the sandbox answers the way Mollie answers one it refuses: with a status and a sentence. The adapter turns
 * it into Mollie's error body, so the SDK raises the exception it raises for Mollie's own refusal.
 *
 * @internal
 */
final class SandboxRefusal extends RuntimeException
{
    public function __construct(public readonly int $status, string $detail)
    {
        parent::__construct($detail);
    }

    public static function notFound(string $what): self
    {
        return new self(404, "The sandbox knows no {$what}.");
    }

    public static function unsupported(string $method, string $path): self
    {
        return new self(422, "The sandbox does not answer {$method} {$path}. It answers the customers, mandates, payments and refunds of the install's own payments; everything else needs Mollie itself.");
    }
}
