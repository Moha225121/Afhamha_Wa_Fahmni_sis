# Final pre-deployment audit

> **Follow-up on 18 October 2026:** The user authorized the administration, parent-manifest test, and shared 404 corrections, followed by a push to `main` after successful checks. All four previously failing tests now pass. Integration with the eight newer upstream commits passed **346/346 Laravel tests (2,706 assertions)** and **8/8 JavaScript tests**. The build, Composer validation, Composer/npm security audits, scoped PHP formatting, syntax checks (247 root PHP files, 13 changed nested PHP files, 14 JavaScript files, and 151 Blade views), and isolated migration rehearsal all passed. The merged student interfaces were checked at 320, 375, and 1440 pixels without horizontal page overflow; PDF navigation and mocked tutor history worked. Upstream PWA/notification changes and the existing attendance date correction were preserved. This clears the source-integration gate for the authorized Git push. Production deployment, physical-device testing, and live-provider checks remain separate pending work. The seven-section report below records the **17 October** assessment and its evidence.

Project: Afhamha Wa Fahmni. Date: 17 October 2026, Africa/Tripoli.
Branch: `feature/almotasm-qa-fixes`. Assigned scope: `HEXA-AF-STU-01`.

**Recommendation: NOT READY.** Dependency advisories have been resolved, but the actual application still has four failing tests, including a reproducible administration page error. Production configuration, the production database engine, physical phones, and the live AI provider require verification.

No deployment, push, merge, production connection, live migration, production key change, or destructive database operation was performed. Existing changes were preserved. Evidence and disposable fixtures are in `tmp/final-audit-20261017/`, which is ignored by Git and must stay outside deployment artifacts. The starting reference was `tmp/pre-upload-20261017/summary.json`.

## 1. Issues fixed and files changed

This audit changed two dependency lockfiles:

| File | Change |
| --- | --- |
| `composer.lock` | Laravel framework `13.26.1` → `13.30.0`; Symfony HTTP Foundation `7.4.18` → `7.4.21`. No other package entry changed relative to the starting audit snapshot. |
| `package-lock.json` | concurrently `10.0.5` → `10.0.6`; shell-quote `1.9.0` → `1.12.0`; source-map-js `1.2.1` → `1.2.2`. |

The existing manifest constraints already allow these versions. `composer.json` and `package.json` were preserved. Updates were targeted and inspected before installation; no major upgrade, forced audit fix, or advisory suppression was used. This report is the only new repository documentation file from this audit.

The existing student changes remain intact and passed regression checks:

- `app/Support/TutorMessageRenderer.php`, `resources/views/student/tutor/show.blade.php`, `resources/views/student/layout.blade.php`, and `public/css/student.css`: sanitized Markdown, Arabic RTL layout, readable student messages, contained tables/code, and saved history.
- `public/js/library-reader.js`, `resources/views/library/reader.blade.php`, `public/vendor/pdfjs/VERSION.txt`, and `public/vendor/pdfjs/legacy/`: matching PDF.js display/worker compatibility wrappers and polyfills. The student reader selects these assets; other roles retain their existing PDF module selection.
- `app/Http/Controllers/StudentPortal/EducationController.php` and `app/Http/Controllers/LibraryCatalogController.php`: class restrictions, consistent related-resource visibility, path containment, and useful missing-file responses. New student fallback views and regression tests remain present.
- Existing teacher, assignment, academic, migration, and other developers' changes were preserved byte for byte. No administration, parent, shared-error, or teacher source was fixed by this audit.

The starting diff was saved before changes. SHA-256 comparisons of initially modified/untracked files found only the intended `composer.lock` change. The additional files changed or added are `package-lock.json` and this report. `git diff --check` passed.

## 2. Tests executed

PHP runtime: 8.4.21. Node: 26.1.0. npm: 11.13.0. Application tests ran with `APP_ENV=testing`, SQLite `:memory:`, and an empty AI credential. The test base refuses the normal application database.

| Check | Result |
| --- | --- |
| Full Laravel suite after dependency updates | **276 tests: 272 passed, 4 failed; 2,053 assertions** |
| Focused education, library, reader, tutor, gateway, and tutor metadata migration tests | **64 passed, 0 failed; 608 assertions** |
| Four reported failing tests, each run separately before updates | **4 executions: 0 passed, 4 failed** |
| Same four tests, each run separately after updates | **4 executions: 0 passed, 4 failed** |
| Proposed handoff corrections in copied tests and temporary view overlays only | **4 passed, 0 failed; 189 assertions**; these do not change the real suite's result |
| JavaScript PDF compatibility tests | **2 passed, 0 failed** |
| Production asset build after `npm ci --ignore-scripts` | Passed |
| Composer strict validation and non-development platform requirements | Passed |
| Composer locked security audit | 0 advisories; 0 abandoned packages |
| npm installed-tree audit and production lockfile audit | 0 vulnerabilities in each |
| Exact vendored PDF.js/core-js versions queried against npm advisory registry | 0 advisory groups |
| PHP source syntax | **226 checked, 0 failures** |
| JavaScript application syntax | **6 checked, 0 failures** |
| Compiled Blade syntax | **132 checked: 131 passed, 1 failed**, the existing admin settings template |
| Pint on seven relevant student/controller/test PHP files | Passed |
| Pint on the complete dirty tree | Failed: 2 existing teacher controllers and 7 historical `output/` scripts; no formatting changes applied |
| Isolated SQLite migration rehearsal | All pending migrations passed; integrity `ok`, 0 foreign-key violations |
| Isolated backup restore | Integrity `ok`; all original table row counts and data digests matched |
| Source database preservation | All table data digests matched the pre-rehearsal source |
| Production configuration/cache simulation | `optimize` passed with separate cache/view paths; cached config/routes loaded; HTTPS login 200 |

The isolated HTTPS simulation produced a Secure, HttpOnly, SameSite=Lax session cookie. The CSRF token cookie was Secure and intentionally readable by JavaScript. This was a simulated request with a fixture key and cloned database, not a test of the production TLS terminator or domain.

The browser used a separately migrated local database, isolated file/session directories, a temporary student, generated two-page PDFs, and a mocked AI gateway. After the updates it verified:

- Login, dashboard, assigned subject, published lesson, library, PDF reader, and tutor. All six pages were checked at **320, 375, and 1440 pixels**, with no horizontal page overflow.
- Two PDF pages, next/previous/direct page selection, 300% zoom with overflow contained inside the reader, and rendering after simulating missing native `Promise.withResolvers` and `URL.parse`.
- Successful book and worksheet downloads; useful missing attachment/book responses; corrupt PDF error handling; unsupported file download guidance; hidden draft/future/other-class lessons and other-class books.
- The standalone link delivered `application/pdf`. The in-app browser did not provide usable visual confirmation of its native PDF viewer; native standalone viewing remains a real-browser/device check.
- Mocked tutor submission and persistence, reload and history reopening with two messages, heading/table/code rendering, RTL direction, white student text, zero active injected elements/unsafe links, and no JavaScript dialog.

Automated tests additionally cover unauthenticated/unauthorized file access, byte ranges, path traversal, class and grade visibility, missing subject assignments, ownership, rate limits, idempotency, and provider failure behavior. No synthetic records were inserted into the normal application database. Screenshots and browser measurements are in the evidence directory.

Non-blocking tool messages: the build reports optional `fontaine` font optimization and plugin timing notices; Node's PDF parser tests report unavailable optional canvas helpers, while actual browser canvas rendering passed. Optimized Composer autoload generation completed successfully with plugins disabled after the initial plugin-enabled invocation stalled.

## 3. Security advisories resolved and outstanding

There were **four distinct advisories affecting five package entries**: two Composer advisories and two npm advisories, with the shell-quote advisory also propagated to concurrently. Current audits report zero outstanding advisories.

| Package and original version | Identifier and severity | Reachability before update | Patch and compatibility assessment |
| --- | --- | --- | --- |
| `laravel/framework` 13.26.1 | `PKSA-d5tc-s1qs-h781`, `GHSA-jh5r-qr3c-85q8`, `CVE-2026-102279`; **low** | Production dependency. Debug error pages can render attacker input in a tooltip when `APP_DEBUG=true`. Local debug is enabled; production must disable it. | Patched from 13.30.0; installed **13.30.0**. Same major, within existing constraint. Framework behavior can change across minor releases; regression suite ran and retained the same four unrelated failures. [Maintainer advisory](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8). |
| `symfony/http-foundation` 7.4.18 | `PKSA-xmwy-rmx5-m7h4`, `CVE-2026-108082`; **severity unspecified** by the Composer record and linked Symfony notice | Production dependency. `Cache-Control` parsing runs during HTTP request construction before authentication; unauthenticated requests can reach the ReDoS. This was the priority production remediation. | Initial fix 7.4.19; installed **7.4.21**, which also fixes the whitespace-separator regression in 7.4.19–7.4.20. Same 7.4 line, no constraint change. [Symfony notice and follow-up details](https://symfony.com/cve-2026-108082). |
| `shell-quote` 1.9.0 | `GHSA-pqg4-j6r4-53mv`, `CVE-2026-102422`; **critical** | Transitive development dependency of concurrently. Injection requires attacker-controlled tokens after a comment token to be passed to a shell. No application HTTP path or browser bundle use was found; relevant to developer/CI command tooling. | Patched from 1.11.0; installed **1.12.0** through concurrently. New rejection of unsafe token combinations may affect unusual development commands; the normal build and compatibility tests passed. [Maintainer advisory](https://github.com/ljharb/shell-quote/security/advisories/GHSA-pqg4-j6r4-53mv). |
| `concurrently` 10.0.5 | Same shell-quote advisory, **critical propagated classification**, not a separate vulnerability | Direct development dependency. Used by development tooling, not a deployed Laravel request handler. | Installed **10.0.6**, which requires shell-quote 1.12.0. Patch update within existing 10.x range. npm's dry run showed only the three intended package updates. |
| `source-map-js` 1.2.1 | `GHSA-68fv-2mgg-jv7q`, `CVE-2026-93749`; **high** | Transitive development/build dependency. Malicious indexed source maps can block a consuming Node process. No deployed Node service or request route consuming uploaded source maps was found. | Patched from 1.2.2; installed **1.2.2**. Patch-level validation change; production asset build passed. [Upstream release](https://github.com/7rulnik/source-map-js/releases/tag/v1.2.2), [advisory](https://github.com/advisories/GHSA-68fv-2mgg-jv7q). |

Vendored runtime assets are outside the root npm lockfile, so they were checked separately: PDF.js **6.3.289** and core-js-bundle **3.49.0** returned no matching advisories from the npm bulk advisory endpoint. The PDF.js scripting advisory `GHSA-hq66-cqwq-w95j` identifies **6.2.108** as patched; the vendored version is newer. [Mozilla advisory](https://github.com/mozilla/pdf.js/security/advisories/GHSA-hq66-cqwq-w95j). This is version-based checking, not a full security review of vendor code or every possible PDF.

Keep development tooling out of the production runtime and install reviewed lockfiles rather than resolving new versions during deployment. No known dependency advisory currently blocks this release; the functional and environment gates below still do.

## 4. Failed tests owned by other modules

| Actual failing test | Root cause | Responsible module |
| --- | --- | --- |
| `AdminOperationsTest::test_settings_form_selects_a_valid_theme_when_legacy_color_is_not_in_the_palette` | Admin settings Blade compiles into invalid PHP and returns 500. | Administration / school operations settings |
| `DefaultAccountPasswordTest::test_forms_render_without_exposing_the_default_and_audit_does_not_leak_hashes` | Same settings template fails before password privacy assertions can complete. | Administration settings / account administration |
| `DemoPortalSmokeTest::test_demo_accounts_can_open_their_portal_pages_and_representative_records` | Demo smoke reaches `admin.settings.index` and encounters the same parse error. | Administration settings; integration QA verifies afterward |
| `ParentPortalTest::test_parent_pwa_assets_are_available_without_sensitive_page_caching` | Raw Arabic assertion against escaped JSON. Correcting that assertion also exposes a genuine missing school-branded 404 response. | Parent PWA tests and shared error-page/branding owner |

**Administration handoff.** In `resources/views/admin/operations/settings.blade.php`, lines 20–22 use inline `@php(...)`, followed later by block `@php ... @endphp`. Blade's raw-block regex matches from the first inline opening through the later block ending. The intervening `@foreach` is left as literal text, while a subsequent `@endforeach` compiles into PHP. PHP then reports `unexpected token "endforeach", expecting end of file` at compiled line 33. This is a genuine template implementation defect, not an invalid test expectation.

Replace those inline assignments with explicit `@php ... @endphp` blocks, retaining the full palette, old-input behavior, and fallback color. A complete candidate template was tested only in `tmp/final-audit-20261017/handoff/view-overlay/admin/operations/settings.blade.php`; it made all three copied administration tests pass. The real template remains unchanged.

**Parent/shared-page handoff.** The real manifest returns HTTP 200 with `application/manifest+json; charset=UTF-8`. Decoding produces exactly `مدرسة النسيبة - بوابة ولي الأمر`, and the icon URL is correct. The response escapes Arabic as Unicode sequences; a browser decodes it correctly. Replace the raw name assertion with:

```php
->assertJsonPath('name', 'مدرسة النسيبة - بوابة ولي الأمر')
```

Retain content-type, icon, service-worker, offline page, and privacy assertions. An exact `icons.0.src` JSON assertion was also used in the temporary copy, strengthening the existing icon-prefix check.

After this correction, the original final assertion for `/missing-school-page` fails: the response is Laravel's default unbranded 404. The project has `errors/4xx.blade.php`, but Laravel finds its built-in specific `errors::404` before considering the generic `errors::4xx`. A specific application `resources/views/errors/404.blade.php` delegating to the existing `errors.4xx` preserves the intended branding and status. That candidate was tested in the temporary view overlay. With both handoffs applied only to copies/overlays, **all four diagnostic tests passed with 189 assertions**.

No production source or original assertion was edited for either handoff. The actual full suite remains **272/276**, pending the owners' changes and a fresh full run.

## 5. Missing data, credentials, and real-environment checks

| Area | Observed locally | Required production verification |
| --- | --- | --- |
| Environment/debug | `local`, debug enabled, HTTP application URL | `APP_ENV=production`, `APP_DEBUG=false`, canonical HTTPS URL; verify effective cached values without displaying secrets |
| Application key | Present locally; value not printed or changed | Confirm existing production key in protected secret storage and backup; retain it across releases and restores |
| Database | Reachable SQLite; isolated migrations and restore passed | Identify actual production engine/version and rehearse on that engine with production-like data. `.env.example` proposes PostgreSQL, but its deployment was not available |
| Source content | **0 subjects, 0 lessons, 0 books, 0 class-subject assignments** | School staff must supply real content and assignments. Empty states pass; fixtures are not curriculum |
| Sessions/cache/queue | Local file sessions, file cache, synchronous queue | Confirm chosen stores, permissions, shared cache/locks/rate limiting for multiple instances, and supervised workers if asynchronous queues are enabled |
| Cookies/HTTPS | HttpOnly and SameSite=Lax defaults; Secure not explicitly set locally | Configure Secure cookies, TLS/proxy trust and domain; verify through the actual public HTTPS endpoint |
| Storage | Local directories writable; `public/storage` absent | Persistent private/public storage, correct service-user ownership and storage link; serve only public files through that link |
| Scheduled work | Four configured commands listed successfully; none executed | Cron every minute for `schedule:run`; validate locks/timezone and exam finalization, result publication, upload pruning, and finance reminders with owners |
| Logging | Stack/single, debug level locally | Protected logs, appropriate production level, rotation/retention, alerting and disk monitoring |
| AI | No live credential configured | Server-only Gemini key, allowed model, quota/network access and an approved live request; browser must never receive the key |
| Devices | In-app browser responsive checks and compatibility simulation passed | Physical Android/iOS testing and actual standalone PDF viewing; representative real school books, including large/scanned files |

Private library uploads currently store on the `local` disk. Student endpoints recheck visibility and resolve paths inside allowed roots; tests cover unauthorized reads and traversal. Existing public/private records and the private-file migration still need review against the real deployment's storage before adding a public link. Teacher lesson uploads have MIME/size validation; their source was not modified.

Operational upload review remains necessary: the library's configured application size limit defaults to zero, so choose deployment-specific disk, request, chunk and timeout limits. The administration logo flow permits SVG and stores it publicly; a sanitizer was not found in that store path. The administration/security owner should review SVG handling before accepting files from untrusted sources. This audit did not exercise a malicious-logo exploit or change another module.

The Gemini gateway uses a server-side `x-goog-api-key` header, disables upstream redirects, and emits safe provider failures. Its credential variable is not used in browser source. A scoped credential-pattern scan of application/config/browser/view source returned no matching Google/OpenAI key-shaped values; this is not a guarantee about unreviewed local files or external hosting secrets.

The root working tree also contains untracked teacher implementation/migration files and historical `.vs/`, `output/`, and nested-project storage. Have the teacher owner review the intended teacher files; preserve unrelated local files and exclude development caches, reports, databases, backup snapshots, secrets, and nested application copies from the release artifact. Do not upload the entire working folder.

## 6. Deployment steps and rollback instructions

These steps are a handoff for a separately authorized deployment. They were not run on production.

1. Have the administration and parent/shared-page owners apply their handoffs. Review existing uncommitted teacher work and shared schema changes, including approval requirements already recorded in `docs/HEXA-AF-STU-01.md`. Re-run the individual failures and the full suite until the release gate is agreed. Approve a precise release commit/artifact; include required new student views, renderer, vendor wrappers, bundles and licenses.
2. Build a new release directory from reviewed source. Keep the existing release for rollback. Use the checked lockfiles:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   npm ci --ignore-scripts
   npm run build
   ```

   Build Node tooling in CI/build storage; ship generated `public/build` with the release. Install real production secrets before any Artisan command that needs them. Do not use the convenience `composer run setup` deployment shortcut: it includes key generation and automatic migration.
3. On isolated staging with the actual database engine, rehearse all pending migrations using a restored, access-controlled backup and separate storage roots. Verify data counts, foreign keys, constraints, sequences where applicable, file copies/moves, student access, and rollback. Test the live provider and physical-device PDFs with approved real data.
4. Before production changes, pause writes and stop overlapping workers/scheduled mutations through the deployment process. Take a consistent database backup and a coordinated snapshot of both `storage/app/private` and `storage/app/public`; retain the existing key and secret configuration privately. For PostgreSQL, use an access-controlled custom-format `pg_dump` and verify restoration into a **separate empty database**. For SQLite, use its backup API or `VACUUM INTO` under coordinated maintenance; do not copy a live WAL database without its consistent state. Record snapshot time, release ID, file hashes, restore target, retention and recovery owner.
5. With explicit production authorization, review `migrate:status` and the approved migration list, then run `php artisan migrate --force` once. No demo seeding, `migrate:fresh`, reset, key regeneration, or automatic schema rollback. Link persistent storage; create `public/storage` only to the public root. Configure HTTPS, secure sessions, production logging, upload limits and the intended cache/queue stores. Limit write permissions to necessary runtime directories; protect `.env`, database files, private uploads and logs from the web root.
6. Run `php artisan optimize` in the prepared release, then switch traffic atomically and reload PHP/opcache and any supervised queue workers. Configure scheduler cron without launching duplicate schedulers. Verify `/up`, login, admin settings, parent manifest/error branding, student empty/populated pages, private file denial, real PDF rendering/downloads, saved tutor history and an approved live response. Verify built assets and direct PDF.js assets load. The CSS/reader script already have updated version query strings; invalidate any external cache for replaced assets with stable URLs.
7. Watch errors, database health, failed jobs, scheduler execution and storage usage during the release window. Enable normal writes only after smoke checks. Laravel's documented web root, permissions, production debug and optimization requirements are the baseline; actual hosting controls must be verified. [Laravel deployment documentation](https://laravel.com/framework/docs/deployment).

**Rollback.** Keep writes paused and preserve an incident snapshot before changing anything. If the previous release is compatible with the migrated schema and newly written data, switch traffic back to it, rebuild its caches and reload services while keeping the original key and persistent files. Otherwise, restore the verified database backup into a separate recovery database and restore the matching file snapshot into a recovery storage location. Verify integrity, counts, permissions, restricted access and key continuity, then perform a controlled configuration/traffic switch. Preserve and reconcile writes/uploads made after the backup; do not silently overwrite them or run blanket migration downs.

The local rehearsal used SQLite `VACUUM INTO`, migrated a disposable copy, and restored the untouched backup into another disposable database. All original table rows/digests matched, integrity was `ok`, foreign-key violations were zero, and the source database was unchanged. A production PostgreSQL restore, external object-storage restore and live cutover have **not** been rehearsed.

## 7. Final recommendation

**NOT READY.** The student implementation and dependency remediations passed their focused checks, but release-wide acceptance has not passed.

Blocking gates are the administration template error and three associated failures, the parent test/404 branding handoff, and verification of the real production database/configuration/storage plus required device/live-provider acceptance. The four actual suite failures must not be represented as fixed by the diagnostic overlays. Broad Pint failures also require owner review or an explicitly agreed formatting gate.

There are zero outstanding advisories in the audits performed. This removes the known dependency-security gate; it does not establish production readiness. Resolve the owners' handoffs, run the final suite and staging checks again, and obtain explicit authorization before deployment or any production change.
