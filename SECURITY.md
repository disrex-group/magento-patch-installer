# Security

## Reporting

Report a vulnerability in this plugin privately, through [GitHub's advisory form](https://github.com/disrex-group/magento-patch-installer/security/advisories/new) or by email to support@disrex.nl. Please do not open a public issue for anything that would let someone attack a store running it. We answer within seven working days.

## In scope

Anything that makes the plugin:

- report a store as patched while a patch is not on disk, or exit 0 over a missing security patch;
- apply, revert or skip a patch the root `composer.json` did not ask for, in particular through a package that is not in `extra.magento-patches.trust`;
- write outside the project directory, or run anything from a patch file;
- leave a file half-written.

## Out of scope

What a patch itself contains belongs to whoever wrote it; for Adobe's patches that is Adobe, through [its disclosure programme](https://helpx.adobe.com/security/alertus.html). A trusted package can patch the project by design.

## Supported versions

The latest release. Fixes go forward only.
