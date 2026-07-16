<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * The severity of an account toast relayed over the realtime bridge. A broadcast payload is untrusted, so the
 * bridge clamps an unknown or missing level back to {@see self::Info} rather than trusting the wire.
 */
enum ToastLevel: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';

    /** Resolve an untrusted wire value to a known level, defaulting to Info. */
    public static function fromWire(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Info) : self::Info;
    }
}
