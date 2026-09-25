<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Opts a test class out of the per-test rollback transaction, for tests that
 * run DDL (which MySQL commits implicitly). Such a class must leave the test
 * database migrated and empty when it finishes.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class WithoutDatabaseRollback
{
}
