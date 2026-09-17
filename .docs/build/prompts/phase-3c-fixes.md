# Phase 3c — Frontend fixes from the phase 3b browser review

Phases 1, 2, 3a and 3b are complete and committed. A browser review of 3b
found five defects. This phase fixes exactly those and nothing else.
Phase 4 (backend module, badge, URL fallback, README) follows separately.

## Read first

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md`, section 15, "Erkenntnisse aus Phase 3b" — the table
   at the end lists the five findings with the observation and a suggested
   direction. That table is the scope of this phase.
3. `.docs/build/reports/phase-3b-frontend-refinements.md` and the
   "Phase 3a"/"Phase 3b" sections of `.docs/build/DECISIONS.md` — the
   cursor rules, data attributes and view contract you must preserve.
4. `assets/js/member_chat.js`, `assets/css/member_chat.css`,
   `contao/templates/member_chat/`, `ChatFrameController`,
   `ConversationGateway::listForMember`, `.docs/BROWSER_CHECKLIST.md`.

## The five fixes

1. **Recalculate the height on document scroll.** `resizeViewport()`
   currently runs on `visualViewport` resize/scroll, `window.resize`,
   Turbo events and the mutation/resize observers. Ordinary page scrolling
   fires none of them, so a chat placed below other content keeps the
   height it had at render time. Measured on the demo page: root top
   377 px, height 435 px, and after scrolling the root to the top of the
   viewport the height stayed 435 px with roughly 400 px unused below.
   Add a passive `scroll` listener (document and any scrolling ancestor)
   throttled through the existing `requestAnimationFrame` batching, or
   change the strategy if you find a more robust one; justify the choice
   in `DECISIONS.md`. Keep the no-JavaScript CSS fallback and the
   at-bottom behaviour.
2. **Protect the message list height.** In the demo theme the header takes
   191 px and the compose area 145 px of a 435 px container, leaving 99 px
   for the history. Give the message list a sensible minimum height and
   make the bundle's own header compact and robust against large theme
   fonts: single-line partner name with ellipsis, the mute control not
   forcing a second row at 375 px. Do not fight the project's theme with
   `!important`; keep everything overridable through the documented custom
   properties and add properties for the new values.
3. **Make the mute state visible.** `aria-pressed` toggles correctly, but
   the label stays "Mute conversation" and no CSS reacts to the pressed
   state, so sighted users cannot tell whether a conversation is muted.
   Render the label from the current state ("Mute conversation" versus
   "Unmute conversation", both translated) and add a visible pressed
   style. Keep `aria-pressed`, the focus restoration and the existing
   frame and route contract.
4. **Do not let a hidden tab strand the history loader.** In `loadMore()`
   the `await new Promise(resolve => requestAnimationFrame(resolve))` sits
   inside the `try`. If the tab is hidden at that moment the promise never
   settles, so neither the rest of the `try` nor the `finally` runs: the
   frame keeps `aria-live="off"` and `state.loading === true` until the
   tab becomes visible again, which also stops that frame's polling. Move
   the wait out of the critical section or make it visibility-safe
   (resolve on `visibilitychange` as well, or drop the wait and restore
   `aria-live` directly). Add a unit-level or documented manual check.
5. **Stop re-rendering the top list item on idle polls.** `since` is
   inclusive, so every idle poll returns the conversation whose
   `changedAt` equals the cursor; measured two stream renders per poll
   with no content change. Either answer `204 No Content` when nothing
   changed beyond what the client already has, or move to an exclusive
   cursor with an explicit tie-breaker. The inclusive cursor exists
   because second-resolution timestamps could otherwise lose same-second
   activity (see the Phase 1 decision) — whichever route you take must not
   reintroduce that loss. Update the integration test and the HTTP
   verification accordingly.

## Rules

- Do not add features. Anything outside the five findings belongs to a
  later phase; if you spot something new, record it in the report instead
  of fixing it.
- Verify every API in `vendor/` and the host's `node_modules/@hotwired/turbo`
  before use; append a "Phase 3c" section to `DECISIONS.md`.
- Never claim a check ran; unrun checks are "not verified".
- English everywhere except translation files; attributes for every
  registration; no trivial wrappers; `final` by default; templates only
  under `contao/templates/`; preserve the data attributes, cursor rules
  and view contract unless a fix changes them, and record every change.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan, Rector, `lint:twig`, the webpack build via
  `.docs/build/phase-3a-webpack.cjs` and `.docs/build/verify-phase-3a-http.sh`.
  Fix findings without lowering the PHPStan level.
- The demo is reachable at `https://127.0.0.1:<host https port>`
  (`ddev describe`); the demo root page has no domain binding so that the
  browser tooling can reach it. Do not modify host application code.
- Update `.docs/BROWSER_CHECKLIST.md` where a fix changes the expected
  result, and mark the rows that the 3b review already confirmed so the
  next reviewer only re-checks what changed.
- Commit in small, meaningful steps. Do not start phase 4.

## Report

`.docs/build/reports/phase-3c-fixes.md`: one section per finding with the
change and the evidence that it is fixed, decisions and non-verifiable
items, exact commands and output for every check, anything new you noticed
but did not fix, and open questions for phase 4.
