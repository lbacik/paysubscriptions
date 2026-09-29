<?php

declare(strict_types=1);

namespace App\Service;

readonly class Documentation
{
    private const EXCLUDED_FILES = ['Dashboard.md'];

    public function __construct(
        private string $docsPath,
    ) {
    }

    public function getMenuItems(): array
    {
        $files = scandir($this->docsPath, SCANDIR_SORT_ASCENDING);

        return array_reduce(
            array_filter($files, fn(string $file) => pathinfo($file, PATHINFO_EXTENSION) === 'md'),
            function (array $menuItems, string $file) {
                if (!in_array($file, self::EXCLUDED_FILES, true)) {
                    $menuItems[pathinfo($file, PATHINFO_FILENAME)] = $this->getChapters($file);
                }
                return $menuItems;
            },
            []
        );
    }

    public function hasSection(string $section): bool
    {
        return array_key_exists($section, $this->getMenuItems());
    }

    public function getContent(string $section): string
    {
        if (!$this->hasSection($section)) {
            throw new \InvalidArgumentException(sprintf('Unknown documentation section "%s".', $section));
        }

        $content = file_get_contents($this->docsPath . $section . '.md');

        if (false === $content) {
            throw new \RuntimeException(sprintf('Unable to read documentation section "%s".', $section));
        }

        return $content;
    }

    private function getChapters(string $file): array
    {
        $fileContent = file_get_contents($this->docsPath . $file);
        $lines = explode("\n", $fileContent);

        return array_reduce(
            array_filter($lines, fn(string $line) => str_starts_with($line, '## ')),
            function (array $chapters, string $line) {
                $chapters[] = substr($line, 3);
                return $chapters;
            },
            []
        );
    }
}
