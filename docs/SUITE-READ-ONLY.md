# Suite read-only access

The Suite `viewer` role maps to the existing local `commenter` account role for database compatibility. The adapter also sets an authoritative `read_only` flag from the freshly verified Suite identity, overriding cached client or session values.

The HTTP gate rejects every protected POST and DELETE for viewers, including comments, tasks, imports, calendars, baselines and templates. Assigned-project GET requests and exports remain available. Foreign-project access remains denied. CSRF-protected logout remains available even if the Suite is unavailable.

The account label shows Read-only and whoami returns the current flag. Programme and Lookahead hide editing navigation, disable activity fields and dragging, and explain client access. Analytics hides editing navigation and baseline management while retaining reports. Editing links start hidden until identity is verified, including mobile menus. DOM tests cover these behaviours with a stale planner role plus the authoritative read-only flag. Real browser layout review remains necessary; the server gate remains the authorization boundary.

Gateway, session and HTTP tests cover role mapping, cached write flags, current role changes, assigned reads and exports, foreign projects and mutations. Deploy this adapter with Suite viewer support before enabling client access. No hosted instance or readiness flag is enabled by this change.
