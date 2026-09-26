<?php

declare(strict_types=1);

namespace App\Tests;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;
use PHPUnit\Runner\BeforeTestHook;

/**
 * DAMA's PHPUnit 9 extension, plus the #[WithoutDatabaseRollback] opt-out.
 */
final class DatabaseTransactionExtension implements BeforeFirstTestHook, AfterLastTestHook, BeforeTestHook, AfterTestHook
{
    private bool $transactionStarted = false;

    public function executeBeforeFirstTest(): void
    {
        StaticDriver::setKeepStaticConnections(true);
    }

    public function executeBeforeTest(string $test): void
    {
        if (self::optsOut($test)) {
            // Fresh connections: DDL such as DROP DATABASE would otherwise
            // leave the shared static connection without a selected schema.
            StaticDriver::setKeepStaticConnections(false);

            return;
        }

        StaticDriver::beginTransaction();
        $this->transactionStarted = true;
    }

    public function executeAfterTest(string $test, float $time): void
    {
        if (!$this->transactionStarted) {
            StaticDriver::setKeepStaticConnections(true);

            return;
        }

        StaticDriver::rollBack();
        $this->transactionStarted = false;
    }

    public function executeAfterLastTest(): void
    {
        StaticDriver::setKeepStaticConnections(false);
    }

    private static function optsOut(string $test): bool
    {
        $class = explode('::', $test)[0];

        return class_exists($class)
            && [] !== (new \ReflectionClass($class))->getAttributes(WithoutDatabaseRollback::class);
    }
}
