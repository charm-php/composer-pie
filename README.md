# charm/composer-pie

[PIE](https://github.com/php/pie) (the PHP Foundation's extension installer), per project. Extensions are installed into
`vendor/php-ext/` instead of the system PHP, and `vendor/bin/php` runs PHP with them loaded.

This is an unofficial wrapper around PIE, not part of it. It downloads the real `pie.phar` (pinned to one release, sha256
verified, cached in `${XDG_CACHE_HOME:-~/.cache}/composer-pie/`) and runs it.

```bash
composer require charm/composer-pie
vendor/bin/pie install xdebug/xdebug      # any PIE command; installs into the project, never uses sudo
vendor/bin/php -r 'var_dump(extension_loaded("xdebug"));'
```

`vendor/bin/pie install` with no arguments is PIE's project mode (installs the `ext-*` requirements of your
`composer.json`; PIE asks you to pick a package for each unless you pass `--select=ext=vendor/package`).
Selections given with `--select` are remembered in `composer.json` under `extra.composer-pie.select`
and passed on later runs, so project mode needs no arguments afterwards.
`vendor/bin/pie uninstall vendor/package` removes the extension from the project.

## How it works

- `vendor/bin/pie` runs PIE with `INSTALL_ROOT=vendor/php-ext/root`, `--skip-enable-extension`, and PIE's own state
  (`pie.json`, `pie.lock`, sources) in `vendor/php-ext/pie/`, so nothing is recorded against the system PHP. Then it
  writes `vendor/php-ext/conf.d/<ext>.ini` with `extension=<path to .so>` (`zend_extension=` for Xdebug and the like).
- `vendor/bin/php` runs the real PHP (`$COMPOSER_PIE_PHP`, else the first `php` on `PATH` that is not this wrapper)
  with `PHP_INI_SCAN_DIR=":<project>/vendor/php-ext/conf.d"`. The leading `:` keeps the system's scan directory, and
  child processes inherit the variable. `composer exec -- php ...` and `php` lines in composer `scripts` find
  `vendor/bin/php` first on `PATH`.
- If `composer.json` or `composer.lock` is newer than the last `vendor/bin/pie install`, `vendor/bin/php` prints one line to
  stderr suggesting to run it again, and carries on. Projects that never ran `vendor/bin/pie` are not nagged.
- An extension the system PHP already loads is installed but left disabled in the project (no "already loaded" warnings).

## Building from source

`vendor/bin/pie install` first lets PIE use only pre-built binaries published by the extension. If there is none
for your platform, composer-pie checks for a build environment: a C compiler, `make`, `autoconf`, `m4`, and
`phpize` + `php-config` for exactly the PHP you target (same version, extension_dir), and then lets PIE build from
source. PIE's own offer to install build tools with the system package manager (sudo) is switched off.

If something is missing and you are on Linux (glibc, x86_64 or aarch64), composer-pie can provide it in
`${XDG_CACHE_HOME:-~/.cache}/composer-pie/toolchain/`, shared by all projects, and nothing is installed system-wide:

- a C toolchain from conda-forge (gcc with a glibc 2.28 sysroot, make, autoconf, m4, perl, zlib; about 170 MB
  download, 800 MB on disk) installed with a pinned micromamba and pinned lock files (`toolchain/*.lock`, sha256
  verified);
- if the PHP has no `php-config`/`phpize` (no `-dev` package), these and the headers are built from the matching
  php-src release (sha256 verified against php.net), about 10 MB.

It asks `[y/N]` when interactive. Non-interactively, pass `--install-toolchain` or set `COMPOSER_PIE_TOOLCHAIN=1`,
otherwise it explains what is missing and fails. On other platforms (musl, macOS) it tells you what to install
(`apk add build-base autoconf php-dev`, `xcode-select --install`, ...). Extensions that need system libraries
(other than zlib) still need those libraries' headers installed; the toolchain's compiler does not search `/usr/include`.

## Composer and `ext-*` requirements

Composer checks `ext-*` requirements against the PHP that runs Composer, which does not load the project's
extensions, so `composer require some/package` (needing `ext-ds`) would fail. After `pie install`, composer-pie records
the installed version in `composer.json` under `config.platform` (`"ext-ds": "2.0.0"`, via `composer config`), and
`pie uninstall` removes it. The rule is deliberately simple: every extension the project loads gets an entry, except
those the system PHP already loads itself (Composer sees those anyway). If several PHP binaries are used in one
project, the last install wins. `config.platform` is part of `composer.lock`'s hash, so run `composer update --lock`
once after the first install if you have a lock file.

## Limits

- CLI and long-running servers started through `vendor/bin/php` only. Not php-fpm or mod_php: they do not go through the
  wrapper.
- `pie show` and `pie upgrade` do not understand project installs (PIE looks for the `.so` in the system extension
  directory). Re-run `pie install <package>` to upgrade.
- Linux and macOS only for now; Windows not yet.

## Tests

```bash
composer install
vendor/bin/phpunit                      # unit tests
vendor/bin/phpunit --group integration  # real installs: needs network and a compiler, takes about a minute
```
