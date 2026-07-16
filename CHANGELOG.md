# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 – 2026-07-13

Initial public release.

### Added
- OAuth 2.0 connection to an EMPREINTE Live account, with an explicit consent screen and server-side token management. The app is a public OAuth client secured by PKCE (no client secret), so no administrator configuration is required.
- Dedicated **EMPREINTE Live** app in the top app menu to create, list, join, and delete video meetings.
- Automatic creation of the calendar event when a meeting is created.
- Two-way synchronization with the calendar: trashing, deleting, or editing an event updates the associated meeting.
- Reconciliation of the "EMPREINTE Live" calendar on login (back-fills missing meetings, hides meetings that belong to another account).
- Participant autocomplete (Nextcloud users and contacts).
- Organizer-only meeting link, reserved for the creator.
- PHPUnit (backend) and Vitest (frontend) test suites.
