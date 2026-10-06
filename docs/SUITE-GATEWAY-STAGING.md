# Programme isolated-instance staging plan

The SuiteGateway, SuiteSession and opt-in SuiteHttp adapter are tested staging source. The server prepend is not configured on any live instance and no instance is enabled. Fixture hosting and database provisioning is underway separately. Deployment credentials and private hosting references are not stored in this repository.

The staged SuiteUserMap component uses immutable Suite user IDs and a deployment-provisioned database binding. It never adopts an existing local account by matching email or name. A mapped account has an instance-specific synthetic email and an unknown random password; fresh verified roles update on every mapping. This component must be called only after fresh server-side gateway validation. It does not disable local login or protect any route by itself. Apply `Database/suite-instance-schema.sql` only to dedicated fixture databases after the base schema; provision exactly one binding row separately. MySQL execution and concurrency still require staging verification.

## Proposed staging resources

| Resource | Alpha fixture | Beta fixture |
| --- | --- | --- |
| HTTPS hostname | alpha.programme.defecttracker.uk | beta.programme.defecttracker.uk |
| Application | Separate Programme checkout | Separate Programme checkout |
| MySQL database | Dedicated fixture-only database | Different dedicated fixture-only database |
| Database login | Scoped to Alpha database only | Scoped to Beta database only |
| Upload/import/export storage | Alpha directory only | Beta directory only |
| Suite company/project | Disposable Alpha fixtures | Disposable Beta fixtures |
| Browser session | Host-only cookie | Different host-only cookie |
| Integration secret | Independent 64-hex instance key | Different independent key |

Use generated fixture users and schedules. Do not copy production databases, password hashes, users or files. New persistent credentials/access must be authorised before provisioning. No company instance may be marked ready before the following work is verified.

## Remaining adapter work

SuiteSession now composes gateway redemption, immutable user mapping and fresh validation for each protected call. It stores an opaque token only in the server session and returns local user data without that token. It rejects a foreign instance envelope or a changed Suite user, updates roles from fresh validation, and clears all local session state on rejection or outage. Logout clears local state even if remote revocation fails; the HTTP wrapper must report the failure and require fresh sign-in. SuiteUserMap also requires exactly one local project. The component does not start PHP sessions, configure cookies, regenerate session IDs, enforce HTTP method/CSRF, or gate routes by itself. Those are required before deployment. No tenant readiness flag is enabled.

The staged HTTP adapter now implements `/suite-login.php` through an opt-in server prepend, secure host-only state/session cookies, session-ID rotation, opaque server-side tokens, fresh request validation, route allowlisting, local-login/user-creation refusal, mutation role/CSRF checks and explicit project-selector checks. It refuses local project IDs other than 1 because legacy reports assume that ID. See [SUITE-HTTP-DEPLOYMENT.md](SUITE-HTTP-DEPLOYMENT.md) for its exact deployment requirements and remaining limits. The adapter is not enabled by deploying this branch alone; live instances and static/file protection still require verification. The tested client maps platform owner to local admin, company/project managers to planner, and workers/contractors to commenter; unsupported roles are rejected.

Several legacy Programme utilities bypass `api/_bootstrap.php`. A protected-route inventory and server-level gate are required; hooking only workspace.php is insufficient. Remove debug/probe/install scripts from instance deployments. Do not expose SQL exports or private configuration. Validate private file guards and static/download rules through actual HTTP checks.

## Verification gate

With both fixtures running, prove foreign project/task/comment/dependency/import/export IDs cannot cross instances; cookies and files cannot cross hosts; browser-state mismatch, replay, expiry, disabled modules, company suspension, membership revocation and global logout deny access. Verify MySQL migrations and concurrent redemption. Define and test an offline reauthentication policy before retaining any company data in browser caches or outboxes. Only then record verification evidence and enable readiness flags in the deployment-owned Suite inventory.

Current tests cover the client protocol with a controlled transport, immutable local-user mapping against independent SQLite fixtures, email collision refusal, changed roles, expiry, database binding checks, dangling accounts, transactional rollback, fresh request validation, cached/local-login refusal, foreign session envelopes, revocation/outage session clearing, logout during outage, single-project refusal and the existing schedule behavior. They do not prove MySQL deployment or end-to-end tenant isolation.
