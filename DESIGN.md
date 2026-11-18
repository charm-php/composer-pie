# charm/composer-pie: PIE, per project

Status: design, 2026-11-18. Decided by Frode Børli.

## Goal

A project that depends on Composer packages needing PHP extensions works under the CLI even when
the extensions are not installed system-wide. Several packages can need extensions at once, and
they share one loader.

```bash
composer require charm/composer-pie
vendor/bin/pie install apcu/apcu       # PIE's commands, installing into the project
vendor/bin/pie install                 # PIE's project mode: the project's ext-* requirements
vendor/bin/php -r 'var_dump(extension_loaded("apcu"));'
composer exec -- php ...               # finds vendor/bin/php first on PATH
```

## Approach

charm/composer-pie wraps the real PIE (php/pie, BSD-3-Clause), it does not reimplement it. PIE
does package resolution (Packagist `type: php-ext`), its project mode (`ext-*` requirements,
`--select`, package map), pre-packaged binaries (author-published release assets) and the build
fallback. composer-pie only changes where PIE installs and how the project loads what it installed.

PIE's position (asgrim, php/pie #266, #795): "PIE is used to install extensions to a PHP
installation, not to a PHP project". Per-project extensions are discussed in php/pie discussion
#420, blocked on in-process scoping (dlmopen). composer-pie uses the process as the scope instead:
one CLI process runs one project.

## Pieces

| File | Role |
|---|---|
| `bin/pie` (→ `vendor/bin/pie`) | Gets the pinned `pie.phar` (verified), runs it with the project as target, then writes an ini file per installed extension |
| `bin/php` (→ `vendor/bin/php`) | Runs the real PHP with `PHP_INI_SCAN_DIR=":<project>/vendor/php-ext/conf.d"` |

### Installing into the project

PIE already supports this: `src/Installing/UnixInstall.php` installs to
`INSTALL_ROOT` + `extension_dir` for built and pre-packaged binaries alike, and only uses sudo
when the target is not writable. `vendor/bin/pie` runs PIE with:

- `INSTALL_ROOT=<project>/vendor/php-ext/root`
- `--skip-enable-extension` (no system ini is touched)
- PIE's own state (`pie.json`, `pie.lock`, caches) isolated to the project, so a project install
  is never recorded against the system's PHP installation
- the real PHP as the target PHP

Then it writes `<project>/vendor/php-ext/conf.d/<ext>.ini` with `extension=<absolute .so path>`
(`zend_extension=` for `php-ext-zend` packages).

### Loading

`vendor/bin/php` sets `PHP_INI_SCAN_DIR=":<conf.d>"` (the leading `:` keeps the system scan
directory; children inherit it) and execs the real PHP. Verified 2026-11-18 with Composer 2.9:
`composer exec -- php`, `composer exec <bin>` (bin proxies use `#!/usr/bin/env php`) and `php`
lines in `scripts` find `vendor/bin/php` through `PATH`; `@php` honours the `PHP_BINARY`
environment variable. An extension the system PHP already loads is left alone.

## Out of scope for the first version

- A build toolchain when the system has no compiler (later: micromamba + conda-forge gcc,
  php-src headers; prototype findings in the journal of 2026-11-18).
- A Composer plugin (would set `PHP_BINARY` for `@php`, run `pie install` on `composer install`).
- `suggest`ed extensions (PIE's project mode reads only `require`/`require-dev`).
- Windows, php-fpm (fpm cannot be per project this way).
