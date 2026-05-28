# AI Audit BOS

> AI-powered Business Operating System for audit firms. Less spreadsheets. Less chasing. More auditing.

**AI Audit BOS** replaces the patchwork of Excel, WhatsApp, shared drives and email threads
that most audit firms run on, with a single operating system — built around how audit teams
actually work, with AI woven through every step.

The goal isn't *better audit software*. The goal is to **run the whole audit operation**:
workflow, client document collection, accounting data import, AI assistant, working papers,
partner review, staff KPIs, billing — in one place, by design.

Production-ready, native PHP 8 + MySQL. No Composer. Deploys to Hostinger shared hosting
in minutes.

---

## Table of contents

- [What's inside](#whats-inside)
- [Tech stack](#tech-stack)
- [Quick start](#quick-start)
- [Folder layout](#folder-layout)
- [Roles & access](#roles--access)
- [Modules](#modules)
  - [Engagement workspace](#engagement-workspace)
  - [Document portal](#document-portal)
  - [Accounting data import](#accounting-data-import)
  - [Working papers](#working-papers)
  - [Financial statements](#financial-statements)
  - [AI assistant](#ai-assistant)
  - [Staff KPI & partner dashboard](#staff-kpi--partner-dashboard)
  - [Credit wallet & billing](#credit-wallet--billing)
  - [Activity timeline & audit trail](#activity-timeline--audit-trail)
  - [Super-admin tooling](#super-admin-tooling)
- [Account flows](#account-flows)
- [Security](#security)
- [Configuration reference](#configuration-reference)
- [Deployment](#deployment)
- [Roadmap](#roadmap)

---

## What's inside

| | |
| --- | --- |
| **One screen per job** | Engagement workspace with status, team, deadline, document checklist, working papers, AI panel, accounting data, and a vertical activity timeline. |
| **Branded client portal** | Clients land on a tidy checklist of what to upload. Files mark requests received automatically. |
| **Accounting data import** | CSV and **native XLSX** import for trial balance and general ledger. Per-source column mappings are remembered (SQL Account, AutoCount, UBS, Bukku, Million, Financio). Excel serial dates handled. |
| **Working papers & review notes** | Section taxonomy, risk ratings, full preparer → reviewer → partner thread. Status auto-bumps as notes are raised, responded, cleared. |
| **Financial statements** | SOFP + SOCI generated from the trial balance with print/PDF and CSV export. Heuristic account classification by code + type + name. |
| **AI assistant** | 8 functions (variance analysis, audit queries, WP review, management letter, client reminders, document classification, engagement summary, partner review pack). Real Anthropic Claude with prompt caching and structured prompts pulling real engagement data. |
| **Staff KPI & partner dashboard** | Live productivity scores, high-risk areas, top performers, AI alerts. Partners see engagement health at a glance. |
| **Credit wallet & billing** | Per-firm wallets, manual top-ups, full transaction history, AI usage by function. Every AI call shows the MYR cost up front. |
| **Activity timeline & audit trail** | Every status change, document upload, review note, AI call and login is logged. Engagement-scoped timeline shows the full story. |
| **Super-admin observability** | Cross-firm wallets, transactions, activity log, AI usage, impersonate-firm for support, demo-data seeder. |

---

## Tech stack

- **PHP 8.0+** native — no Composer, no framework
- **MySQL 5.7+** / **MariaDB 10.3+** (utf8mb4)
- **Tailwind CSS** via CDN (compile later if you want)
- **Vanilla JavaScript** for interactivity (sidebar toggle, role tabs, FAQ accordion, stat counters)
- **Apache** with `mod_rewrite` or **Nginx** — Hostinger-compatible
- **Anthropic Claude** API (`claude-opus-4-7` with adaptive thinking + `effort: high`) via cURL

No build step. No JS bundler. Drop the files into `public_html` and you're live.

---

## Quick start

### 1. Database

```bash
mysql -u root -p
```

```sql
CREATE DATABASE auditbos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'auditbos'@'localhost' IDENTIFIED BY 'replace-me';
GRANT ALL PRIVILEGES ON auditbos.* TO 'auditbos'@'localhost';
FLUSH PRIVILEGES;
\q
```

```bash
mysql -u auditbos -p auditbos < sql/001_initial_schema.sql
mysql -u auditbos -p auditbos < sql/002_import_module.sql
```

### 2. Config

```bash
cp config/db_config.local.example.php config/db_config.local.php
```

Edit `config/db_config.local.php`:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'auditbos');
define('DB_USER', 'auditbos');
define('DB_PASS', 'replace-me');

// Optional — leave AI_API_KEY empty to keep the AI stub on.
define('AI_PROVIDER',     'anthropic');
define('AI_MODEL',        'claude-opus-4-7');
define('AI_API_KEY',      '');            // sk-ant-... when you're ready
define('AI_USD_TO_MYR',   4.70);          // FX rate for cost conversion
define('AI_CREDIT_MARKUP', 1.00);         // billable = MYR cost × markup

// Optional — outbound email for password reset & invitations.
// Without these, the platform shows the reset/invite link on-screen
// for manual relay (handy on Hostinger shared hosting without SMTP).
define('MAIL_FROM',      'noreply@your-firm.com');
define('MAIL_FROM_NAME', 'Your Audit Firm');

// Optional — set so absolute invitation URLs use your domain.
define('APP_BASE_URL',   'https://your-firm-domain.com');
```

### 3. First super-admin user

```bash
php sql/install_super_admin.php
```

The script prompts for email + name + password (minimum 12 chars). You can also pass them via env vars:

```bash
SA_EMAIL=you@firm.com SA_PASSWORD='Strong!Pass2026' SA_NAME='Super Admin' \
  php sql/install_super_admin.php
```

To rotate an existing super-admin password instead of creating a new one:

```bash
php sql/install_super_admin.php --reset
```

### 4. Web server

Point the document root at the **project root** (the folder with `index.php` and `.htaccess`).

Apache:
```apache
DocumentRoot /var/www/auditbos
<Directory /var/www/auditbos>
    AllowOverride All
    Require all granted
</Directory>
```

PHP needs `fileinfo`, `pdo_mysql`, `curl`, `openssl`, `zip` enabled (all default on Hostinger).

Make sure the web user can write to `uploads_private/`:

```bash
chown -R www-data:www-data uploads_private
chmod -R 750 uploads_private
```

### 5. Sign in

Visit `/` — the marketing landing page. Click **Sign in**, use the super-admin
credentials from step 3.

### 6. Optional — seed a demo firm with sample data

After creating a firm via `/admin/firms.php`:

`/admin/firms.php` → **Seed** action on the firm row → seeds 3 clients, 2 engagements,
10 working papers, a 26-account trial balance + 30 sample GL transactions, and 5 staff
users (password `DemoPass!2026`).

Then sign in as `demo.admin@example.com` to play around — or use **Impersonate** from
super-admin's firm-users page.

---

## Folder layout

```
/admin            Super-admin pages (firms, wallets, transactions, activity, impersonate, seed)
/ai               AI service layer + UI (registry, prompts, run, outputs, document_classify)
/assets           CSS / JS
/audit            Working papers + review notes thread
/auth             Login / logout / forgot password / reset / accept invite
/config           DB + app config (web-blocked)
/documents        Document collection portal (upload + secure download)
/firm             Staff, clients, engagements, doc requests, portal users, wallet, KPI
/import           Accounting data import (TB, GL, variance view)
/includes         auth_guard, header, sidebar, helpers, csrf, kpi, timeline, fs_builder (web-blocked)
/reports          Financial statement export (SOFP + SOCI)
/sql              Migrations + super-admin installer (web-blocked)
/uploads_private  Client uploads — never web-accessible
/index.php        Public marketing landing (logged-in users bounce to /dashboard.php)
/dashboard.php    Role-aware authenticated landing
```

---

## Roles & access

| Role | Default landing | Key permissions |
|---|---|---|
| `super_admin` | `/admin/firms.php` | All firms, wallets, transactions, activity, impersonate |
| `firm_admin` | `/dashboard.php` | Manage firm + staff + clients + engagements + wallet view |
| `audit_manager` | `/dashboard.php` | Clients, engagements, working papers, AI, KPI |
| `senior_auditor` | `/dashboard.php` | Working papers, review notes, AI, imports |
| `junior_auditor` | `/dashboard.php` | Working papers (limited), document uploads |
| `reviewer` | `/dashboard.php` | Review notes, partner-style review, KPI |
| `client_user` | `/documents/index.php` | Upload documents for their client's engagements only |

Multi-tenancy is enforced via `firm_id` (and `client_id` for portal users) on **every**
database query. There is no shared "global" view that bypasses scoping — even super
admins must impersonate to act in a firm's context.

---

## Modules

### Engagement workspace

`/firm/engagement_view.php?id=…`

Single screen per engagement showing:
- Header with client, FY, partner, manager, deadline, fee, status
- Document checklist with inline status updates and a "Seed default checklist" button
- Working papers list with risk + status badges + open-note counts
- Accounting data summary (TB rows current/prior, GL row count) with deep links to import + FS
- AI Assistant panel — buttons for Summarise / Analyse TB / Draft queries
- Recent AI outputs (last 5) linking to the output viewer
- Vertical **activity timeline** showing the last 60 events from `activity_logs`

### Document portal

`/documents/index.php`

- **Firm staff view:** engagement picker, requests checklist with inline upload buttons
  per request, uploaded-files list with status + AI classification suggestions
- **Client view:** only sees engagements for their `client_id`, same upload UI without
  the unlinked-upload option

Files are stored under `uploads_private/{firm_id}/{engagement_id}/{random}.ext`. The
download endpoint (`/documents/download.php`) verifies firm/client scope, resolves the
path with `realpath()` containment, and streams the file via `readfile()` — direct URLs
never hit the filesystem.

### Accounting data import

`/import/index.php`

Three-step flow for both TB and GL:

1. Upload `.csv` or `.xlsx` + pick period (TB only) + source (Excel / SQL Account / AutoCount / UBS / Bukku / Million / Financio / Other). Server validates type, size, MIME.
2. Column mapping with auto-detection from header keywords + remembered mappings per
   `(firm, import_type, source)`. Live 5-row preview.
3. Commit inside one transaction. TB import **replaces** rows for `(engagement, period)`;
   GL is append by default with an optional "replace previous rows from this source"
   toggle. Chart of accounts upserts automatically.

The XLSX parser is **native** — built on PHP's `ZipArchive` + `SimpleXML` + `XMLReader`
with no Composer dependency. Resolves shared strings, follows workbook rels to find the
first sheet, detects Excel-built-in + custom date numFmts and converts serial dates to
ISO. Sparse rows are compacted to a dense positional array.

Trial balance variance view: `/import/trial_balance_view.php?engagement_id=…` shows
pivoted current-vs-prior with absolute Δ and Δ% per account, threshold-based row
flagging, and a one-click button to run AI variance analysis on the displayed data.

### Working papers

`/audit/working_papers.php` (list + create) and `/audit/working_paper_view.php?id=…`

- Section taxonomy from `audit_sections` (firm-customisable; system defaults shipped:
  Planning, Risk assessment, Cash, AR, Inventory, FA, Payroll, Tax, RPT, Going concern,
  Subsequent events, FS review, Completion).
- Status machine: `not_started → prepared → pending_review → review_note_raised → cleared → completed`.
- Risk ratings (low / medium / high / critical).
- Full **review notes thread** per WP — reviewer raises (`severity`: info / minor / major / critical),
  preparer responds, reviewer clears. WP status auto-transitions to
  `review_note_raised` and back to `pending_review` when all notes are cleared.
- "AI review" button runs `ai_review_working_paper` on the current paper.

### Financial statements

`/reports/financial_statements.php?engagement_id=…`

Statement of Financial Position and Statement of Comprehensive Income generated directly
from the trial balance — no separate schema, no mapping UI required.

Account classification is hybrid:
- `chart_of_accounts.account_type` wins when set (`asset` / `liability` / `equity` / `income` / `expense`)
- Code-band heuristics fall back: `1xxx` asset, `2xxx` liability, `3xxx` equity, `4xxx`
  income, `5xxx` cost of sales, `6xxx` opex, `7xxx` finance costs, `8xxx` tax
- Name-keyword tiebreakers (depreciation / ECL / cost of sales / inventory / bonus accrual)

Features:
- Toggle account-level detail (`?detail=1`)
- CSV export per statement (`?export=csv&fs=sofp|soci`)
- **Print / PDF** via browser `window.print()` — `@media print` CSS hides chrome and tightens table padding
- Balance-check banner when total assets ≠ total equity + liabilities beyond a small tolerance
- Unclassified-accounts panel for anything the heuristic couldn't place

Deliberately deferred: per-account mapping UI, SOCIE, cashflow statement, MFRS-compliant notes.

### AI assistant

All AI work goes through `/ai/ai_service.php`. The provider is **Anthropic Claude** —
model `claude-opus-4-7` with adaptive thinking and `effort: high`, called via cURL with
no Composer dependency. Every call writes to `ai_logs` (prompt, output, tokens, latency,
cost, status, error) and curated outputs land in `ai_outputs`
(`status` in `draft / accepted / rejected / published`).

Each function has a **prompt builder** in `/ai/prompts.php` that pulls real engagement
data (TB, GL, documents, working papers, review notes) and formats it into a structured
user message before sending. Claude reasons over the actual job, not generic instructions.

| Function | Input | Output |
|---|---|---|
| `ai_analyze_trial_balance` | Pivoted current/prior TB | Variance memo with findings by risk level |
| `ai_detect_variance` | Same as above | Compact variance list |
| `ai_generate_audit_queries` | Outstanding docs + variances >20% | Polite client queries grouped by topic |
| `ai_review_working_paper` | One WP's procedure / conclusion / existing notes | Reviewer suggestions with `[severity]` tags |
| `ai_generate_management_letter` | High-risk WPs + major review notes | Sections in Observation / Risk / Recommendation / Mgmt response format |
| `ai_generate_client_reminder` | Pending document requests | Email + WhatsApp drafts with deadline |
| `ai_summarize_engagement_status` | Docs + WPs + data summary | Status briefing for a partner |
| `ai_partner_review_assistant` | Same as summarize, partner framing | Sign-off-ready review pack |
| `ai_classify_document` | One uploaded PDF/image + open doc requests | JSON: suggested request match + confidence + summary + key figures |
| `ai_analyze_gl_exceptions` | GL analytics output (duplicates/round/weekend/Benford/etc.) | Prioritised investigate-first memo by risk |
| `ai_going_concern_assessment` | Ratios + materiality | Going-concern conclusion + procedures + representations |
| `ai_generate_audit_report` | Engagement + FS + materiality + opinion type | Full Independent Auditor's Report draft |

**Cost transparency.** Per-call cost is computed from `usage.input_tokens` and
`usage.output_tokens`, converted USD → MYR via `AI_USD_TO_MYR`, optionally multiplied by
`AI_CREDIT_MARKUP`, then debited from `credit_wallet`. Each call's MYR cost is visible in
`ai_logs.credits_used` and on every output card.

**Stub mode.** Until `AI_API_KEY` is set, the service returns deterministic stub output
that echoes the built user message so every UI flow remains testable without burning tokens.

AI outputs catalogue: `/ai/outputs.php` (list with filters) and `/ai/output_view.php`
(single view with markdown rendering, accept / reject / publish workflow,
copy-to-clipboard, raw-text toggle).

### Staff KPI & partner dashboard

`/firm/kpi.php`

Per-staff productivity computed on-the-fly from live data:
- Jobs assigned vs completed
- Working papers prepared / pending review
- Review notes received vs cleared
- Overdue engagements
- Average WP cycle time (`DATEDIFF(reviewed_at, prepared_at)`)
- **Productivity score** = WP × 2 + notes cleared × 3 + jobs completed × 5 − overdue × 5

Sortable table with a relative-score bar, last-login timestamp, and headline cards across
the top.

Partner-side dashboard (firm_admin / audit_manager / reviewer) adds three panels above
the recent-engagements list:
- **High-risk areas** — WPs with `risk_rating ∈ (high, critical)` or `status = review_note_raised`
- **AI alerts** — draft AI outputs of type variance / partner / WP review / management letter
- **Top performers** — top 5 productivity scores

### Credit wallet & billing

**Firm view:** `/firm/wallet.php` shows balance / total topped up / total used, usage by
category (bar chart), and the last 200 transactions. Read-only for firm staff — only
super admin records top-ups.

**Super admin wallet ops:**
- `/admin/wallets.php` — cross-firm overview with low-balance alerts
- `/admin/firms_topup.php?firm_id=…` — record a manual top-up (amount + reference + notes)
- `/admin/transactions.php` — global credit ledger with filters

Top-ups run inside one transaction: `credit_wallet.balance` + `credit_transactions` row
+ mirrored `firms.credit_balance`.

### Activity timeline & audit trail

`activity_logs` records every meaningful action with `firm_id`, `user_id`, `action`,
`entity_type`, `entity_id`, `description`, `ip_address`, `user_agent`, `created_at`.

Two views:
- `/admin/activity.php` — platform-wide log with filters (firm, action prefix, date range)
- Engagement timeline panel on `/firm/engagement_view.php` — aggregates logs across every
  entity type that ties back to an engagement (engagement, document_request,
  engagement_document, audit_working_paper, audit_review_note, import_batch, ai_output)

During super-admin impersonation, log entries are tagged with
`[impersonated by <name> #<id>]` so the audit trail makes clear who actually performed
the action.

### Super-admin tooling

| Page | Purpose |
|---|---|
| `/admin/firms.php` | CRUD firms; per-row Users, Top up, Seed, Edit actions |
| `/admin/firm_users.php?firm_id=…` | Manage a firm's internal users (invite, role change, resend, revoke, **impersonate**) |
| `/admin/wallets.php` | Cross-firm wallet overview |
| `/admin/transactions.php` | Global credit ledger with filters |
| `/admin/activity.php` | Platform-wide audit log with filters |
| `/admin/firms_topup.php?firm_id=…` | Record a manual top-up |
| `/admin/seed_demo.php?firm_id=…` | One-click demo data for a firm |
| `/admin/impersonate.php` | Start/stop impersonation (POST endpoint) |

**Impersonation.** Lets super admin view as a firm user without sign-out/sign-in. A
pinned amber banner shows the impersonation context on every page with a "Stop
impersonating" button. Cannot impersonate other super admins or inactive accounts;
nested impersonation is blocked.

The super-admin dashboard (`/dashboard.php` as super_admin) shows:
- 8 KPI cards (firms, subs, engagements, overdue, users, AI calls 30d, topped up 30d, credits used 30d)
- Recent platform activity (last 15)
- Wallets running low (< MYR 25)
- AI usage by function (30d) with per-function bar
- Subscription mix (stacked bar)
- Recently active firms

---

## Account flows

### Password reset

`/auth/forgot_password.php` → email → single-use 32-byte token, SHA-256-hashed in
`users.password_reset_token` with 24-hour expiry → email (if `MAIL_FROM` configured) or
on-screen link → `/auth/reset_password.php?token=…` validates, accepts new password,
regenerates session, signs the user in directly.

Response is uniform whether or not the email exists (no user enumeration). Linked from
the login screen as **Forgot your password?**.

### Client portal-user invitations

From the client edit screen → **Manage portal users →** → `/firm/client_portal.php?id=…`

Firm admin / audit manager can:
- Invite a new portal user (creates user with `role=client_user`, `status=inactive`,
  plus a single-use token)
- Resend (rotates the token)
- Revoke (suspends the account)
- Reactivate

Invitees land at `/auth/accept_invite.php?token=…` → redirects to `reset_password.php`
which flips `status` from `inactive` → `active` on first password set.

### Firm-user invitations (super admin)

`/admin/firm_users.php?firm_id=…` — same shape as client portal invitations but for the
firm's internal team. Supports inline role change.

### Custom document requests

`/firm/doc_requests.php?engagement_id=…` — full CRUD for engagement-level document
requests (title, category, due date, status, internal admin notes). Deletes are blocked
when files are attached.

---

## Security

- **Multi-tenant scoping** — every query filters by `firm_id` (or `client_id` for portal users)
- **Prepared statements** — PDO with `ERRMODE_EXCEPTION` and `EMULATE_PREPARES = false`
  (native MySQL prepared-statement protocol)
- **bcrypt** password hashing via `PASSWORD_DEFAULT`, with `password_needs_rehash` re-hashing on login
- **Per-user lockout** — `LOGIN_MAX_ATTEMPTS` (default 6) wrong logins → 15-minute lockout
- **CSRF** tokens auto-verified on every POST via the auth guard
- **Hardened sessions** — HttpOnly + SameSite=Lax cookies, Secure on HTTPS, regenerated
  on login and on impersonation start/stop, with idle timeout
- **Upload validation** — extension + MIME allow-list, size cap (30 MB default), random
  filenames, files stored outside the web root in `uploads_private/`, PHP execution
  disabled inside upload directory via `.htaccess`
- **Download endpoint** with `realpath()` path containment and engagement-level
  authorisation — direct filesystem URLs are not possible
- **Headers** — `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy` set in the root `.htaccess`
- **`.htaccess` denies** on `/config`, `/includes`, `/sql`, `/ai/{ai_service,prompts}.php`,
  `/uploads_private`
- **Activity audit trail** — every significant action logged with actor, IP, user agent,
  and (for super-admin impersonation) the real user's identity
- **AI cost transparency** — every Claude call records prompt, output, tokens, latency
  and MYR cost in `ai_logs`

---

## Configuration reference

All defaults can be overridden in `config/db_config.local.php` (gitignored).

| Constant | Default | Notes |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `auditbos` | |
| `DB_USER` | `auditbos` | |
| `DB_PASS` | *(empty)* | Set in local config |
| `DB_CHARSET` | `utf8mb4` | |
| `APP_NAME` | `AI Audit BOS` | Used in titles and emails |
| `APP_BASE_URL` | *(auto-detect)* | Set for correct invitation URLs |
| `APP_TZ` | `Asia/Kuala_Lumpur` | |
| `SESSION_LIFETIME` | `28800` | 8 hours |
| `LOGIN_MAX_ATTEMPTS` | `6` | Per-user lockout threshold |
| `LOGIN_LOCKOUT_SECONDS` | `900` | 15 minutes |
| `UPLOAD_MAX_BYTES` | `31_457_280` | 30 MB |
| `UPLOAD_ALLOWED_EXT` | `pdf, xlsx, csv, docx, …` | See `config/app_config.php` |
| `AI_PROVIDER` | `anthropic` | |
| `AI_MODEL` | `claude-opus-4-7` | |
| `AI_API_KEY` | *(empty)* | Empty = stub mode |
| `AI_USD_TO_MYR` | `4.70` | Override per environment |
| `AI_CREDIT_MARKUP` | `1.00` | Billable = MYR cost × markup |
| `AI_MAX_TOKENS` | `8192` | |
| `AI_EFFORT` | `high` | `low / medium / high / max` |
| `AI_HTTP_TIMEOUT` | `180` | Seconds |
| `MAIL_FROM` | *(empty)* | Empty = surface reset/invite links in UI |
| `MAIL_FROM_NAME` | `APP_NAME` | |
| `TOKEN_TTL_SECONDS` | `86400` | 24-hour reset / invite tokens |

---

## Deployment

### Hostinger / shared hosting

1. Upload the entire project folder to `public_html` (or the document-root folder of your domain)
2. Create the MySQL database from hPanel; note the host, name, user, password
3. Copy `config/db_config.local.example.php` to `config/db_config.local.php` and fill in
4. Open phpMyAdmin and run `sql/001_initial_schema.sql` and `sql/002_import_module.sql`
5. SSH in (or use the file manager terminal) and run `php sql/install_super_admin.php`
6. Confirm `uploads_private/` is writable by the web user (Hostinger default: yes)
7. Visit your domain — you should see the landing page

Hostinger PHP defaults satisfy the requirements (`fileinfo`, `pdo_mysql`, `curl`,
`openssl`, `zip` all enabled). The provided `.htaccess` files handle URL rewriting and
security headers.

### VPS

Standard LAMP / LEMP stack. PHP 8.0+ with the extensions listed above. Point the
document root at the project root.

### Database migrations

| File | Adds |
|---|---|
| `sql/001_initial_schema.sql` | 21 core tables + seed default document categories + audit sections |
| `sql/002_import_module.sql` | `import_batches` + `import_mappings` + adds `import_batch_id` to `trial_balances` and `general_ledgers` |
| `sql/003_lead_schedules.sql` | `lead_schedule_overrides` + adds `lead_area` to `audit_working_papers` |
| `sql/004_approval_workflow.sql` | `engagement_signoffs` + adds `review_stage` / `locked_at` / `locked_by` to `engagements` |
| `sql/005_reminders.sql` | `reminders` log table |
| `sql/006_materiality.sql` | `engagement_materiality` table |
| `sql/007_aging.sql` | `aging_items` table (debtor / creditor aging) |
| `sql/008_rollover.sql` | adds `rolled_over_from_id` to `engagements` |
| `sql/009_workplan.sql` | `engagement_workplan` table for the 27-step audit SOP |

Run them in order. They're idempotent on `CREATE TABLE IF NOT EXISTS` for the new tables
but the `ALTER TABLE` statements in `002` will fail if run twice — wrap in your own
migration tool if needed.

### Updating

```bash
git pull origin main
# If a new sql/00N_*.sql file appeared, apply it:
mysql -u auditbos -p auditbos < sql/00N_new_migration.sql
```

No build step, no `composer install`, no asset compilation.

---

## Development

### Running locally

```bash
cd /path/to/auditbos
cp config/db_config.local.example.php config/db_config.local.php
# edit DB creds
mysql -u root -p auditbos < sql/001_initial_schema.sql
mysql -u root -p auditbos < sql/002_import_module.sql
php sql/install_super_admin.php
php -S localhost:8080
```

Open `http://localhost:8080/`.

### Adding a new AI function

1. Add an entry to `ai_registry()` in `/ai/ai_service.php` with `prompt`, `output_type`, `entity_type`, `title`.
2. Add a prompt builder in `/ai/prompts.php` — a function that takes
   `(?int $engagementId, array $payload)` and returns a structured user-message string.
3. Wire the new key into `ai_build_user_message()`'s dispatch switch.
4. Use it: `ai_run('your_new_function', ['entity_id' => $someId], $engagementId)`.

### Code conventions

- `declare(strict_types=1)` at the top of every PHP file
- `htmlspecialchars` via the `e()` helper for every dynamic value in HTML
- Prepared statements only — never string interpolation in SQL
- One placeholder per name in a single prepared statement (PDO native prepares reject duplicates)
- Defense-in-depth `AUDITBOS_BOOTSTRAPPED` guard at the top of every include file

### Useful commands

```bash
# Syntax-check every PHP file in the project
find . -name "*.php" -not -path "./.git/*" -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors'

# Find duplicate placeholder uses (the kind that crash PDO native prepares)
# See dashboard.php for the pattern of using one prepared statement per metric.
```

---

## Roadmap

### Built

- ✅ Multi-tenant schema, auth, role-based access
- ✅ Firm / staff / client / engagement management
- ✅ Document portal with secure upload + download
- ✅ Automated reminders (client document chasing + staff nudges, email + WhatsApp deep-link, cron endpoint)
- ✅ Working papers + review notes thread
- ✅ Multi-level approval chain + archive locking (Preparation → Senior → Manager → Partner → Signed off → Locked)
- ✅ Accounting data import (CSV + native XLSX)
- ✅ Trial balance variance view
- ✅ Lead schedules (TB grouped into audit areas, reclassify, tie to working paper)
- ✅ GL analytics (duplicate payments, round numbers, weekend postings, outliers, Benford, concentration)
- ✅ Materiality calculator + going-concern ratios (current vs prior)
- ✅ Debtor / creditor aging analysis (import listing, bucket exposure, ECL focus, AI review)
- ✅ Independent auditor's report draft generator (4 opinion types, ISA 700 / Companies Act 2016 structure)
- ✅ Year-end rollover (clone WPs, requests, lead overrides, materiality basis, team) + filtered engagement list + per-engagement completion rings
- ✅ 27-step audit workplan SOP (Client Acceptance → File Locking) — auto-seeded per engagement, owner/reviewer/due-date assignment, hook-based auto-progression from underlying features
- ✅ Financial statement export (SOFP + SOCI)
- ✅ AI assistant (8 functions + document classifier)
- ✅ Staff KPI module + partner dashboard
- ✅ Credit wallet + manual top-ups + transaction ledger
- ✅ Activity timeline + audit trail
- ✅ Super-admin observability (wallets, transactions, activity, AI usage)
- ✅ Impersonate firm for super-admin
- ✅ Demo data seeder
- ✅ Password reset + invitation flows
- ✅ Public marketing landing page

### Not built yet (deliberate)

- 🚫 MBRS submission
- 🚫 SOCIE (Statement of Changes in Equity) — needs share-capital history
- 🚫 Cashflow statement
- 🚫 MFRS-compliant notes / disclosures (the auditor's report draft is generated; the full FS with notes is not)
- 🚫 Per-account FS mapping UI (heuristic classifier covers ~95% of standard CoAs)
- 🚫 Live accounting-API integrations (CSV/XLSX export already covers SQL Account, AutoCount, UBS, Bukku, Million, Financio)
- 🚫 SMTP / SendGrid integration (reminders use PHP `mail()`; WhatsApp is a deep-link, not an API send)
- 🚫 Engagement deadline calendar / Gantt view
- 🚫 Bulk doc-request templates per firm

If you need one of the above, open an issue.

---

## License

Internal use. Contact the project owner for licensing terms.

---

## Acknowledgements

- **Anthropic Claude** for the AI brain (`claude-opus-4-7`)
- **Tailwind CSS** for making CSS bearable
- Audit firms in Malaysia for the practical feedback that shaped the workflows
