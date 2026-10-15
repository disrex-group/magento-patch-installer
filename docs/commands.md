---
title: Commands
nav_order: 3
---

# Commands

| Command | Writes | Exit code |
|---|---|---|
| `composer magento-patches:list` | nothing | 3 on a configuration error, else 0 |
| `composer magento-patches:status` | nothing | 3 on a configuration error, else 0 |
| `composer magento-patches:apply` | patched files | see below |
| `composer magento-patches:verify` | nothing | see below |
| `composer magento-patches:lock` | `magento-patches.lock` | 0; `--check` exits 1 when the file is out of date |

`apply` also runs by itself at the end of `composer install`, `composer update` and `composer dump-autoload`.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | Every patch meant for this store is in place |
| 1 | A patch is missing, a skipped patch is still on disk, a patch the plugin wrote is still on disk after the project stopped enabling it, or a patch was blocked by a missing prerequisite |
| 2 | A file matches neither side of a patch; nothing was overwritten |
| 3 | The configuration is broken: an untrusted package declares patches, a file is missing, a constraint does not parse |

A configuration error outranks everything else, because the plugin may then have dropped declarations, and a clean exit would describe a store it never fully checked.

## list

Every patch the trusted packages declare, grouped by package, with the line it belongs to and whether it applies here. It reads no vendor file, so it answers on a fresh clone before `composer install`.

## status

The store, then every patch meant for it. Patches for other versions and optional patches that are not enabled are each folded into one line; `-v` lists them, and the reason each enabled patch was switched on.

## apply

Applies what is missing and takes off what the project skips or no longer enables. `--dry-run` reports what would change and writes nothing.

## verify

Checks, writes nothing, and exits with the codes above. This is the command for a pipeline. It does not even repair a root copy that drifted: a store whose vendor copy is patched and whose served copy is not must fail.

## lock

Writes `magento-patches.lock`, the record of the patches this project takes. See [CI and deploys](ci.md#the-lock-file).

## JSON

`list`, `status`, `apply` and `verify` take `--json`. Only JSON goes to stdout; errors go to stderr, and `exit_code` in the body matches the process exit code.

```json
{
  "exit_code": 1,
  "errors": [],
  "patches": [
    {
      "id": "2026-10-001",
      "label": "2026-10-001 October 2026 isolated [2.4.8-p5]",
      "owner": "disrex/magento-security-patches",
      "status": "applies",
      "targets": [
        { "path": "vendor/magento/framework/Filter/Template.php", "state": "missing" }
      ]
    }
  ]
}
```

A target's `state` is one of `present`, `applied`, `missing`, `conflict`, `not-covered`, `unwanted`, `reverted`, `left` or `absent`. `left` means an optional patch that is not enabled is in place, but the plugin did not write that copy, or the file changed since it did, so it leaves the copy alone. A patch's `status` is `applies`, `other-version`, `skipped`, `not-enabled`, `blocked` or `unreadable`; `optional` and `enabled` (the reason, or null) say whether it is an optional patch and why it is on.
