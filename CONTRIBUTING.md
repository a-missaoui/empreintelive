# Contributing to EMPREINTE Live

Thanks for your interest in contributing! This document explains how to set up
the project, the conventions we follow, and how to submit changes.

## Ground rules

- This app **extends the official Nextcloud Calendar without forking or modifying it**.
  Contributions must rely only on **public Nextcloud OCP APIs and events** — no patching
  of the Calendar app and no new hard dependencies on internal APIs.
- Keep sensitive data (OAuth `client_secret`, tokens) **server-side only**. Never expose
  secrets to the browser or commit them to the repository.

## Development setup

The full environment setup, architecture overview, and OAuth configuration are documented in:

📖 [DOCUMENTATION.md](DOCUMENTATION.md)

Quick start:

```bash
# Start Nextcloud and mount the app
docker compose up -d

# Install and enable the OFFICIAL Calendar app
docker compose exec --user www-data nextcloud php occ app:install calendar
docker compose exec --user www-data nextcloud php occ app:enable  calendar

# Enable this app
docker compose exec --user www-data nextcloud php occ app:enable empreintelive

# Build the frontend
cd empreintelive && npm install && npm run build
```

## Coding conventions

- **PHP**: PSR-12, namespace `OCA\EmpreinteLive`.
- **Frontend**: Vue 3, linted with ESLint (`@nextcloud/eslint-config`).
  The toolchain requires **Node.js 22+**:

  ```bash
  nvm use 22
  npm run lint        # must exit 0
  ```

## Tests

All changes should keep the test suites green.

```bash
# Frontend (Vitest)
cd empreintelive && npm run test:unit

# Backend (PHPUnit)
phpunit --configuration phpunit.xml
```

## Submitting changes

1. Create a feature branch from the default branch.
2. Make your change, adding tests when possible.
3. Ensure `npm run lint`, Vitest, and PHPUnit all pass.
4. Update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]` when relevant.
5. Open a pull request with a clear description of the change and its motivation.

## License

By contributing, you agree that your contributions are licensed under the
**GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**, the same
license as the project.
