# Learning App

A learning flashcard web application.

> **Status:** Step 2 — project structure only.
> No application logic, API endpoints, or database tables exist yet.

## Technology

- PHP (server-side)
- Apache (web server)
- MySQL / MariaDB-compatible database
- HTML, CSS, vanilla JavaScript
- No React, no Vue, no Node.js, no frontend framework

## Project Structure

```text
learning-app/
├── public/                  # Web root (Apache DocumentRoot)
│   ├── index.php            # Entry point / front controller
│   ├── api/                 # API endpoint scripts
│   └── assets/
│       ├── css/app.css      # Global stylesheet
│       ├── js/app.js        # Global client-side script
│       └── icons/           # Static icon assets
├── src/                     # Application code (NOT web-accessible)
│   ├── config/              # Configuration and DB connection settings
│   ├── helpers/             # Shared helper functions
│   └── services/            # Business logic and data access services
├── database/                # SQL schema, migrations and seed scripts
├── docs/                    # Project documentation
├── .github/                 # GitHub workflows and repository metadata
├── .gitignore
└── README.md
```

## Folder Purpose

| Path | Purpose |
| --- | --- |
| `public/` | The only directory exposed by Apache. Everything here is reachable by URL, so it must contain no secrets. |
| `public/index.php` | Main entry point, loaded by Apache for site requests. |
| `public/api/` | Server-side API endpoints (JSON over HTTP) consumed by the frontend. |
| `public/assets/` | Static files served directly: CSS, JavaScript and icons. |
| `src/` | Application source code, kept outside the web root so it cannot be requested directly. |
| `src/config/` | Database credentials, environment settings and application configuration. |
| `src/helpers/` | Small, reusable, stateless utility functions. |
| `src/services/` | Business logic and data access (e.g. deck/card operations). |
| `database/` | SQL schema, migration and seed scripts for the flashcard database. |
| `docs/` | Developer and project documentation. |
| `.github/` | GitHub-specific metadata such as CI workflows. |

## Local Setup

Not configured yet. Web server configuration, database connection and
routing will be added in a later step.
