<?php

declare(strict_types=1);

namespace App\Connection;

/**
 * Domain answer to "may this Client act for this User?" (issue #194,
 * CONTEXT.md **Connection**, ADR 0005).
 *
 * No exceptions for normal refusals: each checkpoint (authorize, refresh,
 * `/api/v1`) asks the policy and translates a refusal into its own protocol.
 * The refusal reasons keep "User not eligible" apart from "Client not
 * approved", and the Client side further separates an unknown or inactive
 * Client from one that is simply not approved for the requested scope, so
 * `/api/v1` can keep its distinct 401/403 problem codes.
 */
enum ConnectionDecision
{
    case Allowed;
    case UserNotEligible;
    case ClientUnknownOrInactive;
    case ClientScopeNotApproved;
}
