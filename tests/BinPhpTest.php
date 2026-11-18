<?php

declare(strict_types=1);

namespace Charm\ComposerPie\Tests;

use Charm\ComposerPie\PhpExt;
use PHPUnit\Framework\TestCase;

/** bin/php, run the way Composer's vendor/bin proxy runs it, in a fake project. */
final class BinPhpTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = realpath(sys_get_temp_dir()) . '/composer-pie-unit-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/vendor/bin', 0777, true);
        // Stand-in for Composer's generated sh proxy: mentions the package, sets COMPOSER_RUNTIME_BIN_DIR
        file_put_contents($this->project . '/vendor/bin/php', sprintf(
            "#!/bin/sh\n# ../charm/composer-pie/bin\nexport COMPOSER_RUNTIME_BIN_DIR=%s\nexec %s \"\$@\"\n",
            escapeshellarg($this->project . '/vendor/bin'),
            escapeshellarg(dirname(__DIR__) . '/bin/php'),
        ));
        chmod($this->project . '/vendor/bin/php', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function phpWith(string $env, string $code): string
    {
        $cmd = sprintf('%s %s/vendor/bin/php -r %s 2>&1', $env, escapeshellarg($this->project), escapeshellarg($code));

        return (string) shell_exec($cmd);
    }

    public function testPrependsProjectConfDAndKeepsSystemDir(): void
    {
        $out = $this->phpWith('env -u PHP_INI_SCAN_DIR PATH=' . escapeshellarg($this->project . '/vendor/bin:' . getenv('PATH')), 'echo getenv("PHP_INI_SCAN_DIR");');
        $this->assertSame(':' . PhpExt::confDirFor($this->project . '/vendor/php-ext', PHP_BINARY), $out);
    }

    public function testKeepsExistingScanDirAndDoesNotAddItTwice(): void
    {
        $confd = PhpExt::confDirFor($this->project . '/vendor/php-ext', PHP_BINARY);
        $out = $this->phpWith('PHP_INI_SCAN_DIR=/a:/b', 'echo getenv("PHP_INI_SCAN_DIR");');
        $this->assertSame("/a:/b:$confd", $out);
        $out = $this->phpWith('PHP_INI_SCAN_DIR=:' . $confd, 'echo getenv("PHP_INI_SCAN_DIR");');
        $this->assertSame(":$confd", $out);
    }

    public function testFindsTheRealPhpWhenTheProxyIsFirstOnPath(): void
    {
        $out = $this->phpWith('PATH=' . escapeshellarg($this->project . '/vendor/bin:' . getenv('PATH')), 'echo PHP_SAPI;');
        $this->assertSame('cli', $out);
    }

    public function testComposerPiePhpOverridesPathSearch(): void
    {
        $out = $this->phpWith('COMPOSER_PIE_PHP=' . escapeshellarg(PHP_BINARY) . ' PATH=' . escapeshellarg($this->project . '/vendor/bin:' . getenv('PATH')), 'echo PHP_BINARY;');
        $this->assertSame(PHP_BINARY, $out);
    }

    public function testFailsClearlyWithoutAnyRealPhp(): void
    {
        // a PATH with the coreutils bin/php needs, but no php
        mkdir($this->project . '/tools');
        foreach (['readlink', 'head', 'grep', 'dirname'] as $tool) {
            symlink(trim((string) shell_exec('command -v ' . $tool)), $this->project . '/tools/' . $tool);
        }
        $out = $this->phpWith('PATH=' . escapeshellarg($this->project . '/vendor/bin:' . $this->project . '/tools') . ' COMPOSER_PIE_PHP=', 'echo 1;');
        $this->assertStringContainsString('no real php found', $out);
    }
}
