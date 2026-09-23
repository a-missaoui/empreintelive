# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 – 2026-09-18

### Added
- **Present a document in a meeting, straight from Files.** A "Present in a meeting" action in the Files context menu of a PDF, PPTX or DOCX creates an EMPREINTE Live meeting with the document already loaded in it, converted into slides and ready to present. The calendar event is created and participants are invited, as for any meeting.
- **Participants suggested from the document.** People the document is already shared with are pre-selected. More can be added by searching Nextcloud users and contacts, or by typing any email address, including for people without a Nextcloud account.
- **Documents panel beside the meeting.** The meeting is embedded in Nextcloud with a documents panel next to it. The document's folder is associated with the meeting: it can be browsed with previews, and more documents can be sent to the meeting or removed from it. The Nextcloud file itself is never modified.
- **Share links from the panel**, for one file or a whole folder, with the standard options: read-only or editable, password, expiration. A folder link gives every participant access, including people without a Nextcloud account.
- **Meeting recordings saved to Nextcloud Files.** When a local recording is stopped in a meeting opened from Nextcloud, the file is saved in the meeting's folder instead of being downloaded, with its progress shown above the meeting. Large recordings are sent in chunks. If the meeting's folder is read-only for the user, the recording goes to their own `EMPREINTE Live/<meeting title>` folder. If saving fails, the recording is downloaded to the device, so it is never lost. If the meeting is opened outside Nextcloud, the recording is downloaded as before.
- **English and French translations.** The interface follows each user's language; dates and file sizes follow their locale.
- **Clearer meeting list.** Meetings in progress are marked and listed first, then upcoming ones, then past ones. Secondary actions are grouped in a menu, and deleting a meeting asks for confirmation.

### Fixed
- **Lazy-loaded components were broken when the app is installed in `custom_apps/`.** The bundler assumed the app is always served from `/apps/<id>/js/`, while `custom_apps/` installations are served from `/custom_apps/<id>/js/`. The public path is now resolved at runtime.
- **A document at the root of a user's files no longer associates their whole personal space with a meeting.** The root folder is never associated, and such a link created earlier is removed automatically.

### Compatibility
- Verified on Nextcloud 32, 33 and 34, including an upgrade from 1.0.1 with no reinstallation and no administrator configuration.

### Notes
- Access to files always goes through the current user's own Nextcloud permissions: a user who may only read a folder can neither present its documents nor share them, and a user with no access to the folder sees nothing of it.

## 1.0.1 – 2026-07-22

### Fixed
- Connecting an EMPREINTE account failed with `invalid_request` when no `redirect_uri` was configured, because the authorization request was sent with an empty `redirect_uri`. The `redirect_uri` is now derived automatically from the current Nextcloud instance URL (`<host>/apps/calendar/empreinte-callback`), so the connection works out of the box with no administrator configuration. It remains overridable via `occ config:app:set empreintelive redirect_uri`.

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
