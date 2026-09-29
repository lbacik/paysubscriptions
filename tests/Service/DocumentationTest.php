<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Documentation;
use PHPUnit\Framework\TestCase;

final class DocumentationTest extends TestCase
{
    private Documentation $documentation;

    protected function setUp(): void
    {
        $this->documentation = new Documentation(dirname(__DIR__, 2) . '/resources/docs/');
    }

    public function testKnownSectionsExist(): void
    {
        self::assertTrue($this->documentation->hasSection('About'));
        self::assertTrue($this->documentation->hasSection('Api'));
    }

    /**
     * @dataProvider unsafeSectionProvider
     */
    public function testUnsafeSectionsAreUnknown(string $section): void
    {
        self::assertFalse($this->documentation->hasSection($section));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function unsafeSectionProvider(): iterable
    {
        yield 'traversal' => ['../../AGENTS'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'encoded separator' => ['..%2F..%2FAGENTS'];
        yield 'null byte' => ["Api\x00.md"];
        yield 'unknown name' => ['NoSuchSection'];
        yield 'odd casing' => ['API'];
        yield 'empty' => [''];
    }

    public function testGetContentThrowsForUnknownSectionWithoutReading(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->documentation->getContent('../../AGENTS');
    }

    public function testGetContentReturnsMarkdownForKnownSection(): void
    {
        self::assertStringContainsString(
            '# About PaySubscriptions',
            $this->documentation->getContent('About')
        );
    }
}
