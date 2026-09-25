<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A reporting profile whose duty obliges the platform to act when a seller does not supply their data.
 *
 * A marker, read by the escalation sweep before it looks at a single seller. Whether a platform has to chase
 * missing seller data at all is a rule of the reporting regime, not of the package: a consumer under a duty
 * that asks nothing of the kind binds a profile without this marker and gets no reminders, no measures and
 * no rows. The deadlines themselves are configuration, with the profile's values as the shipped defaults.
 */
interface EscalatesMissingSellerData {}
