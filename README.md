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
`vendor/bin/pie uninstall vendor/package` removes the extension from the project.

## How it works

- `vendor/bin/pie` runs PIE with `INSTALL_ROOT=vendor/php-ext/root`, `--skip-enable-extension`, and PIE's own state
  (`pie.json`, `pie.lock`, sources) in `vendor/php-ext/pie/`, so nothing is recorded against the system PHP. Then it
  writes `vendor/php-ext/conf.d/<ext>.ini` with `extension=<path to .so>` (`zend_extension=` for Xdebug and the like).
- `vendor/bin/php` runs the real PHP (`$COMPOSER_PIE_PHP`, else the first `php` on `PATH` that is not this wrapper)
  with `PHP_INI_SCAN_DIR=":<project>/vendor/php-ext/conf.d"`. The leading `:` keeps the system's scan directory, and
  child processes inherit the variable. `composer exec -- php ...` and `php` lines in composer `scripts` find
  `vendor/bin/php` first on `PATH`.
- An extension the system PHP already loads is installed but left disabled in the project (no "already loaded" warnings).

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
- No compiler is provided: when a package has no pre-built binary for your platform, PIE builds it and needs the usual
  `phpize`, `gcc`, `make`, `autoconf` (and the PHP development headers).
- `pie show` and `pie upgrade` do not understand project installs (PIE looks for the `.so` in the system extension
  directory). Re-run `pie install <package>` to upgrade.
- Linux and macOS only for now; Windows not yet.

## Tests

```bash
composer install
vendor/bin/phpunit                      # unit tests
vendor/bin/phpunit --group integration  # real installs: needs network and a compiler, takes about a minute
```
