# DefectTracker Programme

This repository contains the **DefectTracker Programme** web app – a PHP-based tool for managing and viewing construction programmes / lookaheads and related project data.

It’s designed to run on a standard LAMP-style shared hosting setup (PHP 8.x, web server, database), with no heavy build tools.

---

## Folder structure

### `app/`
Core application code.

- Request handling / routing glue.
- Business logic for programmes, projects, dates, and related entities.
- Helpers shared across the public pages, admin area, and API.

Think of this as the “brains” of the application.

---

### `admin/`
Admin interface for power users.

- Screens for managing data (programmes, phases, milestones, users, etc.).
- Admin-only actions like create/update/delete, imports, and bulk edits.
- Typically accessed after login with elevated permissions.

If you’re looking to add new admin pages or tools, start here.

---

### `api/`
JSON/API endpoints.

- Endpoints used by front-end JavaScript and any external integrations.
- Returns JSON for things like programme data, filters, and stats.
- Good place to expose new data for dashboards or external tools.

When you add new API routes, document them here or in a separate API section.

---

### `assets/js/`
Front-end JavaScript.

- Behaviour for UI components, forms, filters, and AJAX calls into `api/`.
- Page-specific scripts for views like programme timelines, analytics, etc.

Any browser-side behaviour (beyond plain HTML) should live here.

---

### `libs/`
Shared libraries and low-level utilities.

- Custom helper classes and functions (database layer, auth helpers, etc.).
- Any third-party code that isn’t managed via Composer.

If something is used by multiple parts of the app (admin, API, public), it probably belongs in `libs/`.

---

### `scripts/migrations/`
Database migrations and one-off scripts.

- SQL or PHP migration scripts to create/alter tables and seed data.
- Intended to be run manually or from the command line during deployment.

When changing the database schema, add a migration here instead of editing tables by hand.

---

### `storage/uploads/`
User-generated and exported files.

- File uploads (e.g. attachments).
- Generated exports (CSVs, PDFs, etc.), if the app produces any.

This directory must be writable by the web server in production.

---

### `templates/`
View templates.

- HTML/PHP templates used to render pages.
- Layouts, partials, and page-level templates for both public and admin views.

If you’re changing what a page looks like (HTML structure), this is normally the place to start.

---

### `tmp/`
Temporary runtime storage.

- Cache files, temp exports, and other short-lived artefacts.
- Safe to clear if you need to “reset” temporary state.

Also needs to be writable by the web server.

---

### `tools/`
Utility & maintenance scripts.

- One-off or scheduled scripts (cron jobs, imports, maintenance tasks).
- Anything that isn’t part of the normal web request flow but supports the app.

If you write a script you run from the command line to help manage the system, it likely belongs here.

---

## Top-level files

### `index.html`
Landing or entry page for the Programme app.

- Public entry point – typically links into the main application views.

### `about.html`
Static “About” / info page describing what the Programme tool is and how it’s used.

### `analytics.html`
Front-end entry for reporting/analytics views.

- Usually pulls data from the `api/` layer and renders charts/tables with JS.

### `links.html`
Collection of useful links (other DefectTracker tools, documentation, etc.).

### `login.html`
Public login form that hands off to the authentication logic in the PHP code.

### `composer.json`
Composer configuration.

- Declares PHP dependencies (if any) and autoloading rules.
- Run `composer install` in this repo on a dev machine to pull in dependencies.

---

## How things fit together (high level)

1. **Public HTML pages** (`index.html`, `analytics.html`, etc.) act as entry points.
2. Requests are handled by code in `app/` and `libs/`, which:
   - Talk to the database,
   - Apply business rules,
   - Choose the right template from `templates/`.
3. **Admin functionality** is exposed via `admin/`.
4. **JSON data** is served from `api/` to power dynamic UI and external integrations.
5. **Assets** in `assets/js/` add interactivity on the client side.
6. **Migrations** in `scripts/migrations/` keep the database in sync with the code.

---

## Setup & deployment (Plesk / shared hosting)

### Requirements

- PHP 8.x (CLI and web SAPI)
- MySQL or MariaDB database
- Web server (Apache / Nginx via Plesk)
- Optional: Composer (for local development)

---

### 1. Local setup (optional but recommended)

```bash
# Clone the repository
git clone https://github.com/irlam/programme.git
cd programme

# Install PHP dependencies (if used)
composer install  # optional but recommended

# Configure your local web server to serve this folder as a virtual host,
# or use something like PHP's built-in server for simple testing:
php -S localhost:8000
