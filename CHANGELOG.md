# Changelog

## 0.1.0 (2026-11-18)

First release.

- `vendor/bin/pie` runs the real PIE (pinned 1.5.5, sha256-verified) so extensions install into the project (`vendor/php-ext/`), never into the system PHP; PIE's state and Composer cache stay out of the user's config.
- `vendor/bin/php` runs the real PHP with the project's extensions loaded (`PHP_INI_SCAN_DIR`), one conf.d per PHP binary; `composer exec` and Composer scripts find it through `PATH`.
- PIE's project mode (`vendor/bin/pie install` with no package) works through the wrapper; `--select` choices are remembered in `composer.json`.
- Installed extensions are recorded in `composer.json` `config.platform`, so Composer accepts packages that require them.
- Pre-built binaries first; then the system's build tools; otherwise, with consent (`--install-toolchain`), a C toolchain (micromamba + pinned conda-forge gcc, Linux glibc x86_64/aarch64) and php-src dev files in the user cache.
- `vendor/bin/pie uninstall` removes a project extension without touching the system.

Limits: CLI and long-running servers only (not php-fpm), Linux and macOS (macOS without the toolchain), not Windows. `pie show` and `pie upgrade` do not understand project installs.
