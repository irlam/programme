# Programme isolated-instance staging plan

The SuiteGateway client is tested source only; it is not connected to Programme's request/session middleware and has not enabled any instance. No live instance keys or new accounts have been created.

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

Implement `/suite-login.php` around a Secure HttpOnly host-only SameSite=None browser-state cookie, SuiteGateway begin/redeem, and app session regeneration. Retain the returned opaque token only server-side. Gate every protected API, report/export, admin utility, import and file route with fresh SuiteGateway validation; failures and outages must deny access. Add an immutable local user-ID mapping instead of adopting accounts by email. Disable separate local-login/bootstrap/user-creation paths in Suite-managed instances. Assert the deployment's local project/database binding before queries. The tested client maps platform owner to local admin, company/project managers to planner, and workers/contractors to commenter; unsupported roles are rejected.

Several legacy Programme utilities bypass `api/_bootstrap.php`. A protected-route inventory and server-level gate are required; hooking only workspace.php is insufficient. Remove debug/probe/install scripts from instance deployments. Do not expose SQL exports or private configuration. Validate private file guards and static/download rules through actual HTTP checks.

## Verification gate

With both fixtures running, prove foreign project/task/comment/dependency/import/export IDs cannot cross instances; cookies and files cannot cross hosts; browser-state mismatch, replay, expiry, disabled modules, company suspension, membership revocation and global logout deny access. Verify MySQL migrations and concurrent redemption. Define and test an offline reauthentication policy before retaining any company data in browser caches or outboxes. Only then record verification evidence and enable readiness flags in the deployment-owned Suite inventory.

Current tests cover the client protocol with a controlled transport and the existing schedule behavior. They do not prove deployment or end-to-end tenant isolation.
