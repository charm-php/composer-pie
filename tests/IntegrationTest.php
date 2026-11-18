<?php

declare(strict_types=1);

namespace Charm\ComposerPie\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real PIE, real downloads, real compiler: installs extensions into a temporary project and checks they load
 * through vendor/bin/php and `composer exec`, and nowhere else. Run with: vendor/bin/phpunit --group integration
 */
#[Group('integration')]
final class IntegrationTest extends TestCase
{
    private string $project;
    private string $sudoLog;
    private string $env;

    protected function setUp(): void
    {
        $base = realpath(getenv('COMPOSER_PIE_TEST_DIR') ?: sys_get_temp_dir());
        $this->project = $base . '/composer-pie-it-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/shims', 0777, true);
        // Any use of sudo is a failure: this shim records the call and fails
        $this->sudoLog = $this->project . '/sudo.log';
        file_put_contents($this->project . '/shims/sudo', "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->sudoLog) . "\nexit 1\n");
        chmod($this->project . '/shims/sudo', 0755);
        $this->env = 'COMPOSER_ROOT_VERSION=1.0.0 PATH=' . escapeshellarg($this->project . '/shims:' . getenv('PATH')) . ' ';

        file_put_contents($this->project . '/composer.json', json_encode([
            'name' => 'test/project',
            'repositories' => [['type' => 'path', 'url' => dirname(__DIR__), 'options' => ['symlink' => true]]],
            'minimum-stability' => 'dev',
        ]));
        $this->sh('composer require charm/composer-pie:@dev --no-interaction 2>&1');
    }

    protected function tearDown(): void
    {
        if (!getenv('COMPOSER_PIE_KEEP')) {
            exec('rm -rf ' . escapeshellarg($this->project));
        }
    }

    /** Run in the project, assert success, return output. */
    private function sh(string $command, int $expectedStatus = 0): string
    {
        exec('cd ' . escapeshellarg($this->project) . ' && ' . $this->env . $command . ' 2>&1', $out, $status);
        $out = implode("\n", $out);
        $this->assertSame($expectedStatus, $status, "$command\n$out");

        return $out;
    }

    private function systemSnapshot(): string
    {
        $dirs = [trim((string) shell_exec('php -r \'echo ini_get("extension_dir");\'')), '/etc/php', '/usr/local/etc/php'];

        return (string) shell_exec('find ' . implode(' ', array_map('escapeshellarg', array_filter($dirs, 'is_dir'))) . ' -printf "%p %T@ %s\n" 2>/dev/null | sort');
    }

    public function testInstallIntoProjectLoadsOnlyThroughVendorBinPhp(): void
    {
        $before = $this->systemSnapshot();

        $this->sh('vendor/bin/pie install xdebug/xdebug');
        $this->sh('vendor/bin/pie install php-ds/ext-ds');

        $check = 'echo extension_loaded("xdebug") ? "xdebug=yes" : "xdebug=no", " ", extension_loaded("ds") ? "ds=yes" : "ds=no";';
        $this->assertSame('xdebug=yes ds=yes', $this->sh('vendor/bin/php -r ' . escapeshellarg($check)));
        $this->assertSame('xdebug=yes ds=yes', $this->sh('composer exec --no-interaction -- php -r ' . escapeshellarg($check)));
        $this->assertSame('xdebug=no ds=no', $this->sh('env -u PHP_INI_SCAN_DIR php -r ' . escapeshellarg($check)));

        // Project mode sees them as installed, because it runs the target PHP with the project's conf.d
        $out = $this->sh('vendor/bin/pie install </dev/null');
        $this->assertStringContainsString('Finished checking extensions', $out);

        $this->sh('vendor/bin/pie uninstall php-ds/ext-ds');
        $this->assertSame('xdebug=yes ds=no', $this->sh('vendor/bin/php -r ' . escapeshellarg($check)));

        $this->assertSame($before, $this->systemSnapshot(), 'system extension_dir / ini dirs changed');
        $this->assertFileDoesNotExist($this->sudoLog, 'sudo was invoked');
    }

    public function testExtensionTheSystemAlreadyLoadsIsLeftAlone(): void
    {
        if (trim((string) shell_exec('php -r \'echo extension_loaded("apcu") ? 1 : 0;\'')) !== '1') {
            $this->markTestSkipped('apcu is not loaded system-wide here');
        }
        $this->sh('vendor/bin/pie install apcu/apcu');
        $this->assertSame('1', $this->sh('vendor/bin/php -r \'echo (int) extension_loaded("apcu");\''), 'no "already loaded" warning expected');
    }

    /** Regression: one conf.d was shared by all PHP builds, so the second PHP loaded the first one's .so. */
    public function testEachPhpVersionLoadsItsOwnBuild(): void
    {
        $other = '/usr/bin/php8.4';
        if (!is_executable($other) || PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION === '8.4') {
            $this->markTestSkipped('needs /usr/bin/php8.4 and a different php running the tests');
        }
        $check = 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, " ds=", (int) extension_loaded("ds");';

        $this->sh('vendor/bin/pie install php-ds/ext-ds');
        $this->sh('COMPOSER_PIE_PHP=' . $other . ' vendor/bin/pie install php-ds/ext-ds');

        // exact output: no "Unable to initialize module" warnings
        $this->assertSame('8.4 ds=1', $this->sh('COMPOSER_PIE_PHP=' . $other . ' vendor/bin/php -r ' . escapeshellarg($check)));
        $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' ds=1', $this->sh('vendor/bin/php -r ' . escapeshellarg($check)));
        $this->assertCount(2, glob($this->project . '/vendor/php-ext/conf.d/*/ds.ini'));

        // an empty argument is not an error
        $this->assertStringNotContainsString('Warning', $this->sh('vendor/bin/pie "" list', 1));
    }
}
