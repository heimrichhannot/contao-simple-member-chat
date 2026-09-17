# Phase 3b / 3c browser checklist

Status: Phase 3b desktop and 375px review is recorded in `.docs/CONCEPT.md`,
section 15. Confirmed behaviors below are historical reviewer evidence, not a
new phase 3c browser pass. Phase 3c automated/HTTP checks pass; its browser
rechecks are **not verified** because automatic approval review rejected the
demo browser login. The later reviewer verification in concept section 15
supersedes that phase 3c status: desktop/375px scroll height, compact header,
mute styling and idle polls are confirmed there. Phase 4 adds the checks below;
no new browser login was performed by the implementing agent.

## Setup (outside the five-minute pass)

- Demo URL: `https://127.0.0.1:32773/phase3a-chat-legacy.html`.
  Recheck the HTTPS port with `ddev describe` if DDEV restarted. Modern-layout
  counterpart: `/phase3a-chat-modern.html`.
- Reviewer logs in as `phase3a-chat-alice` and `phase3a-chat-bob`, using the
  local demo password in `.docs/build/phase-3a-demo.php`.
- Alice/Bob conversation:
  `/phase3a-chat-legacy/01a0ae9d-2c0d-7b36-8209-a415ae1e4ed5.html`.
- Alice's **History 01** conversation has 55 messages over six days:
  `/phase3a-chat-legacy/01a0aee4-7cea-7d92-aad3-2f1441e6a8e2.html`.
  The list has 51 History contacts plus Bob, so its second page is real.
  These contacts cannot log in. `.docs/build/phase-3b-demo.php` created them
  without modifying layouts, pages or application source.
- Use a desktop pointer for Enter testing. Have an already logged-in real
  iOS/Android device available for the keyboard check. Device emulation alone
  does not verify the real keyboard or PWA standalone behavior.

## Five-minute pass

| Time / recorded 3b status | Action | Expected result |
| --- | --- | --- |
| 0:00–0:30  **Confirmed: Enter, Shift+Enter, reset/focus/bottom; whitespace association HTTP only** | Alice sends a message using desktop Enter, then types Shift+Enter. Also submit whitespace using the button. | Enter sends once even on a touch-capable laptop with a fine primary pointer. Shift+Enter inserts a newline. Successful send resets/refocuses the textarea and scrolls down. Whitespace returns an error, retains text and links `aria-describedby="chat-compose-error"` to the visible error. |
| 0:30–1:00  **Confirmed: reading anchor, new-message control; keyboard activation not recorded** | With Alice at the bottom, Bob sends. Then Alice scrolls up and Bob sends again. Wait up to four seconds per poll. | At bottom, the message scrolls into view. Away from bottom, the visible message stays in place and “New messages ↓” appears. Activate it by keyboard: scroll to bottom, control disappears, focus remains usable on the log. Inspect `data-chat-at-bottom` / `data-chat-has-new`. No duplicate messages on the next poll. |
| 1:00–1:40  **Confirmed: automatic/manual prepend, pixel anchor, live reset and day boundary; recheck hidden cleanup** | Alice opens History 01 and scrolls to the top. Repeat after setting `data-chat-auto-load="false"` on `#chat-messages` in DevTools and reloading that conversation as needed. Activate Older messages with keyboard. | Automatic observation loads the older five messages; the visible message does not jump, including when the final button disappears. Manual mode loads only on click/keyboard. Button disables during the request. Log is `aria-live="off"` during insertion and returns to `polite`. No duplicate visible day separator at the boundary; older messages stay after a poll. |
| 1:40–2:05  **Confirmed: list pagination and sorting** | Scroll Alice's conversation list to its bottom; repeat with auto-load disabled on `#chat-conversations` if needed. | More conversations appends the final two entries, removes the exhausted button and keeps newer entries first. Loaded entries survive the next 15-second list poll. Keyboard activation works. |
| 2:05–2:35  **Confirmed: pressed state, focus and muted list; recheck label/style** | On Alice/Bob, Tab to Mute conversation and press Space, then toggle back. Have Bob send while muted and wait for the list poll. | Only `chat-mute` is replaced; partner heading and compose text stay. Focus returns to the toggle. `aria-pressed` changes true/false, and the translated label changes between “Mute conversation” and “Unmute conversation”; the pressed state has a contrasting fill and inset border. Muted list item has a muted icon and no unread count. New messages still arrive. No polling requests target the mute frame. |
| 2:35–3:00  **Confirmed: relative time, datetime, tooltip, day boundaries** | Inspect fresh messages and History 01; inspect their `<time>` and day separators. | Fresh text is relative in page English; older times use local device time. `datetime` remains a complete timestamp and tooltip gives full date/time. Server-rendered day separators have text and `role="separator"`, one visible separator per day. Today appears in the current chat; historical days show weekday/date. Yesterday has unit coverage; verify with yesterday's messages when available. |
| 3:00–3:25  **Confirmed: page locale and search** | Prefer German in the browser's language settings, keep the English demo page, send/poll/mute/search. Search `Car`, then an unmatched term, then erase to one character. | All refreshed labels stay English. `Car` finds Chat Carol. An unmatched sufficiently long query shows “No contacts found.” Below two characters the results area is empty, even if a previous search was still loading. |
| 3:25–4:20  **Confirmed: 375px single-column/empty state only; recheck height; real phone not verified** | On the real phone, open Alice/Bob below the page header/login area. Focus the textarea, open/close the keyboard, rotate or resize while at bottom, then repeat while scrolled up. | Conversation fits the remaining visual viewport, compose remains reachable above the keyboard/safe area, and the page does not gain a second chat-height scroll region. At-bottom position follows resizing; reading position is not forced down otherwise. Touch Enter makes a newline; Send sends. Empty-state text is absent on mobile, present beside the list at desktop width. |
| 4:20–5:00  **Not recorded: navigation/background/timer checks** | Navigate back/open another conversation, background/restore the tab, and check requests and focus. | No doubled timers/requests after Turbo navigation. Hidden tabs and the hidden mobile sidebar do not poll. History reloads only on navigation, not on polls. Compose typing is not interrupted. No self-referencing-frame error in console. |

## Additional device/accessibility checks (not verified)

- Repeat keyboard/height on iOS Safari **and installed standalone PWA**, and on
  Android Chrome with the project's `interactive-widget=resizes-content` meta.
- Screen reader: prepend does not announce the entire history; incoming text
  is announced once; the mute toggle communicates pressed state.
- Slow/offline network: repeated load-more clicks create one request; failed
  history load re-enables the button for retry; polling retains backoff.
- Override project grid breakpoint and the documented custom properties; check
  single-column height calculation follows actual sidebar visibility. Disable
  JavaScript to inspect server time text and the static height fallback.
- Concurrent own send and incoming poll: message IDs remain ascending, no
  message is skipped, and no day separator is duplicated visibly.

## Focused phase 3c recheck (browser results pending)

| Finding | Action and expected result | Evidence already available |
| --- | --- | --- |
| Document / ancestor scroll | At 375px, scroll the page so the chat root moves from below the header to viewport top; height grows to use the remaining viewport. Repeat inside a scrolling ancestor. At bottom, stay at bottom; while reading, do not jump down. | Client unit harness: 435 → 812px and one queued animation frame. Actual browser pixels not verified. |
| Compact header / protected history | At 375px inspect a long partner name: one line with ellipsis, mute/back on the same row, history at least 12rem. Override the documented custom properties. With a very short keyboard viewport, controls remain reachable by page scrolling. Disable JS and check `100dvh` fallback. | CSS source and build verified; actual theme layout and no-JS rendering not verified. |
| Visible mute state | Toggle twice by keyboard: translated action changes, pressed style is visibly distinct, focus stays on the button, header/compose remain intact. | HTTP confirms both English labels and pressed values. Browser focus/style recheck pending. |
| Hidden history cleanup | Disable automatic history loading, activate Older messages, then background the tab as streams finish. Return: live region is polite, button usable, polling resumes. Repeat on slow network and with the final page. | Isolated post-render hidden-document test passes without executing a repaint. Real tab timing and screen reader behavior not verified. |
| Idle polling | Inspect network from the initial list: unchanged polls send matching `since`/`fingerprint`, return 204 and produce no list streams. Send/read/mute in a second session, including rapid same-second changes: changes arrive; following idle poll is 204. | Initial and subsequent idle HTTP 204 verified; same-second mutations covered with the real test database. |


## Phase 4 edges (manual acceptance pending)

All rows below are **not verified in a browser**. HTTP and PHP evidence is in
`build/reports/phase-4-edges.md`. No new demo content was created in phase 4.

| Area | Action | Expected result |
| --- | --- | --- |
| Standalone badge | On a page without a chat element, render `member_chat_unread_badge({class: 'nav__badge'})`, enable `huh_member_chat_badge` and a Turbo entry, rebuild. Add the Turbo no-cache meta described in README. | No chat CSS is loaded solely for the badge. Server count/link is present immediately; anonymous output is empty. |
| Zero count | Read all unmuted messages; have a second member send while the first stays on the badge-only page. | Empty `chat-unread` frame remains; within the badge interval (30 seconds default), a count/link appears. Repeated requests are full-frame GETs, no self-referencing src error or incremental stream error. |
| Accessibility/locale | Inspect a positive badge in English/German, navigate by keyboard and use a screen reader. | Accessible label contains translated unread count, link opens chat at top level; polling has `aria-live=off`. Classes survive frame reload. |
| Mute and tabs | Mute a conversation, send to it, background/restore the tab, then navigate with Drive. | Muted messages do not increase the badge; hidden tabs pause and restored pages have one timer, not duplicates. |
| URL fallback | Visit each demo chat page, wait past activity throttle; test a deleted/unpublished/non-regular saved destination, then a root fallback. | Tracked valid page wins; invalid page falls back in root sorting/ID order; no valid destination renders a count without a broken link. |
| Backend module | As an authorized backend user open Accounts → Member chat, open its message child list; repeat without module permission. | Pair/author labels and timestamps/excerpts render escaped; only children/delete on conversations and delete on messages. No participant module or create/edit UI; unauthorized users cannot access it. |
| Moderation | Delete a middle message, then the last message, then the remaining message in a disposable conversation; reload the frontend. Delete another disposable conversation. | Last-message pointer/time follows the remaining tail or becomes zero; all child records disappear on conversation deletion. Already-open message logs require reload to remove deleted text. |
| Theme and devices | Carry forward real iOS/Android/PWA, screen-reader and theme override checks above. | Results must be recorded separately; local automated checks do not certify these. |
