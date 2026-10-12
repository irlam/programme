# Multiple programmes in one project

The workspace now has separate Project and Programme selectors. Existing activities, including historical imports, appear under **Original programme** without data migration. Each new named programme uses an existing import-catalogue row, and its tasks use the existing source_import_id foreign key. Project identity and Suite company/project bindings remain unchanged.

On Import programme, choose **New separate programme**, enter a name (prefilled from the filename), preview the supported spreadsheet/CSV and save. The success link opens that exact programme. Alternatively select **Add activities to an existing programme** to append deliberately; no replacement or deletion is performed.

Selection is preserved in the URL on refresh and passed to timelines, registers, lookaheads, analytics, baselines and exports. Editing and dependency recalculation stay within the programme. Dependencies between programmes or projects are rejected. Native admin/planner roles can import/edit; Viewers retain read-only access. Names must be unique within a project when imported sequentially; simultaneous creations are not serialized by a unique programme-name constraint.

No database migration or live data rewrite is required for installations with the existing imports table/schema. Current source is for the standalone main deployment; isolated Suite deployment packages must incorporate the reviewed feature before using it there. Existing source-reporting project totals continue to describe their project, rather than a user's browser-selected programme.

Validation covers two named imports, original/historical preservation, separate reads and exports, edit/scheduling/baseline isolation, cross-project and dependency denial, invalid-import rollback, role/CSRF checks, explicit append and DOM switching/deep links/refresh. A dedicated MySQL CI fixture exercises the native JSON catalogue and foreign keys. DOM simulation is not browser-layout or live-hosting acceptance.
