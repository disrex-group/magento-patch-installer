---
title: Overview
nav_order: 1
---

# Magento Patch Installer

`disrex/magento-patch-installer` is a Composer plugin that applies patches to a Magento 2 or Adobe Commerce store, checks on every Composer run that they are still on disk, and fails the run when one is missing.

It is built for Adobe's security patches. Since July 2026 Adobe ships those as isolated patch files every month instead of as releases, and a store only gets them by applying the files itself.

## What it does differently

- **It asks the files, not a log.** Before and after every run it checks each patched file with `git apply`, so a package reinstall that quietly undoes a patch is found and put back.
- **A re-issued patch replaces the old one.** When Adobe changes a patch after release day, the plugin swaps it on stores that already carry the first version, instead of stopping with a conflict.
- **A patch goes on whole or not at all.** If one file of a patch will not take it, none of its files are written.
- **Root files are patched in both places.** Magento copies about 120 files from `magento/magento2-base` to the project root; the plugin patches the served copy and the package copy.
- **Optional patches stay off until a store asks for them.** A package can ship a patch that changes behaviour as `optional`; it only goes on in a project that enables it with a reason, and comes off again when that line goes.
- **It fails the build.** A missing or conflicting patch makes `composer install` exit non-zero, so a pipeline stops before an unpatched store goes live.

## Where to start

- [Getting started](getting-started.md): install the plugin and a patch package.
- [Commands](commands.md): `list`, `status`, `apply`, `verify` and `lock`, with their exit codes.
- [Declaring patches](declaring-patches.md): ship your own patches, or switch one off.
- [CI and deploys](ci.md): the verify gate, the lock file and Renovate.
- [How it decides](how-it-works.md): what happens to each file, and why.

Disrex publishes Adobe's patches as [`disrex/magento-security-patches`](https://github.com/disrex-group/magento-security-patches).
