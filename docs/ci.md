---
title: CI and deploys
nav_order: 5
---

# CI and deploys

## Verify as its own step

Run `composer magento-patches:verify` after the install, and let a non-zero exit stop the pipeline. Two ordinary setups leave the plugin out without a word: `composer install --no-plugins`, and a missing `allow-plugins` entry. Both exit 0 on an unpatched store, and only a separate step notices.

GitLab CI:

```yaml
build:
  script:
    - composer install --no-interaction --no-dev
    - composer magento-patches:verify --no-interaction
```

GitHub Actions:

```yaml
- run: composer install --no-interaction --no-dev
- run: composer magento-patches:verify --no-interaction
```

Run it in the build job, before anything reaches a server: a failing patch then stops the pipeline instead of leaving a store in maintenance mode.

## The lock file

`magento-patches.lock` in the project root records the patches the project takes. A `packages` block at the top names each patch package and its version once. Below it, every patch has its package, id, line, the sha256 of its file and the files it touches; an optional patch is listed only while it is enabled, with the reason. Commit it. A merge request then shows every patch that arrives, changes or goes, and a new release of a patch package changes its version and the `content-hash`, plus whatever patches it brings.

To upgrade from 0.1, update the patch package together with its dependencies, so the installer comes along: `composer update disrex/magento-security-patches -w`. Without `-w`, Composer keeps the installer at the version in `composer.lock`, and once a patch package needs 0.2 it keeps the older patch package too, without a warning. A lock written by 0.1 reports as out of date after the upgrade to 0.2. Run `composer magento-patches:lock` once and commit the result.

It works like `composer.lock`: `composer update` and `composer magento-patches:lock` write it, `composer install` never does. Install runs on servers, and a rewritten tracked file there breaks the next `git pull`. When the file no longer matches, install and verify warn; `composer magento-patches:lock --check` exits 1.

## Renovate

Each release of a patch package is a new version, so Renovate opens a merge request for it in every project. Two things to set up:

- Renovate runs Composer with `--no-plugins`, so `magento-patches.lock` stays old in its merge requests. Rewrite it with a post-upgrade task in a package rule, and allow that command in the Renovate job with `RENOVATE_ALLOWED_COMMANDS='["^composer magento-patches:lock$"]'`:

  ```json
  {
      "packageRules": [
          {
              "matchPackageNames": ["disrex/magento-security-patches", "disrex/magento-patch-installer"],
              "groupName": "Magento security patches",
              "postUpgradeTasks": {
                  "commands": ["composer magento-patches:lock"],
                  "fileFilters": ["magento-patches.lock"],
                  "executionMode": "update"
              }
          }
      ]
  }
  ```
- Renovate needs the credentials of every private Composer repository the project uses, since it resolves the whole lock file.

## What lives where

| File | Committed | Purpose |
|---|---|---|
| `magento-patches.lock` | yes | Which patches the project takes |
| `var/magento-patches/ledger.json` | no, `var/` is ignored | What the plugin last wrote to each file, per installation |
