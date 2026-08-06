<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Where a recorded tax status came from.
 *
 * It is stored because the four are not equally strong evidence, and an auditor asking why a document was
 * issued the way it was needs to know which one answered. A status somebody typed about themselves and a
 * status a registry confirmed produce the same enum case and carry very different weight.
 */
enum CreatorTaxStatusSource: string
{
    /** The creator told us. */
    case SelfDeclaration = 'self_declaration';

    /** An official registry confirmed it. */
    case RegistryCheck = 'registry_check';

    /** The system moved them itself, because a threshold was crossed. */
    case AutoFlip = 'auto_flip';

    /** Somebody at the platform set it by hand. */
    case Admin = 'admin';
}
