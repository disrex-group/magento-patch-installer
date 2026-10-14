---
title: Getting started
nav_order: 2
---

# Getting started

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 or later, or Mage-OS 1.0 or later
- PHP 7.4 to 8.5
- Composer 2.2 or later
- `git` on the PATH, also on the servers that run `composer install`

## Install

Allow the plugin first. Composer does not run a plugin it was not told to trust, and in CI it skips one silently and exits 0, which leaves a store unpatched without a word.

```bash
composer config --no-plugins allow-plugins.disrex/magento-patch-installer true
```

Then tell it which packages may patch your project. Nothing outside your own `composer.json` is read until you name it here:

```bash
composer config --json extra.magento-patches.trust '["disrex/*"]'
```

Then require a patch package. The installer is one of its dependencies, so you never require or update it yourself; the `allow-plugins` line above only gives Composer permission to run it:

```bash
composer require disrex/magento-security-patches
```

The next `composer install` or `composer update` applies every patch meant for your Magento version and prints what it did:

```
Magento security patches: 6 for this store · 81 files applied now · 42 for other versions
  applied   248p5-VULN-39341-CE  StyleSmuggler APSB26-146            9/9
  applied   2026-07-001          July 2026 isolated [2.4.8-p5]       32/32
  ...
  OK   81 of 81 files in place
```

## Check a store

```bash
composer magento-patches:status
```

shows the Magento version, its end-of-life date, where the patches come from, and every patch with the number of its files in place. Add `-v` to see each file and the patches for other versions.

## Moving from another patcher

Patches that another tool already applied count as in place; nothing is applied twice. Remove the other tool's copy of the same patches afterwards, or two tools will each try to apply them on the next install. In particular:

- `disrex/stylesmuggler-adobe-patches` conflicts with the patch package; `composer remove` it in the same step.
- Patch files for Adobe's security patches in `extra.patches` (cweagans) can go.
- A `post-install-cmd` that runs `vendor/bin/magento-patches apply MCLOUD-...` for the monthly releases can go.
