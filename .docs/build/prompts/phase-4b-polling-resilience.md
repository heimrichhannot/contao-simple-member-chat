# Phase 4b — Polling resilience

Phases 1 to 4 are complete and committed. A browser review of phase 4
found two defects in the polling lifecycle of `assets/js/member_chat.js`.
They were introduced earlier than phase 4 but only surfaced now. This
phase fixes exactly these two and nothing else.

## Read first

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md`, section 15, "Erkenntnisse aus Phase 4" — the table
   states both defects, how they were reproduced and what they cause.
   Section 5.2 states the intended polling behaviour.
3. `.docs/build/DECISIONS.md` (phases 3a to 4) for the cursor rules and
   data attributes you must preserve.
4. `assets/js/member_chat.js`, especially `schedule()`, `poll()`,
   `discover()`, `start()` and the `turbo:before-cache`, `turbo:load` and
   `visibilitychange` handlers.

## The two fixes

1. **Polling must survive `turbo:before-cache` without a following
   `turbo:load`.** Reproduced: on a freshly loaded page three polls
   occurred within nine seconds; after dispatching `turbo:before-cache`
   with no navigation, zero polls occurred in ten seconds, and neither
   `pageshow` nor `visibilitychange` recovered it. Only a full page load
   did. The handler sets `active = false`, disconnects the observers and
   clears the frame map, and only `start()` restores them. Turbo also
   fires this event on `pagehide`, so a back/forward restore from the
   browser cache, or an embedded or mobile browser hiding the page, leaves
   the chat permanently frozen with no indication to the user.
   Make the lifecycle recoverable: restart on `pageshow` (including
   `event.persisted`), and consider `visibilitychange` to visible and
   `turbo:render` as additional restart signals. Restarting must be
   idempotent — no duplicated timers, observers or listeners, and no
   duplicated requests when `turbo:load` also fires. Keep the existing
   teardown for real navigations so a cached page does not keep polling in
   the background.
2. **A visibility change must not restart the full interval.** Reproduced:
   a badge frame with the default 30 s interval polled zero times in 38 s
   while the pane's visibility toggled every few seconds; the same frame
   with a 2 s interval polled normally, and the 15 s list and 4 s message
   frames kept working. `schedule()` clears the timer and always schedules
   a full interval, so any frame whose interval is longer than the gap
   between visibility changes starves. The badge at 30 s is exactly that
   case on an ordinary desktop where a user switches tabs.
   Preserve the remaining time: remember when the current interval started
   and, on resuming, wait only the remainder (never a negative value, and
   poll immediately when the interval has already elapsed while hidden).
   Keep the existing backoff on failures and the rule that hidden or
   invisible frames do not poll.

## Rules

- Do not add features and do not touch the server side unless a fix
  genuinely requires it. Anything else you notice goes into the report,
  not into the code.
- Verify Turbo's event behaviour in the host's
  `node_modules/@hotwired/turbo` before relying on it; append a
  "Phase 4b" section to `DECISIONS.md` with the evidence.
- Never claim a check ran; unrun checks are "not verified".
- English everywhere except translation files; no trivial wrappers;
  preserve every data attribute, cursor rule and view contract.
- Extend `.docs/build/verify-phase-3c-client.cjs` (or add a sibling
  client test) so both defects are covered by an automated check that
  fails against the current implementation: one asserting that polling
  resumes after `turbo:before-cache` followed by `pageshow` without a
  `turbo:load`, and one asserting that a frame with a long interval polls
  after the remaining time rather than a full interval when visibility
  toggles.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan, Rector, `lint:twig`, the webpack build, the client tests and
  `.docs/build/verify-phase-3a-http.sh`.
- Update `.docs/BROWSER_CHECKLIST.md` with a row for each fix, including
  the exact reproduction so a reviewer can confirm it in the browser.
- Do not modify host application code. Commit in small, meaningful steps.

## Report

`.docs/build/reports/phase-4b-polling-resilience.md`: one section per
defect with the change and the evidence that it is fixed, decisions and
non-verifiable items, exact commands and output for every check, and
anything new you noticed but did not fix.
