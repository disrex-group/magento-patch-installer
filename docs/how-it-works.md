---
title: How it decides
nav_order: 6
---

# How it decides

## Each file, each run

For every file of every patch that applies to the store, the plugin asks `git apply` two questions about the file as it is on disk:

| Reverse check | Forward check | State |
|---|---|---|
| passes | | `present`: the change is there |
| fails | passes | `missing`: apply writes it |
| fails | fails, file absent | `not-covered`: the module is replaced or not installed |
| fails | fails, file present | `conflict`: never overwritten |

The reverse check comes first and wins: a hunk with common context can match at a second place in a file that is already patched, and applying again would duplicate the change.

After every write the plugin checks that the file's hash moved. `git apply` can exit 0 having written nothing, and a security fix is only in place when the bytes are.

The plugin runs `git apply` with `core.autocrlf=false` and `--whitespace=nowarn`, so a developer's git config cannot change the verdict, and outside any repository above the project, so a Magento that lives in a subdirectory of a monorepo is patched like any other.

## Chains

A later monthly patch can rewrite lines an earlier one added. The earlier patch then fails its reverse check while it is in fact applied. For patches linked by a cumulative line or `depends`, the plugin asks again on a scratch copy of the file with the later links taken off, and a report puts the earlier links on a scratch copy before it asks whether a later one applies.

When a link of a chain cannot go on, every patch built on it is blocked by name instead of failing three patches later.

## One patch, one unit

All files of a patch are checked before anything is written. If one would conflict, none are written. If a write fails halfway, the files already written are rolled back.

## Root files

`magento/magento2-base` copies files such as `nginx.conf.sample` and `pub/errors/processor.php` to the project root through its `extra.map`. The root copy is the one that is served; the package copy is the one the next deploy copies back. The plugin applies each patch to both. A copy that is a symlink to the other, as the symlink deploy strategy leaves it, is written once.

Entries in `extra.map` that point outside the project, or into another package's directory, are ignored: the map is read from every installed package, trusted or not.

## Re-issued patches

Adobe sometimes changes a patch after release day and keeps its id. On a store that already has the first version, the new one matches neither side of the file, which would be a conflict on every server where `composer install` runs over an existing `vendor/`.

The plugin keeps a ledger in `var/magento-patches/ledger.json`: for every file it wrote, the patches on it in order and the file's hash afterwards. When the hash still matches and the patches meant for the file have changed, it takes the recorded patches off a scratch copy, puts the current ones on, and writes the result only if every step succeeded. A file someone changed by hand no longer matches its hash and stays a conflict.

## Skipped patches

A patch the project skips is taken off in reverse order, before anything is applied, so nothing is reverted while a later patch still sits on it.

## Optional patches

An optional patch the project has not enabled is never written. When the project switches one off again, the plugin takes it off the files where the ledger shows it wrote that patch and the file still has the recorded hash, the same way it replaces a re-issued patch. It leaves any other copy alone and reports it as `left`, so it does not fight a second patch tool during a migration. If the plugin wrote a copy but cannot take it off cleanly, for instance because another patch on the same file does not go on in that run, the report shows that copy as a `conflict` and the run exits 2. Nothing is overwritten, and the ledger keeps the copy, so a later run takes it off once the file allows it.

## Advice

`status`, `apply`, `verify` and the install output warn when:

- the Magento version is within six months of its end of life, or past it. The dates ship in `resources/magento-eol.json`; the plugin never goes online while patching.
- the store is one patch release behind the line the patches are built for. Adobe's isolated patches only exist for the newest `-p` of a line.
- an enabled optional patch does not fit this install.
- `magento-patches.lock` does not match.

Advice never changes the exit code.
