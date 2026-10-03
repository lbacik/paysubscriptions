<?php

declare(strict_types=1);

namespace App\Mailer;

/**
 * The PaySubscriptions SES tenant boundary, owned by the aws-config
 * `PaysubsSesTenantStack`.
 *
 * These values are code, not configuration: neither the DSN, a message
 * header, nor a caller can select another tenant, configuration set, Region
 * or From identity. The dedicated IAM principal is limited to exactly these
 * values, so a change here must ship together with the infrastructure change.
 */
final class SesTenantBoundary
{
    public const ACCOUNT = '045689588845';
    public const TENANT_NAME = 'paysubs-app';
    public const CONFIGURATION_SET = 'paysubs-app-events';
    public const REGION = 'eu-central-1';
    public const FROM_ADDRESS = 'no-reply@paysubscriptions.com';

    private function __construct()
    {
    }
}
