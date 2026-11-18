<?php

declare(strict_types=1);

namespace Charm\ComposerPie\Tests;

use Charm\ComposerPie\Toolchain;
use PHPUnit\Framework\TestCase;

final class ToolchainTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composer-pie-unit-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function tool(string $name): void
    {
        touch($this->dir . '/' . $name);
        chmod($this->dir . '/' . $name, 0755);
    }

    public function testReportsEveryMissingTool(): void
    {
        $this->assertSame(['a C compiler (cc, gcc or clang)', 'make', 'autoconf', 'm4'], Toolchain::missingTools($this->dir));
    }

    public function testAnyCompilerAndMakeNameCounts(): void
    {
        foreach (['clang', 'gmake', 'autoconf', 'm4'] as $tool) {
            $this->tool($tool);
        }
        $this->assertSame([], Toolchain::missingTools($this->dir));
    }

    public function testNonExecutableFilesDoNotCount(): void
    {
        touch($this->dir . '/gcc');
        $this->assertContains('a C compiler (cc, gcc or clang)', Toolchain::missingTools($this->dir));
    }
}
