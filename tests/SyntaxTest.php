<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Lint every PHP file in the project to catch syntax errors.
 *
 * This runs outside Brain Monkey — no WordPress mocking needed.
 */
class SyntaxTest extends PolyfillTestCase
{
    /**
     * Return every PHP file in the project (excluding vendor/).
     *
     * @return array<string, array{string}>
     */
    public function phpFileProvider(): array
    {
        $root = dirname(__DIR__);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            // Skip vendor directory
            if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                continue;
            }

            // Use relative path as dataset key for readable test names
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            $files[$relative] = [$path];
        }

        return $files;
    }

    /**
     * @dataProvider phpFileProvider
     */
    public function test_php_file_has_valid_syntax(string $path): void
    {
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $exitCode);
        $this->assertSame(
            0,
            $exitCode,
            "Syntax error in $path:\n" . implode("\n", $output)
        );
    }
}
