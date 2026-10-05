# Changelog

## [Unreleased]

- Changed: A push notification is now titled "New message from" and the sender's
  name instead of the bare name, in the language of the page the recipient opens.

## [0.1.2] - 2026-09-28

- Fixed: Typing in the contact search no longer moves the cursor back to the
  start of the search field.
- Added: Every conversation in the list shows when its last message arrived.
- Changed: In the conversation list, the message excerpt sits on its own line
  below the partner name.

## [0.1.1] - 2026-09-21

- Added: Every part of a conversation list item carries a `member-chat__` class,
  and the open conversation is marked with `member-chat__list-item--current` and
  `aria-current="page"`. The marker is rendered server-side, so polls and
  history loads keep it.
- Added: Custom properties `--member-chat-current-bg`, `--member-chat-unread-bg`
  and `--member-chat-unread-fg` for the default colours.
- Added: Documentation screenshot.

## [0.1.0] - 2026-09-21

First release:

- Added: Private 1:1 member text conversations, provider-controlled contact
  search, UUID links, throttled activity tracking and muted unread counts.
- Added: Responsive Contao content element with Turbo polling, history loading,
  accessible controls and overridable Twig templates/styles.
- Added: Site-wide unread badge, published-page URL fallback and backend
  moderation with last-message repair.
- Added: Member-deletion anonymization and stable integration events/read APIs.
- Added: Unit, database and DDEV HTTP verification; documented manual device
  checks.
- Added: Optional PWA push integration inside this bundle: queued delivery,
  current recipient filtering, per-recipient absolute deep links, fixed
  configuration selection and message excerpts disabled by default. No PWA
  requirement for normal chat installations.
