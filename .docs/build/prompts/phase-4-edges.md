# Phase 4 — Edges of `heimrichhannot/contao-simple-member-chat`

Phases 1, 2, 3a, 3b and 3c are complete and committed; the five browser
findings from 3b are fixed and verified. You are implementing phase 4: the
back end, the site-wide unread badge, the conversation URL policy, the
Messenger preparation and the documentation. Phase 5 (the PWA bridge
package) is a separate repository and is not part of this phase.

## Read first, in this order

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md` — sections 5.5, 7, 9, 12, 12b and the decisions
   in 13 (notably 3, 4 and 8). Section 15 collects what phases 1 to 3c
   discovered; the "Erkenntnisse aus Phase 3c" block lists the new public
   contract items that the README must cover.
3. `.docs/build/reports/phase-*.md` and `.docs/build/DECISIONS.md` — the
   cursor rules, data attributes, view contract and API evidence you must
   preserve and document.
4. `.docs/build/PHASES.md` and `.docs/BROWSER_CHECKLIST.md`.

Then read the code you build on: `ChatPageUrlGenerator`,
`ParticipantGateway`, `ConversationGateway`, `ChatContextFactory`,
`MessageSentEvent`, `MessageGateway`, the DCA files and `config/services.yaml`.

## Scope of this phase

### Conversation URL policy (concept 7, decision 4)

Replace the phase 3a placeholder with the full `ConversationUrlGenerator`
described in concept section 7:

- `forConversation(Conversation, int $memberId): ?string` and
  `listPage(int $memberId): ?string`.
- Resolution order: the member's `tl_chat_participant.lastPageId`, then the
  `memberChatPage` field of the root page, then `null`. With several root
  pages use the first published root that has the field set; verify how to
  enumerate published roots in Contao 5.7 and record it.
- It is the only place that builds conversation URLs: badge, push deep
  links, the redirect after starting a contact, list links. Keep the
  existing behaviour where the content element already knows its page —
  that path must not regress or add queries per row.
- Guard against a `lastPageId` pointing at a deleted, unpublished or
  non-regular page.

### Unread badge (concept 5.5)

- Route `GET /_member_chat/unread` returning a small frame with the
  viewer's unread count (`ParticipantGateway::unreadCount` exists and
  already excludes muted conversations), linking to the chat page via
  `ConversationUrlGenerator::listPage`.
- Twig runtime function `member_chat_unread_badge(attributes = {})` with
  `#[AsTwigFunction]`, rendering the frame with the server-side initial
  value and the badge poll interval; nothing at all when no member is
  logged in; an empty but present frame at count zero so polling keeps it
  current. No function for the bare number — concept 5.5 explains why.
- The badge frame is polled by the existing script (`data-chat-poll`) and
  must work on pages that contain no chat element, so its markup carries
  everything the script needs. Check whether the Encore entry has to be
  activated for such pages and solve it without forcing the whole chat
  CSS on every page, or document the requirement in the README.
- Accessibility per concept 5.6a: translated `aria-label` with the count,
  `aria-live="off"` on the frame.

### Back end (concept 9)

- `$GLOBALS['BE_MOD']['accounts']['member_chat']` with the conversation and
  message tables, translations for the module and its labels.
- `tl_chat_conversation`: list view, label showing both members and the
  time of the last message, `notCreatable`, `notEditable`, operations
  `children` and `delete`.
- `tl_chat_message`: child list, label with author, time and an excerpt,
  `notCreatable`, `notEditable`, operation `delete`.
- Deleting the last message must repair `lastMessageId`/`lastMessageAt` of
  the conversation through an `ondelete` callback registered with
  `#[AsCallback]`; cover it with an integration test.
- Resolve member names for the labels through `ContactResolver` and avoid
  one query per row.
- `tl_chat_participant` gets no back end module.

### Messenger preparation (concept 8.2, decision 3)

The bridge package is phase 5 and lives elsewhere. This phase only makes
the chat side consumable:

- Confirm that `MessageSentEvent`, `ConversationCreatedEvent`,
  `MessagesReadEvent`, `MessageGateway::find()`, `Conversation` with
  `uuid`, the mute flag and `lastReadAt` form a stable public API, and
  mark them as such in code comments and the README.
- Add whatever small accessor is genuinely missing for a listener to
  answer "which recipients want a push for this message" without touching
  internals: recipients are in the event, but mute state and last read
  time currently require a gateway call. Decide whether to expose a narrow
  read-only service for that and record the reasoning.
- Do not add a Messenger message, handler or any dependency on the PWA
  bundle.

### Documentation (concept 12b, phase 3 reports)

- `README.md` covering: what the bundle does and the deliberate
  non-goals from concept 1.1; requirements (PHP 8.4, Contao 5.7, Encore,
  the two Turbo entries, the Android viewport meta
  `interactive-widget=resizes-content`); installation and migration;
  Contao setup (chat page with the content element, `memberChatPage` on
  the root page, member groups for the providers); the complete
  configuration tree from concept 12 with defaults and units, including
  `polling.activity_throttle` in seconds and the page-tracking delay it
  causes; writing a custom contact provider against
  `ContactProviderInterface` with `Viewer` and `Contact`; the public view
  contract and template blocks from concept 5.7; the documented custom
  properties and the overridable grid rule; the events and the stable API
  for integrations; the privacy behaviour from concept 10 including the
  deletion paths that are not covered; and the limitations the reports
  recorded.
- A `CHANGELOG.md` entry for the first release.
- Move `.docs/build/verify-host.php` out of the build folder or replace it
  with an integration test, as concept section 15 noted for this phase.

## Rules that override everything else

- Verify every Contao, Symfony and Twig API in `vendor/` before use;
  append a "Phase 4" section to `DECISIONS.md` with evidence, especially
  for the back end module registration, the `ondelete` repair, published
  root page lookup and the Twig function registration.
- Never claim a check ran; unrun checks are "not verified".
- English everywhere except translation files; attributes for every
  registration; no trivial wrappers; `final` by default; templates only
  under `contao/templates/`; `src/Model/` stays reserved for Contao
  Active Record classes.
- Preserve every cursor rule, data attribute and view contract from
  phases 3a to 3c unless a change is required and recorded.
- Do not modify host application code. Demo content is allowed and must be
  listed. Run PHP tools inside DDEV; fix findings without lowering the
  PHPStan level.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan, Rector, `lint:twig`, the webpack build and
  `.docs/build/verify-phase-3a-http.sh`; extend the HTTP script with the
  badge route and a back end smoke check if feasible.
- The demo is reachable at `https://127.0.0.1:<host https port>`
  (`ddev describe`); the demo root page has no domain binding so the
  browser tooling can reach it. You cannot log in through a browser
  yourself; put anything that needs a browser into
  `.docs/BROWSER_CHECKLIST.md` with expected results.
- Commit in small, meaningful steps.

## Report

`.docs/build/reports/phase-4-edges.md`: files by concept section,
decisions and non-verifiable items, exact commands and output for every
check, the demo content you created, what remains for phase 5, and
anything in the concept that turned out wrong or incomplete.
