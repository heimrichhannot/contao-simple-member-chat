# Changelog

## 0.1.1 — 2026-09-21

- The conversation list is now styleable: every part of a list item carries a
  `member-chat__` class, and the open conversation is marked with
  `member-chat__list-item--current` and `aria-current="page"`. The marker is
  rendered server-side, so polls and history loads keep it.
- New custom properties `--member-chat-current-bg`, `--member-chat-unread-bg`
  and `--member-chat-unread-fg` for the default colours.
- Documentation screenshot.

## 0.1.0 — 2026-09-21

First release:

- Private 1:1 member text conversations, provider-controlled contact search,
  UUID links, throttled activity tracking and muted unread counts.
- Responsive Contao content element with Turbo polling, history loading,
  accessible controls and overridable Twig templates/styles.
- Site-wide unread badge, published-page URL fallback and backend moderation
  with last-message repair.
- Member-deletion anonymization and stable integration events/read APIs.
- Unit, database and DDEV HTTP verification; documented manual device checks.
- Optional PWA push integration inside this bundle: queued delivery, current
  recipient filtering, per-recipient absolute deep links, fixed configuration
  selection and message excerpts disabled by default. No PWA requirement for
  normal chat installations.
