# Changelog

Pushing a tag of the form `vX.Y.Z` publishes a GitHub release using the matching row below as its release notes. Zenodo archives the release when the repository is enabled in the [Zenodo GitHub integration](https://help.zenodo.org/docs/github/enable-repository/).

| Version | Changes |
| --- | --- |
| 1.1.4 | Fixed configuration saves for bare `@NEDCAPTCHA` tags and retained the default math challenge. Made action tags case-sensitive, added immediate editor links, preserved pending edits and structured custom pairs, and improved editor notices, validation, and malformed annotation repair. Added design-rights and draft-mode checks and redacted audit logging. Escaped dynamic output, validated operand ranges, handled invalid colors and blank answers, decoded embedding keys, and made custom helpers optional. Added math fallback for invalid configurations and image-rendering failures and fixed GD precision-loss warnings. Protected eligible public survey links in every arm with browser-bound CAPTCHA proof. Corrected challenge discovery and pagination, preserved first-page answers through CAPTCHA recovery, and restored Save & Return and Stop Actions, including response capture, discard acknowledgements, and file re-upload after session loss. |
| 1.1.3 | Prevented REDCap calculation JavaScript from running against fields removed from the CAPTCHA-only survey page while retaining branching logic for custom challenges. |
| 1.1.2 | Removed "Test" link (development artifact). |
| 1.1.1 | Minor code refactor. |
| 1.1.0 | New feature: Configurable solved CAPTCHA sessions.<br>Minor PHP 8.5 compatibility fixes. |
| 1.0.0 | Initial release. |
