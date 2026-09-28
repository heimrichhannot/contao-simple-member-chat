# Changelog

## Unreleased

- Typing in the contact search no longer moves the caret to the start of the
  field. A response replaces the whole search frame, so the client now restores
  the typed value and the caret position along with the focus.
- Every conversation in the list shows when its newest message arrived, as a
  `member-chat__time` element that the client rewrites to a relative time.
  `--member-chat-time-fg` sets its colour.
- A list entry now lays its parts out explicitly: partner and time on the first
  line, the message excerpt and the status on the second, the avatar beside
  both. The rules are unlayered, so a broad theme rule such as
  `a { display: flex }` no longer pulls the entry onto a single line.

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
