# Suite read-only access

The Suite `viewer` role maps to the existing local `commenter` account role for database compatibility. The adapter also sets an authoritative `read_only` flag from the freshly verified Suite identity, overriding cached client or session values.

The HTTP gate rejects every protected POST and DELETE for viewers, including comments, tasks, imports, calendars, baselines and templates. Assigned-project GET requests and exports remain available. Foreign-project access remains denied. CSRF-protected logout remains available even if the Suite is unavailable.

The account label shows Read-only and whoami returns the current flag. Browser and mobile interface review remains necessary; the server gate protects against mutations even where an old editing button is still visible.

Gateway, session and HTTP tests cover role mapping, cached write flags, current role changes, assigned reads and exports, foreign projects and mutations. Deploy this adapter with Suite viewer support before enabling client access. No hosted instance or readiness flag is enabled by this change.
