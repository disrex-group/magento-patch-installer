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

`magento-patches.lock` in the project root records the patches the project takes: package and version, patch id, line, the sha256 of each patch file and the files it touches. Commit it. A merge request then shows every patch that arrives, changes or goes.

It works like `composer.lock`: `composer update` and `composer magento-patches:lock` write it, `composer install` never does. Install runs on servers, and a rewritten tracked file there breaks the next `git pull`. When the file no longer matches, install and verify warn; `composer magento-patches:lock --check` exits 1.

## Renovate

Each release of a patch package is a new version, so Renovate opens a merge request for it in every project. Two things to set up:

- Renovate runs Composer without plugins by default, so `magento-patches.lock` stays old in its merge requests. On a self-hosted Renovate, set `allowPlugins: true` in the admin config to let the plugin rewrite it, or let the first pipeline run `composer magento-patches:lock` and commit the result.
- Renovate needs the credentials of every private Composer repository the project uses, since it resolves the whole lock file.

## What lives where

| File | Committed | Purpose |
|---|---|---|
| `magento-patches.lock` | yes | Which patches the project takes |
| `var/magento-patches/ledger.json` | no, `var/` is ignored | What the plugin last wrote to each file, per installation |
