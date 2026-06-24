# Meloton Agent Workflow & Guidelines

Welcome to the **Meloton** project! This document is created for future AI agents to understand the architecture, design system, and core rules of this codebase. Always read this file before making major changes.

## 1. Project Architecture

- **Stack:** Vanilla PHP 8+, MySQL, Vanilla CSS/JS.
- **Routing:** Handled via `.htaccess` (rewriting URLs like `/home` to `user/home.php`, `/console` to `console/index.php`).
- **Directories:**
  - `user/`: All user-facing pages (dashboard, watch videos, deposit, withdraw, etc.).
  - `console/`: Admin dashboard pages.
  - `auth/`: Login, register, logout logic and views.
  - `api/`: AJAX endpoints returning JSON.
  - `partials/`: Reusable layout parts (`header.php`, `footer.php`).
  - `assets/css/`: Contains the global stylesheet `app.css`.
  - `config/`: Database connection (`database.php`) and utility functions (`bootstrap.php`, `helpers.php`).

## 2. Design System: Neo-brutalism

The entire user interface is built on the **Neo-brutalism** design system. You must adhere to these aesthetics strictly:
- **No Tailwind CSS**: All styling is done via inline CSS or classes from `app.css`.
- **Colors**: Defined in `app.css` (`:root`). We use stark, vibrant colors (e.g., `var(--yellow)`, `var(--brand)` (hot pink), `var(--mint)`, `var(--lavender)`) paired with pure black (`var(--ink)`).
- **Borders & Shadows**: Elements use thick borders (`border: 2.5px solid var(--ink)`) and hard shadows (`box-shadow: 3px 3px 0 var(--ink)`). **Do not use soft, blurry box-shadows.**
- **Typography**: The font family is `'Nunito', sans-serif`. Use heavy font weights (`800`, `900`) for headings and important text.
- **Compact & Minimalist**: The layout should prioritize horizontal swiping (CSS scroll-snap) and grid layouts over long vertical scrolling lists.

### Trusted Neo-Brutalism (Transactional Pages)
For pages dealing with finances and balances (e.g., `deposit.php`, `withdraw.php`, `history.php`, `checkin.php`), use the **Trusted Neo-Brutalism** variant:
- **Palette**: Deep Blue/Navy (`var(--blue)` / `#1e3a8a`), Emerald Green (`var(--green)` / `#059669`), and Gold/Yellow (`var(--yellow)`). Avoid overly playful colors like Pink or Mint here.
- **Copywriting**: Use formal, banking-like terminology (e.g., "Saldo Beli" instead of "Saldo Deposit", "Top Up Saldo Beli" instead of "Deposit").
- **Layout**: Use card styles resembling credit cards/bank cards for balances. Use compact grids for nominal selections.

## 3. Icons: Phosphor Icons

**CRITICAL RULE:** Do NOT use native emojis (e.g., 💰, 🎬, 💸) for the UI anymore.
- We use **Phosphor Icons** via CDN (included in `partials/header.php`).
- Always use the **Bold** (`ph-bold ph-*`) or **Fill** (`ph-fill ph-*`) weights because they complement the thick borders of Neo-brutalism.
- Example: `<i class="ph-bold ph-wallet"></i>` or `<i class="ph-fill ph-check-circle"></i>`.

## 4. Database & Global Rules

- **Database Access:** When you need to interact with the local MySQL database in Laragon, the `mysql` command is **not** in the system PATH by default. You must use the following PowerShell one-liner to query the database:
  ```powershell
  $mysqlCmd = (Get-ChildItem -Path C:\laragon\bin\mysql\ -Filter mysql.exe -Recurse | Select-Object -First 1).FullName; & $mysqlCmd -u root -e "USE tonton; SHOW TABLES;"
  ```
- **Drachin Feature is DELETED:** Do not recreate or reference the "Drachin" (Drama China) feature. It was intentionally purged from the DB and codebase.
- **Session & Globals:** `$user` array is globally available in protected pages if `auth/guard.php` is included. Database connection is `$pdo`. Global settings are fetched using `setting($pdo, 'key', 'default_value')`.

## 5. Development Workflow

1. Always review the code logic before implementing. Use `bootstrap.php` utility functions (like `format_rp()`, `fmt_short()`) when displaying currencies or numbers.
2. If the user asks for a UI tweak, ensure you maintain the `border`, `box-shadow`, and `border-radius` combination that defines the app's look.
3. Push changes frequently when completing logical milestones (using `git add .`, `git commit`, `git push`).
