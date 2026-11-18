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
     * The conf.d directory for one PHP binary: named after its resolved path, so `php` and `php8.5` share one.
     * bin/php computes the same name in sh.
     */
    public static function confDirFor(string $phpExtDir, string $phpBinary): string
    {
        $real = realpath($phpBinary) ?: $phpBinary;

        return $phpExtDir . '/conf.d/' . preg_replace('/[^A-Za-z0-9._-]/', '_', ltrim($real, '/'));
    }

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
     * Write an ini file for every extension PIE has just installed for the target PHP (its .so lies below
     * $soPrefix, INSTALL_ROOT + that PHP's extension_dir), and drop ini files whose .so has gone.
     *
     * @param callable(string): bool $systemLoads Whether the system PHP (without our conf.d) loads extension $name
     * @return array<string, bool> the project's extensions for the target PHP, name => loaded by the project (false: left
     *                             alone because the system PHP already loads it)
     */
    public static function sync(string $phpExtDir, string $confDir, string $soPrefix, callable $systemLoads): array
    {
        foreach (glob($phpExtDir . '/pie/php*/vendor/composer/installed.json') ?: [] as $file) {
            $packages = json_decode((string) file_get_contents($file), true)['packages'] ?? [];
            foreach ($packages as $package) {
                $so = $package['extra']['pie-installed-binary'] ?? null;
                if (!is_string($so) || !str_starts_with($so, $soPrefix) || !str_starts_with($package['type'] ?? '', 'php-ext') || !is_file($so)) {
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
                $present[basename($ini, '.ini')] = preg_match('/^(?:zend_)?extension=/m', $contents) === 1;
            }
        }

        return $present;
    }

    /**
     * Remove the extension installed from PIE package $package for the PHP with conf.d $confDir: its ini, its .so
     * and, when no other PHP build uses the package either, its pie.json requirement.
     *
     * @return list<string> names of the extensions removed (none: not installed)
     */
    public static function uninstall(string $phpExtDir, string $confDir, string $package): array
    {
        $found = [];
        foreach (glob($confDir . '/*.ini') ?: [] as $ini) {
            $contents = (string) file_get_contents($ini);
            if (!str_starts_with($contents, self::MARKER . $package . "\n")) {
                continue;
            }
            if (preg_match('/^;?(?:zend_)?extension=(.+)$/m', $contents, $m) && str_starts_with($m[1], $phpExtDir . '/root/')) {
                @unlink($m[1]);
            }
            unlink($ini);
            $found[] = basename($ini, '.ini');
        }

        foreach (glob($phpExtDir . '/conf.d/*/*.ini') ?: [] as $other) {
            if (str_starts_with((string) file_get_contents($other), self::MARKER . $package . "\n")) {
                return $found;
            }
        }
        foreach (glob($phpExtDir . '/pie/php*/pie.json') ?: [] as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (isset($json['require'][$package])) {
                unset($json['require'][$package]);
                $json['require'] = (object) $json['require'];  // PIE's schema wants {} not []
                file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
        }

        return $found;
    }
}
