# Suite HTTP adapter — staging only

`app/suite-prepend.php` is an opt-in server-level gate for **dedicated fixture instances**. It is not automatically activated by deploying this branch. Never configure it on the current shared Programme installation. Suite inventory readiness must remain false pending deployment and isolation verification.

## Sanitized staging package

Run `python3 bin/build-suite-package.py /absolute/path/outside-the-checkout/staging.zip`. The builder refuses existing destinations, uses an explicit application/asset allowlist, and includes only tracked dependency/font code. It excludes runtime credentials, SQL, backups, installers, tests, probes and local-user screens. The generated login/help/navigation uses Construction Suite. Each public PHP entrypoint gets a `require_once` gate after its strict declaration, providing fail-closed protection if the server-wide prepend setting is missing. Missing private configuration returns generic 503 before endpoint execution.

The ZIP separates `httpdocs/` from deployment instructions/rules and a SHA-256 manifest in `deployment/`. Publish only the `httpdocs/` contents to a fresh dedicated instance. Never extract `deployment/` into the public document root or overlay an old installation containing residual debug/runtime files. Do not treat this ZIP as an enabled production app: private configuration, schemas/fixtures, deployed Suite APIs, actual web-server rules and live tests are still required. No credentials are in the archive.

The included `.htaccess` denies private/support directories and sensitive extensions for Apache. `deployment/nginx-rules.conf` provides instance-specific static-denial rules that must be merged into the provider's existing server configuration and syntax checked. Source tests do not prove those rules are active on Plesk. Private directories and static/upload paths require live HTTP checks before readiness can be enabled. The package contains no upload storage or download route; direct uploaded files remain unavailable.

## Deployment-owned configuration

Set `PROGRAMME_SUITE_CONFIG_FILE` to a PHP file outside the document root that returns the same binding used by the gateway and session components:

```php
return [
    'instance_id' => 1,        // independently provisioned Suite instance
    'organization_id' => 1,    // fixture organization
    'project_id' => 1,         // fixture Suite project
    'local_project_id' => 1,   // only local project in this database
    'suite_origin' => 'https://suite.defecttracker.uk',
    'origin' => 'https://alpha.programme.defecttracker.uk',
    'key' => 'REPLACE_WITH_AN_INDEPENDENT_64_HEX_INSTANCE_KEY',
];
```

Numbers are illustrative; use actual fixture bindings. Use separate keys/configuration/databases for the two hosts. Keep real secrets out of source control and HTTP responses. The file and PHP session storage must have restricted access. Programme's database configuration remains deployment-owned and independently scoped to this database.

Legacy reports assume local project 1, so this adapter explicitly refuses another local project ID. The mapping component verifies exactly one local project and one matching database binding row on every validated request. Do not infer or initialize these rows from a browser request.

Configure PHP-FPM `auto_prepend_file` to the absolute path of `app/suite-prepend.php` **for every PHP request in this instance**, including nested directories. Verify effectiveness through actual HTTP tests, including routes that do not include `_bootstrap.php`. If using `.user.ini`, verify that no nested file/server setting overrides the prepend. Keep `display_errors` off. The direct web server must populate `HTTPS=on`; forwarded headers are intentionally ignored. Configure HTTPS redirects and valid certificates before testing cookies.

## What the gate covers

The callback uses a 60-second Secure/HttpOnly host-only state cookie with SameSite=None, validates state through SuiteGateway, clears the cookie, rotates the PHP session ID, and stores the opaque tool token only server-side. App sessions use a Secure/HttpOnly host-only cookie with SameSite=Lax. Tokens never appear in whoami JSON or browser cookies. The callback success destination is fixed at `/`.

Every allowed PHP request requires fresh Suite validation and immutable local user mapping. Role changes apply immediately. Revocation, outages and invalid database bindings clear local login state and deny access. Local password login and user creation are unavailable. Logout requires POST plus CSRF, attempts remote token revocation, and clears local session state even if remote revocation fails.

The PHP route allowlist blocks raw SQL, GET recalculation, local-user administration, probes/installers, configuration/library PHP and unknown endpoints. Debug/selftest/probe query switches are denied. Mutations require current mapped permissions and CSRF; commenters may post comments but cannot edit plans. Explicit project selectors in query/form/JSON must match the binding. JSON is limited to 1 MiB/depth 32. The existing bootstrap uses `require_once` for DB.php so it works with the prepend.

## Still required before enabling an instance

- Publish a sanitized application tree. Do not publish SQL, backup/source files, tools, tests, debug pages or deployment configuration.
- Web-server rules must independently deny private directories, SQL/backups and direct uploaded/imported files, including static responses served by nginx. PHP prepend does not protect static files. Configure private storage and authenticated download routes before allowing files.
- Replace the legacy login screen/account-management links for Suite users. Static UI may be public only if it contains no company data; no browser data/offline caches may remain enabled before the reauthentication policy is verified.
- Verify real MySQL migration/connectivity, independent database privileges, concurrent mapping/redemption, all production API/import/export behaviors and foreign IDs/files/cookies across two hosts. HTTP fixtures use a controlled transport and SQLite; they do not prove live isolation.
- Verify global logout, expired/replayed handoffs, suspended organizations/projects, disabled modules and revoked memberships against the deployed Suite gateway.
- Verify host-specific cookies and the actual browser callback over HTTPS, including session-ID rotation and state failure. The automated HTTP test simulates server HTTPS metadata; it does not replace a live TLS/browser test.

The shared hosting system account remains a filesystem isolation limitation. Do not mark tenant readiness based only on passing these source tests.
