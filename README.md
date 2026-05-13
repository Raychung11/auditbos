# AI Audit BOS

An AI-powered Business Operating System for audit firms. Phase 1 MVP scaffolding.

> **Status:** initial scaffolding pushed; see open draft PR for the full diff.

This is not just audit software — the goal is to run the entire audit operation
intelligently: workflow automation, client document collection, accounting data
import, AI assistant, working papers, partner review, and staff KPIs.

## Stack

- PHP 8+ (native, no frameworks)
- MySQL 5.7+ / MariaDB 10.3+
- Tailwind CSS (via CDN for now)
- Vanilla JavaScript (Alpine.js can be added later)
- Apache/Nginx, Hostinger-friendly

## Folder layout

```
/admin           Super-admin pages (firms)
/ai              AI service layer + UI (run, history, registry)
/assets          CSS / JS
/audit           Working papers, review notes
/auth            Login / logout
/client          (reserved for client-only pages)
/config          DB + app config (web-blocked)
/documents       Document collection portal (upload + download)
/firm            Staff, clients, engagements
/import          (reserved for TB/GL import)
/includes        auth_guard, header, sidebar, helpers, csrf (web-blocked)
/reports         (reserved)
/sql             Migrations + installer (web-blocked)
/uploads_private Client uploads — never web-accessible
```

## First-time setup

1. **Database**

   ```bash
   mysql -u root -p
   CREATE DATABASE auditbos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'auditbos'@'localhost' IDENTIFIED BY 'replace-me';
   GRANT ALL PRIVILEGES ON auditbos.* TO 'auditbos'@'localhost';
   FLUSH PRIVILEGES;
   exit
   mysql -u auditbos -p auditbos < sql/001_initial_schema.sql
   ```

2. **Config**

   ```bash
   cp config/db_config.local.example.php config/db_config.local.php
   # edit values
   ```

3. **First super-admin user**

   ```bash
   php sql/install_super_admin.php
   ```

4. **Webserver**

   Point the document root at the project root. Apache `mod_rewrite` is
   recommended (clean URLs are configured in the root `.htaccess`). PHP
   needs `fileinfo` enabled (default).

   Make sure the web user can write to `/uploads_private`.

5. **Sign in** at `/auth/login.php`.

## Roles

| Role            | Default landing            | Key permissions |
|-----------------|----------------------------|-----------------|
| super_admin     | `/admin/firms.php`         | All firms       |
| firm_admin      | `/dashboard.php`           | Manage firm     |
| audit_manager   | `/dashboard.php`           | Manage clients + engagements |
| senior_auditor  | `/dashboard.php`           | Working papers, review notes |
| junior_auditor  | `/dashboard.php`           | Working papers (limited) |
| reviewer        | `/dashboard.php`           | Review notes, partner-style review |
| client_user     | `/documents/index.php`     | Upload documents for their engagements |

## AI service

All AI work goes through `/ai/ai_service.php`. Every call writes to `ai_logs`
(prompt, output, tokens, latency, status, error_message) and curated outputs
land in `ai_outputs` (with `status` in `draft / accepted / rejected / published`).

The provider is **Anthropic Claude — model `claude-opus-4-7` with adaptive
thinking and `effort: high`**, called via cURL with no Composer dependency.
Per-call cost is computed from `usage.input_tokens` and `usage.output_tokens`
returned by the API and debited from `credit_wallet` (USD; firms can apply a
markup via `AI_CREDIT_MARKUP`).

Each function has a **prompt builder** in `/ai/prompts.php` that pulls real
engagement data (TB, GL, documents, working papers, review notes) and formats
it into a structured user message before sending. That way Claude reasons over
the actual job, not generic instructions.

Functions:

- `ai_analyze_trial_balance` — uses pivoted current-vs-prior TB rows
- `ai_generate_audit_queries` — uses outstanding docs + > 20% variances
- `ai_review_working_paper` — uses one WP's procedure / conclusion / notes
- `ai_generate_management_letter` — uses high-risk WPs + major review notes
- `ai_generate_client_reminder` — uses pending document requests
- `ai_detect_variance` — same input as TB analysis
- `ai_summarize_engagement_status` — uses doc checklist + WPs + data summary
- `ai_partner_review_assistant` — same as summarize, partner framing

Until `AI_API_KEY` is set in `db_config.local.php`, the service returns
deterministic stub output (echoing the built user message) so the UI works
end-to-end.

AI outputs can be reviewed at `/ai/outputs.php` (catalogue) and
`/ai/output_view.php` (single view with markdown rendering, accept/reject/
publish workflow, copy-to-clipboard, raw-text toggle). The working paper view
has an "AI review" button that invokes `ai_review_working_paper` on the
current WP.

## Security

- Prepared statements everywhere (PDO with `ERRMODE_EXCEPTION`,
  `EMULATE_PREPARES = false`).
- CSRF tokens auto-verified on POST in `includes/auth_guard.php`.
- bcrypt password hashing via `PASSWORD_DEFAULT`.
- Per-user lockout (`LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_SECONDS`).
- Hardened session cookies (HttpOnly, Lax, Secure when HTTPS).
- Uploads stored under `/uploads_private` (web-blocked + PHP execution
  disabled via `.htaccess`); served through `/documents/download.php`
  which enforces engagement-level authorisation and path containment.
- Activity audit trail in `activity_logs`.

## Not built yet (deferred per spec)

- MBRS submission
- Full financial statement generator
- OCR
- Live SQL Accounting / AutoCount / UBS / Bukku / Million / Financio
  integrations (the schema is already shaped for these)
- WhatsApp send-out (templates can be drafted via AI now)
