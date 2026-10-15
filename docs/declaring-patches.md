---
title: Declaring patches
nav_order: 4
---

# Declaring patches

Patches are declared under `extra.magento-patches`, in the root `composer.json` or in a package the root trusts. Every patch is a diff with paths relative to the project root, such as `vendor/magento/framework/Escaper.php` or `nginx.conf.sample`, applied with `git apply -p1`. Adobe's patch files have exactly that form.

## A patch on its own

```json
{
    "extra": {
        "magento-patches": {
            "patches": [
                {
                    "id": "ACME-1",
                    "label": "Cookie limit",
                    "source": "patches/acme-cookie-limit.diff",
                    "base": { "magento/framework": ">=103.0.8" }
                }
            ]
        }
    }
}
```

## A line of patches built on each other

Adobe's monthly patches each build on the month before. Put them in a cumulative line and the order you list them in is the chain:

```json
"lines": {
    "2.4.8-p5": {
        "base": { "magento/magento2-base": "2.4.8-p5" },
        "cumulative": true,
        "patches": [
            { "id": "2026-07-001", "source": "2026-07-001.diff" },
            { "id": "2026-08-001", "source": "2026-08-001.diff" }
        ]
    }
}
```

## Fields

| Field | Required | Meaning |
|---|---|---|
| `id` | yes | Unique within its line |
| `source` | yes | The diff, relative to the file that declares it, inside the declaring package. URLs are refused |
| `base` | for a line | Package => version constraint. The patch only applies when every package matches; a package that is not installed means it does not apply |
| `label` | no | Short name shown next to the id |
| `description` | no | Shown by `list -v` |
| `depends` | no | Ids this patch is built on. Not allowed in a cumulative line, where the list order is the chain |
| `optional` | no | `true` keeps the patch off until the root project enables it. Not allowed in a cumulative line, and a patch that is not optional cannot depend on one that is. Installer 0.1 does not know this field and applies the patch on every store, so a package that declares an optional patch must require `disrex/magento-patch-installer` `^0.2` |

Put `base` on the version that decides whether the patch belongs here, not on every module the patch touches. A file of a module that is replaced or not installed is reported as not covered and does not block the rest.

## Splitting declarations over files

```json
"magento-patches": {
    "include": ["patches/isolated.json", "patches/emergency.json"]
}
```

Each file holds `patches` and `lines` in the same form, and sources resolve against that file's directory. A missing or empty file is an error, never a shorter list, and included files cannot include others.

## Switching an optional patch on

A package can ship patches that change behaviour, such as a higher cookie limit, as `optional`. Those stay off until the root `composer.json` enables them, with a reason:

```json
"magento-patches": {
    "sources": {
        "disrex/magento-security-patches": {
            "enable": { "DISREX-COOKIE-LIMIT": "consent and marketing scripts, DISC-456" }
        }
    }
}
```

`base` still decides whether an enabled patch fits this install; one that does not is listed with the patches for other versions, and the advice says so. An enabled patch that builds on another optional patch needs that one enabled too.

Enabling an id the package does not declare, or a patch that is not optional, is a configuration error: `composer install` fails and `verify` exits 3. Take the line out when a release of the package drops the patch or makes it apply to every store.

Take the line out again and the next `composer install` removes the patch, but only from files the plugin wrote itself. A copy that another tool, such as cweagans, put in place stays, and the report shows it as `left`.

## Switching a patch off

Only the root `composer.json` can switch a patch off, and it has to say why:

```json
"magento-patches": {
    "sources": {
        "disrex/magento-security-patches": {
            "skip": { "2026-10-001@2.4.8-p5": "breaks our PDF customizer, DISC-123" }
        }
    }
}
```

A skip takes the patch off a store that already has it, and with it every patch built on it. Name the line with `@line` when the patch you skip is part of a line and other patches build on it; a bare id would match that id in every line, including lines published later. Skipped patches stay in every report with your reason. A patch cannot be enabled and skipped at once, and skipping an optional patch that is not enabled is an error, since it does nothing.

## Other settings

| Setting | Effect |
|---|---|
| `"allow-unpatched": true` | Report problems but let `composer install` succeed |
| `"dry-run": true` | Report what would change on every run and write nothing |
