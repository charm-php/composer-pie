<?php

declare(strict_types=1);

namespace Charm\ComposerPie\Tests;

use Charm\ComposerPie\PhpExt;
use PHPUnit\Framework\TestCase;

final class PhpExtTest extends TestCase
{
    private string $dir;
    private string $conf;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composer-pie-unit-' . bin2hex(random_bytes(4));
        $this->conf = $this->dir . '/conf.d/usr_bin_php8.5';
        mkdir($this->dir . '/root/usr/lib/php', 0777, true);
        mkdir($this->dir . '/pie/php8.5_abc/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function sync(callable $systemLoads): array
    {
        return PhpExt::sync($this->dir, $this->conf, $this->dir . '/root/usr/lib/php/', $systemLoads);
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

        $this->assertEqualsCanonicalizing(['xdebug', 'ds'], array_keys($this->sync(fn () => false)));
        $this->assertStringContainsString("zend_extension=$xdebug\n", file_get_contents($this->conf . '/xdebug.ini'));
        $this->assertStringContainsString("\nextension=$ds\n", file_get_contents($this->conf . '/ds.ini'));
    }

    public function testSyncDisablesExtensionsTheSystemAlreadyLoads(): void
    {
        $this->installed('apcu/apcu', 'apcu', 'php-ext');

        $this->sync(fn (string $name) => $name === 'apcu');
        $this->assertStringContainsString("\n;extension=", file_get_contents($this->conf . '/apcu.ini'));
    }

    public function testSyncKeepsIniFilesPieNoLongerRecordsButDropsOnesWhoseSoIsGone(): void
    {
        $this->installed('xdebug/xdebug', 'xdebug', 'php-ext-zend');
        $this->sync(fn () => false);
        // PIE forgets xdebug once something else is installed (see PhpExt docblock)
        unlink($this->dir . '/pie/php8.5_abc/vendor/composer/installed.json');
        $ds = $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        $this->assertEqualsCanonicalizing(['xdebug', 'ds'], array_keys($this->sync(fn () => false)));

        unlink($ds);
        $this->assertSame(['xdebug' => true], $this->sync(fn () => false));
        $this->assertFileDoesNotExist($this->conf . '/ds.ini');
    }

    public function testSyncLeavesForeignIniFilesAlone(): void
    {
        mkdir($this->conf, 0777, true);
        file_put_contents($this->conf . '/mine.ini', "extension=/nowhere.so\n");
        $this->sync(fn () => false);
        $this->assertFileExists($this->conf . '/mine.ini');
    }

    public function testUninstallRemovesIniSoAndRequirement(): void
    {
        $ds = $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        $this->sync(fn () => false);

        $this->assertSame([], PhpExt::uninstall($this->dir, $this->conf, 'nobody/nothing'));
        $this->assertSame(['ds'], PhpExt::uninstall($this->dir, $this->conf, 'php-ds/ext-ds'));
        $this->assertFileDoesNotExist($ds);
        $this->assertFileDoesNotExist($this->conf . '/ds.ini');
        $this->assertSame([], json_decode(file_get_contents($this->dir . '/pie/php8.5_abc/pie.json'), true)['require']);
    }

    public function testSyncOnlyWritesExtensionsBuiltForTheTargetPhp(): void
    {
        $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        // an extension installed for another PHP (other extension_dir) into the same project
        mkdir($this->dir . '/root/usr/lib/php84');
        $other = $this->dir . '/root/usr/lib/php84/xdebug.so';
        touch($other);
        $file = $this->dir . '/pie/php8.5_abc/vendor/composer/installed.json';
        $json = json_decode(file_get_contents($file), true);
        $json['packages'][] = ['name' => 'xdebug/xdebug', 'type' => 'php-ext-zend', 'extra' => ['pie-installed-binary' => $other]];
        file_put_contents($file, json_encode($json));

        $this->assertSame(['ds' => true], $this->sync(fn () => false));
    }

    public function testConfDirIsKeyedByResolvedBinary(): void
    {
        $this->assertSame($this->dir . '/conf.d/' . trim(str_replace('/', '_', realpath(PHP_BINARY)), '_'), PhpExt::confDirFor($this->dir, PHP_BINARY));
        symlink(PHP_BINARY, $this->dir . '/php');
        $this->assertSame(PhpExt::confDirFor($this->dir, PHP_BINARY), PhpExt::confDirFor($this->dir, $this->dir . '/php'));
    }

    public function testUninstallKeepsPieRequirementWhileAnotherPhpStillHasThePackage(): void
    {
        $this->installed('php-ds/ext-ds', 'ds', 'php-ext');
        $this->sync(fn () => false);
        mkdir($this->dir . '/conf.d/usr_bin_php8.4');
        file_put_contents($this->dir . '/conf.d/usr_bin_php8.4/ds.ini', PhpExt::ini('php-ds/ext-ds', '/x/ds.so', false));

        $this->assertSame(['ds'], PhpExt::uninstall($this->dir, $this->conf, 'php-ds/ext-ds'));
        $this->assertArrayHasKey('php-ds/ext-ds', json_decode(file_get_contents($this->dir . '/pie/php8.5_abc/pie.json'), true)['require']);
    }
}
