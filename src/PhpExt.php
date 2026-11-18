<?php

declare(strict_types=1);

namespace Charm\ComposerPie;

/**
 * Keeps <vendor>/php-ext/conf.d in step with what PIE has installed into the project.
 *
 * One ini file per extension, `conf.d/<ext>.ini`, whose first line names the PIE package that owns it. The ini
 * files are our ledger: PIE's own record (installed.json) is not usable for that, because with INSTALL_ROOT
 * PIE's status check looks for the .so in the system extension_dir, does not find it, and drops the package
 * from its record the next time anything else is installed.
 */
final class PhpExt
{
    private const MARKER = '; managed by charm/composer-pie: ';

    /**
     * @param bool $disabled The system PHP already loads this extension, so loading it again would only warn
     * @return string The ini file contents loading $so
     */
    public static function ini(string $package, string $so, bool $zend, bool $disabled = false): string
    {
        return self::MARKER . $package . "\n"
            . ($disabled ? "; system PHP already loads it, left alone\n;" : '')
            . ($zend ? 'zend_extension' : 'extension') . '=' . $so . "\n";
    }

    /**
     * Write an ini file for every extension PIE has just installed, and drop ini files whose .so has gone.
     *
     * @param callable(string): bool $systemLoads Whether the system PHP (without our conf.d) loads extension $name
     * @return list<string> names of the extensions installed in the project
     */
    public static function sync(string $phpExtDir, callable $systemLoads): array
    {
        $confDir = $phpExtDir . '/conf.d';
        foreach (glob($phpExtDir . '/pie/php*/vendor/composer/installed.json') ?: [] as $file) {
            $packages = json_decode((string) file_get_contents($file), true)['packages'] ?? [];
            foreach ($packages as $package) {
                $so = $package['extra']['pie-installed-binary'] ?? null;
                if (!is_string($so) || !str_starts_with($package['type'] ?? '', 'php-ext') || !is_file($so)) {
                    continue;
                }
                $name = basename($so, '.so');
                @mkdir($confDir, 0777, true);
                file_put_contents($confDir . '/' . $name . '.ini', self::ini(
                    $package['name'],
                    $so,
                    $package['type'] === 'php-ext-zend',
                    $systemLoads($name),
                ));
            }
        }

        $present = [];
        foreach (glob($confDir . '/*.ini') ?: [] as $ini) {
            $contents = (string) file_get_contents($ini);
            if (!str_starts_with($contents, self::MARKER)) {
                continue;
            }
            if (preg_match('/^;?(?:zend_)?extension=(.+)$/m', $contents, $m) && !is_file($m[1])) {
                unlink($ini);
            } else {
                $present[] = basename($ini, '.ini');
            }
        }

        return $present;
    }

    /** Remove the extension installed from PIE package $package: its ini, its .so and its pie.json requirement. */
    public static function uninstall(string $phpExtDir, string $package): bool
    {
        $found = false;
        foreach (glob($phpExtDir . '/conf.d/*.ini') ?: [] as $ini) {
            $contents = (string) file_get_contents($ini);
            if (!str_starts_with($contents, self::MARKER . $package . "\n")) {
                continue;
            }
            if (preg_match('/^;?(?:zend_)?extension=(.+)$/m', $contents, $m) && str_starts_with($m[1], $phpExtDir . '/root/')) {
                @unlink($m[1]);
            }
            unlink($ini);
            $found = true;
        }

        foreach (glob($phpExtDir . '/pie/php*/pie.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (isset($json['require'][$package])) {
                unset($json['require'][$package]);
                file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
        }

        return $found;
    }
}
