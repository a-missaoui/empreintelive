# Security Policy

## Supported versions

Security fixes are provided for the latest released version of
**EMPREINTE Live**.

| Version | Supported |
| ------- | --------- |
| 1.0.x   | ✅        |

## Reporting a vulnerability

If you discover a security vulnerability, please report it **privately**.

- **Do not** open a public GitHub issue for security problems.
- Email the maintainers at **security@empreinte.live** with:
  - a description of the vulnerability,
  - steps to reproduce it,
  - the affected version, and
  - any potential impact you have identified.

You can expect an initial acknowledgement within a reasonable delay. We will
investigate, keep you informed of the progress, and coordinate a fix and
disclosure timeline with you.

## Scope

This application handles OAuth authentication against EMPREINTE Live. It is a
**public OAuth client** secured by **PKCE**, so there is no client secret to store.
OAuth tokens are managed **server-side** and are never exposed to the browser.
Reports related to token exposure, authentication bypass, or calendar data leakage
are especially appreciated.

Thank you for helping keep EMPREINTE Live and its users safe.
