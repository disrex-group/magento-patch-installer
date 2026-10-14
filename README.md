# disrex/magento-patch-installer

A Composer plugin that applies patches to Magento 2 and Adobe Commerce, checks on every Composer run that they are still on disk, and fails the run when one is missing. It was built for Adobe's monthly isolated security patches, which a store only receives by applying the files itself.

Full documentation: **https://disrex-group.github.io/magento-patch-installer/**

## Install

```bash
composer config --no-plugins allow-plugins.disrex/magento-patch-installer true
composer config --json extra.magento-patches.trust '["disrex/*"]'
composer require disrex/magento-security-patches
```

Without the `allow-plugins` line a CI run skips the plugin without a word and exits 0 on an unpatched store. Without `trust` the run fails and names the package.

## Use

```bash
composer magento-patches:status    # the store and every patch meant for it
composer magento-patches:verify    # exit 0 only when everything is in place; writes nothing
composer magento-patches:apply     # apply what is missing; --dry-run to only report
composer magento-patches:list      # what the trusted packages declare
composer magento-patches:lock      # write magento-patches.lock; --check for CI
```

Exit codes: 0 all in place, 1 missing, 2 conflict, 3 configuration error. Run `verify` as its own pipeline step after `composer install`.

## What it guarantees

- Every verdict comes from asking `git apply` about the file on disk, so a reinstalled package that lost its patch is found and patched again.
- A patch Adobe re-issues with other content replaces the old one on stores that already have it.
- A patch goes on whole or not at all, and a file that matches neither side is never overwritten.
- Root files that Magento copies from `magento/magento2-base` are patched in both places.

## Requirements

Magento 2.4.4 or later or Mage-OS 1.0 or later, PHP 7.4 to 8.5, Composer 2.2 or later, and `git` on the PATH. CI runs the end-to-end check on Magento 2.4.6-p15 to 2.4.9 and Mage-OS 1.3.1, 2.3.0 and 3.6.0.

## Development

```bash
composer install
composer lint && composer analyse && composer test
tests/e2e-local.sh ~/path/to/a/magento/project   # against a copy of a real store
```

Releases use [git-cliff](https://git-cliff.org) with conventional commits:

```bash
git cliff --bump -o CHANGELOG.md
git commit -am "chore(release): X.Y.Z" && git tag X.Y.Z && git push origin master X.Y.Z
```

The tag triggers the GitHub release, and Packagist picks it up through its webhook.

## License

MIT, see [LICENSE](LICENSE). Security issues: see [SECURITY.md](SECURITY.md).
