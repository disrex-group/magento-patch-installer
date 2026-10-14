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

Put `base` on the version that decides whether the patch belongs here, not on every module the patch touches. A file of a module that is replaced or not installed is reported as not covered and does not block the rest.

## Splitting declarations over files

```json
"magento-patches": {
    "include": ["patches/isolated.json", "patches/emergency.json"]
}
```

Each file holds `patches` and `lines` in the same form, and sources resolve against that file's directory. A missing or empty file is an error, never a shorter list, and included files cannot include others.

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

A skip takes the patch off a store that already has it, and with it every patch built on it. Name the line with `@line` when other patches build on the one you skip; a bare id would match that id in every line, including lines published later. Skipped patches stay in every report with your reason.

## Other settings

| Setting | Effect |
|---|---|
| `"allow-unpatched": true` | Report problems but let `composer install` succeed |
| `"dry-run": true` | Report what would change on every run and write nothing |
