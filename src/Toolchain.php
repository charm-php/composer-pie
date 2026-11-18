<?php

declare(strict_types=1);

namespace Charm\ComposerPie;

/**
 * What PIE needs to build an extension from source for one target PHP: a C compiler, make, autoconf, m4, and
 * phpize + php-config for exactly that PHP. Finds what the system has; on Linux (glibc, x86_64/aarch64) can
 * provide the rest in the cache directory: a conda-forge C toolchain (micromamba, pinned lock files in
 * toolchain/) and phpize/php-config/headers built from the matching php-src release.
 */
final class Toolchain
{
    private const MICROMAMBA_VERSION = '2.10.0-0';
    private const MICROMAMBA_SHA256 = [
        'linux-64' => '701f39c06f8e6a670a0e186c8479b9e5c0502a2f0cda6cef65d83bdd2875e8bd',
        'linux-aarch64' => '3bb56786e21b9b24024f599fe9185b4a59dc3f9f59937a42311ee583adbbb23c',
    ];
    /** Bundled extensions that need no external library; enabled in the php-src configure when the target has them. */
    private const BUNDLED = ['bcmath', 'calendar', 'ctype', 'exif', 'fileinfo', 'filter', 'ftp', 'pcntl', 'posix', 'session', 'shmop', 'sockets', 'sysvmsg', 'sysvsem', 'sysvshm', 'tokenizer'];

    private string $dir;
    /** @var array{version: string, extension_dir: string, zts: bool, debug: bool, extensions: list<string>} */
    private array $info;

    /** @param string $cache composer-pie's cache directory */
    public function __construct(string $cache, private string $php)
    {
        $this->dir = $cache . '/toolchain';
        $json = (string) shell_exec(escapeshellarg($php) . ' -r \'echo json_encode([PHP_VERSION, ini_get("extension_dir"), (bool) PHP_ZTS, (bool) PHP_DEBUG, get_loaded_extensions()]);\' 2>/dev/null');
        [$version, $extDir, $zts, $debug, $extensions] = json_decode($json, true) ?: ['', '', false, false, []];
        $this->info = ['version' => $version, 'extension_dir' => $extDir, 'zts' => $zts, 'debug' => $debug, 'extensions' => array_map('strtolower', $extensions)];
    }

    /** @return list<string> the build tools not found on $path (a PATH string) */
    public static function missingTools(string $path): array
    {
        $has = function (string ...$names) use ($path): bool {
            foreach (explode(':', $path) as $dir) {
                foreach ($names as $name) {
                    if ($dir !== '' && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
                        return true;
                    }
                }
            }

            return false;
        };

        return array_keys(array_filter([
            'a C compiler (cc, gcc or clang)' => !$has('cc', 'gcc', 'clang'),
            'make' => !$has('make', 'gmake'),
            'autoconf' => !$has('autoconf'),
            'm4' => !$has('m4'),
        ]));
    }

    /** @return string|null "linux-64" or "linux-aarch64" when a toolchain can be provided on this machine */
    public static function platform(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux' || str_contains((string) shell_exec('ldd --version 2>&1'), 'musl')) {
            return null;
        }

        return match (php_uname('m')) {
            'x86_64' => 'linux-64',
            'aarch64' => 'linux-aarch64',
            default => null,
        };
    }

    /** @return array{0: string, 1: string}|null [php-config, phpize] for exactly the target PHP, system's or ours */
    public function findPhpConfig(): ?array
    {
        $real = realpath($this->php) ?: $this->php;
        $candidates = [];
        foreach ([$this->php, $real] as $php) {
            $candidates[] = dirname($php) . '/php-config' . substr(basename($php), 3);
        }
        $candidates[] = $this->phpDevDir() . '/bin/php-config';
        foreach (explode(':', (string) getenv('PATH')) as $dir) {
            $candidates[] = $dir . '/php-config';
        }

        foreach ($candidates as $config) {
            $phpize = dirname($config) . '/phpize' . substr(basename($config), strlen('php-config'));
            if (!is_executable($config) || !is_executable($phpize)) {
                continue;
            }
            $out = fn (string $option) => trim((string) shell_exec(escapeshellarg($config) . ' ' . $option . ' 2>/dev/null'));
            if ($out('--version') === $this->info['version'] && $out('--extension-dir') === $this->info['extension_dir'] && is_file($out('--include-dir') . '/main/php.h')) {
                return [$config, $phpize];
            }
        }

        return null;
    }

    /** Install the conda-forge C toolchain into the cache (once). @return string its bin directory */
    public function installToolchain(): string
    {
        $platform = self::platform() ?? throw new \RuntimeException('no toolchain for this platform');
        $lock = dirname(__DIR__) . '/toolchain/' . $platform . '.lock';
        $prefix = $this->dir . '/' . $platform . '-' . substr(sha1_file($lock), 0, 8);
        if (is_file($prefix . '/.complete')) {
            return $prefix . '/bin';
        }

        $mm = $this->dir . '/micromamba-' . self::MICROMAMBA_VERSION . '-' . $platform;
        if (!is_file($mm) || hash_file('sha256', $mm) !== self::MICROMAMBA_SHA256[$platform]) {
            $this->download('https://github.com/mamba-org/micromamba-releases/releases/download/' . self::MICROMAMBA_VERSION . '/micromamba-' . $platform, self::MICROMAMBA_SHA256[$platform], $mm);
            chmod($mm, 0755);
        }

        // A conda prefix cannot be moved after creation, so build in place and mark it complete at the end.
        self::rmrf($prefix);
        $env = getenv();
        $env['MAMBA_ROOT_PREFIX'] = $this->dir . '/mamba-root';
        $env['CONDA_PKGS_DIRS'] = $this->dir . '/pkgs';
        fwrite(STDERR, "composer-pie: installing a C toolchain into $prefix (about 170 MB download)\n");
        $this->run([$mm, 'create', '-y', '-q', '-p', $prefix, '--platform', $platform, '--file', $lock], $env, null, 'micromamba failed');
        self::rmrf($this->dir . '/pkgs');
        self::rmrf($this->dir . '/mamba-root');
        touch($prefix . '/.complete');

        return $prefix . '/bin';
    }

    /**
     * Build phpize, php-config and the headers for the target PHP from its php-src release (PHP itself is not
     * built), into the cache. $toolchainBin: directory with our compiler, if the system has none. @return array{0: string, 1: string} [php-config, phpize]
     */
    public function buildPhpDevelopment(?string $toolchainBin): array
    {
        $version = $this->info['version'];
        $prefix = $this->phpDevDir();
        self::rmrf($prefix);
        fwrite(STDERR, "composer-pie: no phpize/php-config for PHP $version here; building them from php-src into $prefix\n");

        $release = json_decode((string) @file_get_contents('https://www.php.net/releases/index.php?json&version=' . $version), true);
        $sha256 = null;
        foreach ($release['source'] ?? [] as $source) {
            if ($source['filename'] === "php-$version.tar.xz") {
                $sha256 = $source['sha256'];
            }
        }
        $sha256 ?? throw new \RuntimeException("php.net lists no php-$version.tar.xz");
        $tarball = $this->dir . "/downloads/php-$version.tar.xz";
        if (!is_file($tarball) || hash_file('sha256', $tarball) !== $sha256) {
            $this->download("https://www.php.net/distributions/php-$version.tar.xz", $sha256, $tarball);
        }

        $work = $prefix . '.src';
        self::rmrf($work);
        mkdir($work, 0777, true);
        $env = getenv();
        $env['PATH'] = ($toolchainBin ? $toolchainBin . ':' : '') . getenv('PATH');
        $log = $this->dir . "/php-$version-build.log";
        $this->run(['tar', '-xJf', $tarball, '-C', $work, '--strip-components=1'], $env, null, 'could not extract php-src (is xz installed?)');

        $configure = ['./configure', '--prefix=' . $prefix, '--disable-all', '--without-pear'];
        $this->info['zts'] && $configure[] = '--enable-zts';
        $this->info['debug'] && $configure[] = '--enable-debug';
        foreach (array_intersect(self::BUNDLED, $this->info['extensions']) as $ext) {
            $configure[] = '--enable-' . $ext;
        }
        $this->run($configure, $env, $work, "php-src configure failed, see $log", $log);
        $this->run(['make', 'install-headers', 'install-build', 'install-programs'], $env, $work, "php-src make failed, see $log", $log, true);

        // Extensions include headers of bundled extensions that are not enabled here (e.g. ext/session/php_session.h)
        $include = $prefix . '/include/php';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work . '/ext', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'h') {
                $to = $include . '/ext' . substr($file->getPathname(), strlen($work . '/ext'));
                if (!is_file($to)) {
                    @mkdir(dirname($to), 0777, true);
                    copy($file->getPathname(), $to);
                }
            }
        }

        // The target is the installed PHP, not the one that was never built here
        $config = $prefix . '/bin/php-config';
        $script = (string) file_get_contents($config);
        $script = preg_replace('/^extension_dir=.*$/m', 'extension_dir=' . escapeshellarg($this->info['extension_dir']), $script);
        $script = str_replace("# Remove quotes\n", 'php_binary=' . escapeshellarg(realpath($this->php) ?: $this->php) . "\n# Remove quotes\n", $script);
        file_put_contents($config, $script);
        self::rmrf($work);

        return [$config, $prefix . '/bin/phpize'];
    }

    private function phpDevDir(): string
    {
        return $this->dir . '/php/' . $this->info['version'] . '-' . substr(md5(realpath($this->php) ?: $this->php), 0, 8);
    }

    private function download(string $url, string $sha256, string $to): void
    {
        $data = @file_get_contents($url);
        if ($data === false || hash('sha256', $data) !== $sha256) {
            throw new \RuntimeException("could not download $url with the expected sha256");
        }
        @mkdir(dirname($to), 0777, true);
        file_put_contents($to . '.tmp', $data);
        rename($to . '.tmp', $to);
    }

    /** @param list<string> $command */
    private function run(array $command, array $env, ?string $cwd, string $error, ?string $log = null, bool $append = false): void
    {
        $out = $log === null ? STDERR : fopen($log, $append ? 'a' : 'w');
        // `env` finds the command on $env's PATH (proc_open would use ours)
        $process = proc_open(['env', ...$command], [['file', '/dev/null', 'r'], $out, $out], $pipes, $cwd, $env);
        if ($process === false || proc_close($process) !== 0) {
            throw new \RuntimeException($error);
        }
    }

    private static function rmrf(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            exec('rm -rf ' . escapeshellarg($path));
        }
    }
}
