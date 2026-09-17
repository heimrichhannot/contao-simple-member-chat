# Phase 3b — Frontend refinements of `heimrichhannot/contao-simple-member-chat`

Phases 1, 2 and 3a are complete and committed, including a review fix
(`src` only on embedded frames). You are implementing phase 3b: the
remaining frontend behaviour plus the defects found in the browser review
of 3a. Phase 4 (backend module, badge, URL fallback, README) follows.

## Read first, in this order

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md` — sections 5.1 (mobile table), 5.2, 5.3a, 5.6, 5.6a,
   5.7 and decisions 5, 6, 8, 9, 15. Section 15, "Erkenntnisse aus
   Phase 3a", lists the review findings you must fix; each row names the
   expected change.
3. `.docs/build/reports/phase-3a-frontend-core.md` and the "Phase 3a"
   section of `.docs/build/DECISIONS.md` — the cursor rules, data
   attributes and view contract you must preserve.
4. `.docs/build/PHASES.md`.

Then read: `assets/js/member_chat.js`, `assets/css/member_chat.css`, all
templates under `contao/templates/`, `ChatFrameController`,
`ChatActionController`, `ChatReader`, `ReadTracker`, `ParticipantGateway`,
`ConversationGateway::listForMember`, `ContactGateway::search`,
`ChatContextFactory`, `.docs/build/verify-phase-3a-http.sh`.

## Scope of this phase

### Fixes from the 3a review (concept section 15)

1. **Fragment locale.** Every frame, stream and action response renders in
   the language of the page whose ID the request carries, not in the
   request's `Accept-Language`. Verify how Contao 5.7 derives the frontend
   locale (`PageModel::language`/`rootLanguage`, request `_locale`,
   `LocaleSwitcher` or translator locale) and set it before rendering.
   Add a curl case to the verification script that requests a fragment
   with `Accept-Language: de` on the English page and asserts English
   labels.
2. **Participant `tstamp` churn.** `ParticipantGateway::markRead` must bump
   `tstamp` only when `lastReadMessageId` or `muted` actually changes.
   Activity-only updates (`lastReadAt`, `lastPageId`) do not touch
   `tstamp` and are throttled: skip the write when `lastReadAt` is younger
   than a configurable number of seconds (new option
   `polling.activity_throttle`, default 30). `changedAt` must then stay
   constant across idle polls; prove it with an integration test and by
   observing a stable `X-Chat-Since` across two idle list polls in the
   verification script.
3. **Enter to send.** Decide touch versus keyboard by
   `matchMedia("(pointer: coarse)")` only; drop the `maxTouchPoints` check.
4. **Dynamic height.** Replace the static `100dvh` rule with a script that
   sets the conversation container height to `visualViewport.height`
   minus the container's actual top offset (and minus
   `--member-chat-offset-top` if set), recomputed on `visualViewport`
   `resize`/`scroll`, on `turbo:load` and when the frames change size.
   This is also the iOS keyboard handling from decision 9: only active in
   the single-column layout and only when `window.visualViewport` exists;
   the static rule remains as the no-JavaScript fallback. Keep the message
   list scrolled to the bottom through height changes when it was at the
   bottom.
5. **Contact search UX.** Word-prefix matching in `ContactGateway::search`
   (`field LIKE 'q%' OR field LIKE '% q%'`, escaped), an explicit "no
   contacts found" line when the query met the minimum length and nothing
   matched, and no output at all below the minimum length.
6. **Empty state** only from the two-column breakpoint upwards (CSS).

### Load more (concept 5.3a, decision 6)

- Messages: "Older messages" button at the top of `#chat-messages` with
  `?before=<oldest loaded id>`; the messages route answers `before` with a
  Turbo Stream that `prepend`s the older page and replaces or removes the
  button; `IntersectionObserver` triggers it automatically, a data
  attribute on the frame disables auto-loading; button disabled while a
  request is running; scroll position preserved (`overflow-anchor` or
  explicit scroll correction, verify in the browser what actually holds).
  Gateway support for `before` exists; add the `X-Chat-Before` header and
  keep the `after` cursor untouched.
- Conversations: "More conversations" button below the list with
  `?before=<lastMessageAt>,<id>` (the gateway already takes both);
  Turbo Stream `append`; after loading more, the list stays in incremental
  mode and the client-side sort from 3a must keep appended older items
  below newer ones.

### Mute toggle (concept 5.1, decision 8)

- Route `POST /_member_chat/conversations/{uuid}/mute` with
  `_token_check: true`, body `muted=0|1`, calls `MuteService`, answers
  with a Turbo Stream replacing the small frame `#chat-mute` in the
  conversation header. Button as `<button aria-pressed>` with translated
  labels; the header keeps its static render, only the toggle is a frame
  that is never polled. Muted conversations show the muted icon in the
  list (already rendered) and count no unread messages (already done).

### Time display (decision 5)

- Client-side relative time via `Intl.RelativeTimeFormat` and
  `Intl.DateTimeFormat`, language from the `lang` attribute, applied on
  load and after every frame or stream render; the server text stays as
  fallback. Day separators rendered server-side between messages of
  different days ("Today", "Yesterday", weekday, else date), as an element
  with `role="separator"` and text, translated. Make sure the incremental
  `append` stream also emits a separator when the day changed since the
  last rendered message (the client passes nothing; compute from the
  previous message in the window, or emit the separator and let the
  client drop a duplicate — decide and record).

### Scroll behaviour and accessibility (concept 5.6a)

- After incoming messages: scroll to the bottom if the list was at the
  bottom, otherwise show a "New messages" control that scrolls down when
  activated; set `data-chat-at-bottom` / `data-chat-has-new` on the frame
  for CSS.
- `aria-live` off during `prepend` of older messages and back on
  afterwards; the load-more and mute buttons keyboard-reachable; the
  compose error linked via `aria-describedby` (exists, verify it survives
  the stream replacement); focus handling for the mute toggle after its
  stream replaces the frame.

### Styles and customisation (concept 5.7)

- Complete the custom property set from concept 5.7 and use it
  consistently; add properties for the load-more and new-messages
  controls. Document the overridable grid rule in the CSS comment (exists).
- Ensure all new elements use `attrs()` with `mergeWith(...)` and live in
  the partials or in new blocks (`load_more`, `mute`, `day_separator`,
  `new_messages`), so projects can override them.

### Verification

- Unit tests for the new view logic (day separators, before cursors, mute
  view state, word-prefix escaping), integration tests for the `tstamp`
  rule and `before` windows, and the extended curl script covering locale,
  stable `X-Chat-Since` across idle polls, `before` on both routes and
  the mute route. Run PHPUnit, ECS, PHPStan, Rector, `lint:twig`, the
  webpack build via the existing `.docs/build/phase-3a-webpack.cjs` and
  the verification script.
- Browser behaviour: the demo is reachable at
  `https://127.0.0.1:<host https port>` (see `ddev describe`), the root
  page has no domain binding. You cannot log in yourself; describe
  precisely which manual checks remain and write them into
  `.docs/BROWSER_CHECKLIST.md` (concept 12b) with expected results, so a
  reviewer can run them in five minutes: send, incoming message with and
  without being at the bottom, older messages, more conversations, mute
  toggle, relative time, day separator, locale, mobile height with the
  keyboard open, Enter on desktop versus touch.

## Rules that override everything else

- Verify every Contao, Symfony, Twig and Turbo API in `vendor/` and the
  host's `node_modules/@hotwired/turbo` before use; append a "Phase 3b"
  section to `DECISIONS.md` with evidence for the locale mechanism, the
  scroll anchoring approach, `IntersectionObserver` usage and the
  `tstamp` rule.
- Never claim a check ran; unrun checks are "not verified".
- English everywhere except translation files; attributes for every
  registration; no trivial wrappers; `final` by default; templates only
  under `contao/templates/`; keep the data attributes and cursor rules of
  3a unless a finding above changes them, and record every change.
- Do not modify host application code; demo content changes must be
  listed. Run PHP tools inside DDEV; fix findings without lowering the
  PHPStan level.
- Commit in small, meaningful steps. Do not start phase 4.

## Report

`.docs/build/reports/phase-3b-frontend-refinements.md`: files by concept
section, decisions and non-verifiable items, exact commands and output for
all checks including the verification script, the browser checklist
location, open questions for phase 4, and anything in the concept that
turned out wrong or incomplete.
