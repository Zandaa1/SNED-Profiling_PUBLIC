# SNED Profiling

Learner profiles, school assignments, education plans, assessment forms, transfers and report workflows.

Sanitized source-code portfolio demonstration. This is an independent export; no production data or account access is provided.

## Local setup

1. Use a local PHP 8.2+ installation with mysqli, PDO MySQL, mbstring and MySQL/MariaDB. Do not expose this legacy demonstration to the internet.
2. Create an empty local database named `portfolio_demo` and a dedicated local database user. Import `schema.sql` into that database; it contains no accounts or record rows.
3. Copy `config.php.example` to `config.php` and fill in your own local database username/password. Keep this file ignored and private.
4. If `composer.json` is present, install its declared dependencies with Composer. Libraries and generated dependencies are not bundled.
5. Run `php -S 127.0.0.1:8000` from this directory. Open the local address. Camera scanning requires a browser that permits camera access on localhost; HTTPS is needed for remote camera/PWA use.
6. Provision your own synthetic local accounts and records matching the schema. There are no supplied logins. Password representation follows the existing application; inspect its login path before provisioning. Never use real passwords or personal records.

## Verification and limitations

This export was reviewed as source and checked with PHP syntax lint. Database import, account provisioning, browser flows, printing, document generation, QR scanning and external services have not been validated end to end. Schema versions may need reconciliation with later queries. CDN libraries require internet access. Original uploads, documents, operational configuration and images were withheld; neutral generated placeholders supplied in this release are synthetic and are not screenshots or institutional records. Placeholder panoramas/maps are illustrative and do not reproduce actual locations. Existing tests may require optional Node/browser tooling, a configured local database and synthetic fixtures; they are not proof of a complete working installation.

Institutional Word/Excel/PDF templates and uploaded documents are excluded. Template-dependent exports require newly authored local templates with the expected fields and must be validated separately. The former bulk sample generator is disabled. Two-factor login is disabled because this source snapshot does not include its verification route. Assessment checklist definitions contain no original curriculum/item rows; author synthetic local items before testing that workflow.

## Rights

No new license grant is made by this export. Original third-party library notices remain applicable. Public visibility does not grant redistribution rights to original institutional content.
