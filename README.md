# charm/composer-pie

Install PHP extensions into your project instead of into the system PHP. No root, no php.ini changes, and each
project gets its own extensions.

```bash
composer require charm/composer-pie
vendor/bin/pie install xdebug/xdebug
vendor/bin/php your-script.php            # runs with xdebug loaded
```

It uses [PIE](https://github.com/php/pie), the PHP Foundation's extension installer, to find, download and build the
extension. composer-pie is an unofficial companion to PIE, not part of it.

## What it is for

composer-pie is for PHP that runs from the command line: long-running servers and workers, scripts, tests and tools.

- **Application servers** such as [Swerve](https://github.com/phasync/swerve), and event-loop libraries such as
  [phasync](https://github.com/phasync/phasync), ReactPHP or AMPHP.
- **Workers and scripts**: queue workers, cron jobs, CLI tools, test suites.

It cannot add extensions to php-fpm or Apache's mod_php: those start PHP on their own, not through your project.
For a web application that needs an extension the system lacks, serve it with Swerve instead. Swerve runs
WordPress, Laravel, Symfony, Drupal and other php-fpm applications unchanged, and its workers load the project's
extensions:

```bash
composer require charm/composer-pie phasync/swerve
vendor/bin/pie install php-ds/ext-ds
composer exec -- swerve -S public --http=0.0.0.0:8080
```

(Verified with WordPress 7.1 on Swerve using an extension that only the project has.)

## Running your code with the project's extensions

Start PHP through the project, in any of these ways:

| Command | |
|---|---|
| `vendor/bin/php script.php` | runs PHP with the project's extensions |
| `composer exec -- php script.php` | the same |
| `composer exec -- phpunit` | any tool installed in `vendor/bin` |
| `"scripts": {"test": "php vendor/bin/phpunit"}` | `php` in Composer scripts |

Programs started from there inherit the extensions: worker processes, `proc_open(PHP_BINARY, ...)`, child scripts.

Composer's launchers in `vendor/bin` start the system PHP, so a tool run directly as `vendor/bin/phpunit` does not
see the project's extensions. Run it with `composer exec -- phpunit` or `vendor/bin/php vendor/bin/phpunit`.

## Installing extensions: your situation

`vendor/bin/pie install <package>` takes any [PIE package](https://packagist.org/explore/?type=php-ext), e.g.
`xdebug/xdebug`, `apcu/apcu`, `phpredis/phpredis`, `php-ds/ext-ds`. It uses a pre-built binary when the extension
publishes one for your platform, and otherwise builds it from source. What you need depends on your system:

### Your system can build PHP extensions

You have a C compiler, `make`, `autoconf` and the PHP development files (`php-dev` on Debian and Ubuntu,
`php-devel` on Fedora and RHEL). Nothing more to do:

```bash
vendor/bin/pie install xdebug/xdebug
```

### The extension publishes pre-built binaries

Then no build tools are needed at all; `vendor/bin/pie install` downloads the binary for your PHP version and
platform.

### Linux without build tools

composer-pie can download a C compiler and the other build tools for you, into your user's cache
(`~/.cache/composer-pie/toolchain`). Nothing is installed system-wide, and every project shares it. It is a one-time
download of about 170 MB.

```bash
vendor/bin/pie install xdebug/xdebug --install-toolchain
```

Run interactively, it asks before downloading. In CI or a Docker build, set `COMPOSER_PIE_TOOLCHAIN=1` instead. This
works on Linux with glibc (Debian, Ubuntu, Fedora, RHEL and most others) on x86_64; arm64 is set up but not yet tested.

### Alpine Linux or macOS without build tools

composer-pie tells you what to install:

```bash
apk add build-base autoconf php83-dev          # Alpine (match your PHP version)
xcode-select --install && brew install autoconf  # macOS
```

### The extension needs a system library

Some extensions use a library from your system: `imagick` needs ImageMagick, `rdkafka` needs librdkafka. Install the
library's development package (e.g. `apt install libmagickwand-dev`), then `vendor/bin/pie install` as usual.

### The extension is already installed system-wide

Nothing to do. composer-pie notices and leaves it to the system PHP.

## Declaring the extensions your project needs

List them in `composer.json` as usual, and install them all with one command:

```json
{
    "require": { "ext-apcu": "*" },
    "require-dev": { "ext-xdebug": "*" }
}
```

```bash
vendor/bin/pie install                      # installs every missing ext-* requirement
vendor/bin/pie install --no-dev             # production: skip require-dev
```

If several packages provide an extension, PIE asks which one to use; pass `--select ext=vendor/package` once and
composer-pie remembers the choice in `composer.json`.

After an install, composer-pie also records the extension in `composer.json` (`config.platform`), so Composer
accepts packages that require it. If you have a `composer.lock`, run `composer update --lock` once after the first
install.

To remove an extension from the project: `vendor/bin/pie uninstall xdebug/xdebug`.

## How it works

- `vendor/bin/pie` runs the real PIE (a pinned, checksum-verified release) with the project as its target: the
  extension goes to `vendor/php-ext/`, and PIE's own records stay there too. PIE is never allowed to edit the system
  `php.ini` or to use sudo.
- composer-pie then writes an ini file for the extension in `vendor/php-ext/conf.d/`, one directory per PHP binary,
  so a project used with PHP 8.4 and 8.5 keeps a build for each.
- `vendor/bin/php` starts your real PHP with that directory added to `PHP_INI_SCAN_DIR`. The system's own ini files
  still load, and programs it starts inherit the setting.
- When `composer.json` or `composer.lock` changed since the last `vendor/bin/pie install`, `vendor/bin/php` prints a
  one-line reminder to run it again.
- The build toolchain comes from [conda-forge](https://conda-forge.org/) (gcc, make, autoconf), installed with
  [micromamba](https://mamba.readthedocs.io/) from pinned, checksum-verified lock files. If your PHP has no
  development files, composer-pie builds them from the matching php-src release.

## Limits

- Command-line PHP only; not php-fpm or Apache (see above).
- Linux and macOS. Windows is not supported yet.
- `vendor/bin/pie show` and `vendor/bin/pie upgrade` do not understand project installs yet; to upgrade, run
  `vendor/bin/pie install <package>` again.

## Development

```bash
composer install
vendor/bin/phpunit                      # unit tests
vendor/bin/phpunit --group integration  # real installs: needs network and a compiler, a few minutes
```

## License

MIT. PIE is BSD-3-Clause, by the PHP Foundation.
