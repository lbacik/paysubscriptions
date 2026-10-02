<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\TestCase;

/**
 * Guards rollback safety for the v1 release (issue #100).
 *
 * The release deploys with `doctrine:migrations:migrate` (non-transactional:
 * MySQL commits DDL implicitly, so a failed run can leave a partly migrated
 * schema and re-running must be safe) and rolls back by redeploying the previous image tag against the already
 * migrated schema. That only works while migrations are additive: new tables,
 * new nullable or defaulted columns, new indexes. Anything destructive in
 * up() — a dropped table or column, a rename, a truncate — breaks the
 * previous image, which still reads the old shape.
 *
 * This test scans every migration's up() body for destructive statements.
 * Migrations deployed before the v1 baseline are allowlisted with their
 * justification; anything newer must stay additive. Runs anywhere: it reads
 * files, never touches a database.
 *
 * Index-only changes (DROP/CREATE INDEX) are intentionally allowed: they
 * never break the previous image's queries, only their speed. The v1
 * baseline migration itself swaps secondary indexes for the composite the
 * messenger transport mapping expects.
 */
final class AdditiveMigrationTest extends TestCase
{
    /**
     * Migrations whose up() is knowingly destructive, with why the rollback
     * story still holds. The v1 rollback baseline starts after these: no
     * supported rollback crosses them.
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [
        // Billing-cycle replacement (#37): drops monthly/yearly/first_payment
        // after backfilling. Landed before the API release window; the v1
        // rollback baseline is the schema after it.
        'Version20260923190000' => 'ALTER TABLE subscription DROP COLUMN',
    ];

    public function testUpMigrationsStayAdditive(): void
    {
        $dir = \dirname(__DIR__, 2).'/migrations';
        $files = \glob($dir.'/Version*.php');
        self::assertNotEmpty($files, 'No migrations found to guard.');

        $violations = [];
        foreach ($files as $file) {
            $version = \basename($file, '.php');
            $up = self::upBody((string) \file_get_contents($file));

            foreach (self::destructiveStatements($up) as $statement) {
                $allowlisted = isset(self::ALLOWLIST[$version])
                    && \str_contains($statement, self::ALLOWLIST[$version]);
                if (!$allowlisted) {
                    $violations[] = \sprintf('%s: %s', $version, $statement);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Destructive statements in migration up() break previous-image rollback.\n".\implode("\n", $violations)
        );
    }

    public function testAllowlistEntriesStillMatch(): void
    {
        foreach (self::ALLOWLIST as $version => $needle) {
            $file = \dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            self::assertFileExists($file, \sprintf('Allowlisted migration %s disappeared; drop it from ALLOWLIST.', $version));
            self::assertStringContainsString(
                $needle,
                self::upBody((string) \file_get_contents($file)),
                \sprintf('Allowlisted migration %s no longer contains its destructive statement; drop it from ALLOWLIST.', $version)
            );
        }
    }

    private static function upBody(string $contents): string
    {
        $upPos = \strpos($contents, 'function up(');
        self::assertNotFalse($upPos, 'Migration without up().');

        $downPos = \strpos($contents, 'function down(', $upPos);

        return false === $downPos ? \substr($contents, $upPos) : \substr($contents, $upPos, $downPos - $upPos);
    }

    /**
     * @return list<string>
     */
    private static function destructiveStatements(string $upBody): array
    {
        $found = [];
        foreach (\explode(';', $upBody) as $chunk) {
            // Index-only changes are rollback-safe (they cost the previous
            // image speed, never correctness), so they are carved out here
            // explicitly rather than passing by accident of the pattern
            // below. The v1 baseline migration relies on this allowance.
            if (1 === \preg_match('/\b(DROP|CREATE)\s+INDEX\b/i', $chunk)) {
                continue;
            }
            if (1 === \preg_match('/\b(DROP\s+(TABLE|COLUMN)|RENAME\s+(TABLE|COLUMN|TO)|TRUNCATE(\s+TABLE)?)\b/i', $chunk)) {
                $found[] = \trim((string) \preg_replace('/\s+/', ' ', $chunk));
            }
        }

        return $found;
    }
}
