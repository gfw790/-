# GitHub Copilot Workspace Instructions

## About this workspace

This repository is a multi-module safety and management system rooted at `a:\risk_server\project`.
It is built with PHP, MySQL, JavaScript, and runs primarily in a XAMPP local server environment.

Key folders:
- `risk_assessment/` — risk assessment system for hazard generation and scoring.
- `tbm/` — Tool Box Meeting (daily safety briefing) system.
- `hr-server/` — main HR management system built on Laravel 11 + PHP 8.2.
- `hr_simple/` — simplified HR system for lightweight or test use.
- `near_miss/` — near miss / accident reporting system.
- `Fleet/` — vehicle/equipment management support.
- `board/` — reusable bulletin board module, often integrated across apps.
- `calendar/` — scheduling and date-based UI features.
- `view/` — shared UI or layout components.
Project goals:
- Build a practical, field-focused safety management platform.
- Automatically recommend hazards based on work conditions.
- Enable worker participation in the risk assessment process.
- Keep the system easy to maintain and suitable for a local/internal network.

Core principles:
- PHP should remain procedural unless a refactor is explicitly requested.
- Avoid unnecessary frameworks and overengineering.
- Use modular, folder-based structure and existing app conventions.
- DB design should use `*_master` and `*_mapping` tables, minimize duplication, and keep foreign-key-like relationships where possible.
## What Copilot should know

- This is not a single monorepo app; each top-level folder is its own application or feature area.
- Most work is PHP-centric and procedural except for the Laravel-based HR apps.
- The primary deployment environment is Windows + XAMPP with Apache + MySQL.
- Do not assume Linux paths, systemd, or Linux-only tooling.
- Use folder-specific README files and existing app conventions when available.
- Keep changes practical, maintainable, and aligned with the current folder-based architecture.

## System overview

- `risk_assessment/` focuses on hazard lists, environment/tool mapping, and risk scoring.
- `tbm/` handles daily safety briefing logs, accident summaries, and AI-assisted quiz generation.
- `hr-server/` and `hr_simple/` provide employee/team data used across other modules.
- `near_miss/` stores incident reports, attachments, and reporting workflows.
- `Fleet/` manages vehicles or equipment records.
- `board/` is a shared posting/listing module used across systems.
- `calendar/` provides date-based UI behavior.
- `view/` contains shared layouts or common UI pieces.

## Files and docs to reference

- `hr-server/README.md`
- `hr_simple/README.md`
- `board/README.md`
- `near_miss/README.md`

The root-level `.claude/settings.json` exists, but it is not a general workspace instruction file.

## Project-specific guidance

### Laravel apps

For `hr-server` and `hr_simple`:
- Use `composer install` or `composer update` in the project folder.
- Manage env files and database migrations with `php artisan`.
- Typical local environment is XAMPP + MySQL.
- Frontend tooling may use `npm run dev` and `vite`.
- Preserve Laravel app structure and configuration when modifying these apps.

### Legacy PHP modules

For folders like `risk_assessment`, `tbm`, `board`, `near_miss`, `Fleet`, `calendar`, and `view`:
- Prefer procedural PHP, plain HTML, CSS, and JavaScript.
- Keep logic and data separation within each module folder.
- Respect existing MySQL schema and naming conventions (`*_master`, `*_mapping`).
- Use README docs in each folder for installation and integration details.

## Preferred coding approach

- Keep code simple and practical.
- Avoid unnecessary frameworks unless explicitly requested.
- Favor reusable modules and folder-local logic.
- Maintain readability over abstraction.
- Preserve Windows/XAMPP path conventions unless asked to normalize.

## Recommended behavior

- When asked to change code, preserve existing platform-specific path conventions unless the user asks to normalize or refactor them.
- Prefer using existing README guidance rather than inventing new build or deployment steps.
- Keep fixes and enhancements localized to the relevant app folder.
- If a change affects multiple apps, explicitly note which subproject(s) are impacted.

## Example prompts to follow

- "Update `hr-server/routes/web.php` to add a new route for the HR dashboard."
- "Fix the SQL injection vulnerability in `near_miss/write.php`."
- "Improve the legacy PHP form validation in `board/index.php` while keeping the existing auth integration."
- "Add a workspace note explaining that `hr-server` uses Laravel + XAMPP on Windows."
- "Design a reusable hazard-mapping schema for `risk_assessment` and `tbm`."
