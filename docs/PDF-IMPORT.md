# Printed programme PDF import

The import page supports text-based Asta programme tables with Line, Name, Start, Finish and Duration columns. PDF.js 5.6.205 (Apache-2.0, bundled locally with its worker and licence) reads the PDF in the user's browser. No PDF is sent to an external service and no server shell/PDF utilities are required. The extracted task CSV goes through the existing authenticated preview and commit endpoints, including fresh Suite role, project and CSRF checks.

Wrapped names are joined; dd/mm/yyyy dates are validated and converted to ISO; printed week durations use five working days. Indentation supplies section paths and summary headings are excluded. Missing durations are accepted only for same-day milestones, represented as one-day same-date activities by the current model. Contractors and dependency arrows are not inferred. Auto-FS is turned off for PDF imports and users must acknowledge the preview before saving. Import appends activities; use an empty staging project for the first test.

Limit: 20 MB, 20 pages, 2000 numbered rows per page. Scanned/password-protected/rotated PDFs, unfamiliar headers, duplicate line numbers, incomplete rows and invalid dates stop the preview. Browser support requires modern JavaScript module/worker support. PDF extraction is not an OCR service and must be reviewed against the source.

The preview sends a verified fresh X-CSRF token; client-side debug display never requests the gateway's blocked server debug mode. Failed previews clear earlier results, and expired/read-only users cannot send an upload. The server authorization remains authoritative.

Validation: text-position regression fixtures and import DOM request tests. Owner sample L2742 revision A was privately tested: 84 numbered rows, 13 summaries, 71 activities, seven same-day milestones. The private PDF is not committed. Hosted PDF import, save/refresh/export, mobile and closure tests remain required before readiness.
