<?php

declare(strict_types=1);

namespace Charm\ComposerPie\Tests;

use Charm\ComposerPie\PhpExt;
use PHPUnit\Framework\TestCase;

final class PhpExtTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composer-pie-unit-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/root/usr/lib/php', 0777, true);
        mkdir($this->dir . '/pie/php8.5_abc/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** Pretend PIE installed $name (type $type) and recorded it. */
    private function installed(string $package, string $name, string $type): string
    {
        $so = $this->dir . "/root/usr/lib/php/$name.so";
        touch($so);
        $file = $this->dir . '/pie/php8.5_abc/vendor/composer/installed.json';
        $json = is_file($file) ? json_decode(file_get_contents($file), true) : ['packages' => []];
        $json['packages'][] = ['name' => $package, 'type' => $type, 'extra' => ['pie-installed-binary' => $so]];
        file_put_contents($file, json_encode($json));
        file_put_contents($this->dir . '/pie/php8.5_abc/pie.json', json_encode(['require' => [$package => '*']]));

        return $so;
    }

    public function testIniUsesExtensionOrZendExtension(): void
    {
        $this->assertStringContainsString("\nextension=/x/ds.so\n", PhpExt::ini('php-ds/ext-ds', '/x/ds.so', false));
        $this->assertStringContainsString("\nzend_extension=/x/xdebug.so\n", PhpExt::ini('xdebug/xdebug', '/x/xdebug.so', true));
        $this->assertStringContainsString("\n;extension=/x/apcu.so\n", PhpExt::ini('apcu/apcu', '/x/apcu.so', false, true));
    }

    public function testSyncWritesOneIniPerExtensionWithTheRightDirective(): void
    {
        $xdebug = $this->installed('xdebug/xdebug', 'xdebug', 'php-ext-zend');
        $ds = $this->installed('php-ds/ext-ds', 'ds', 'php-ext');

        $this->assertEqualsCanonicalizing(['xdebug', 'ds'], PhpExt::sync($this->dir, fn () => false));
        $this->assertStringContainsString("zend_extension=$xdebug\n", file_get_contents($this->dir . '/conf.d/xdebug.ini'));
        $this->assertStringContainsString("\nextension=$ds\n", file_get_contents($this->dir . '/conf.d/ds.ini'));
    }

    public function testSyncDisablesExtensionsTheSystemAlreadyLoads(): void
    {
        $this->installed('apcu/apcu', 'apcu', 'php-ext');

        PhpExt::sync($this->dir, fn (string $name) => $name === 'apcu');
        $this->assertStringContainsString("\n;extension=", file_get_contents($this->dir . '/conf.d/apcu.ini'));
    }

    public function testSyncKeepsIniFilesPieNoLongerRecordsButDropsOnesWhoseSoIsGone(): void
    {
        $this->installed('xdebug/xdebug', 'xdebug', 'php-ext-zend');
        PhpExt::sync($this->dir, fn () => false);
        // PIE forgets xdebug once something else is installed (see PhpExt docblock)
        unlink($this->dir . '/pie/php8.5_abc/vendor/composer/installed.json');
        $ds = $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        $this->assertEqualsCanonicalizing(['xdebug', 'ds'], PhpExt::sync($this->dir, fn () => false));

        unlink($ds);
        $this->assertSame(['xdebug'], PhpExt::sync($this->dir, fn () => false));
        $this->assertFileDoesNotExist($this->dir . '/conf.d/ds.ini');
    }

    public function testSyncLeavesForeignIniFilesAlone(): void
    {
        mkdir($this->dir . '/conf.d');
        file_put_contents($this->dir . '/conf.d/mine.ini', "extension=/nowhere.so\n");
        PhpExt::sync($this->dir, fn () => false);
        $this->assertFileExists($this->dir . '/conf.d/mine.ini');
    }

    public function testUninstallRemovesIniSoAndRequirement(): void
    {
        $ds = $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        PhpExt::sync($this->dir, fn () => false);

        $this->assertFalse(PhpExt::uninstall($this->dir, 'nobody/nothing'));
        $this->assertTrue(PhpExt::uninstall($this->dir, 'php-ds/ext-ds'));
        $this->assertFileDoesNotExist($ds);
        $this->assertFileDoesNotExist($this->dir . '/conf.d/ds.ini');
        $this->assertSame([], json_decode(file_get_contents($this->dir . '/pie/php8.5_abc/pie.json'), true)['require']);
    }
}
