# Phase 3b browser checklist

Status: **not verified in a browser**. Run the following five-minute pass with
Alice and Bob already logged in in two separate browser profiles. Record the
browser/device and pass/fail beside each row. HTTP checks are not a substitute
for this pass. Do not proceed to phase 4 as part of this checklist.

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

| Time | Action | Expected result |
| --- | --- | --- |
| 0:00–0:30 | Alice sends a message using desktop Enter, then types Shift+Enter. Also submit whitespace using the button. | Enter sends once even on a touch-capable laptop with a fine primary pointer. Shift+Enter inserts a newline. Successful send resets/refocuses the textarea and scrolls down. Whitespace returns an error, retains text and links `aria-describedby="chat-compose-error"` to the visible error. |
| 0:30–1:00 | With Alice at the bottom, Bob sends. Then Alice scrolls up and Bob sends again. Wait up to four seconds per poll. | At bottom, the message scrolls into view. Away from bottom, the visible message stays in place and “New messages ↓” appears. Activate it by keyboard: scroll to bottom, control disappears, focus remains usable on the log. Inspect `data-chat-at-bottom` / `data-chat-has-new`. No duplicate messages on the next poll. |
| 1:00–1:40 | Alice opens History 01 and scrolls to the top. Repeat after setting `data-chat-auto-load="false"` on `#chat-messages` in DevTools and reloading that conversation as needed. Activate Older messages with keyboard. | Automatic observation loads the older five messages; the visible message does not jump, including when the final button disappears. Manual mode loads only on click/keyboard. Button disables during the request. Log is `aria-live="off"` during insertion and returns to `polite`. No duplicate visible day separator at the boundary; older messages stay after a poll. |
| 1:40–2:05 | Scroll Alice's conversation list to its bottom; repeat with auto-load disabled on `#chat-conversations` if needed. | More conversations appends the final two entries, removes the exhausted button and keeps newer entries first. Loaded entries survive the next 15-second list poll. Keyboard activation works. |
| 2:05–2:35 | On Alice/Bob, Tab to Mute conversation and press Space, then toggle back. Have Bob send while muted and wait for the list poll. | Only `chat-mute` is replaced; partner heading and compose text stay. Focus returns to the toggle. `aria-pressed` changes true/false, with the fixed translated label. Muted list item has a muted icon and no unread count. New messages still arrive. No polling requests target the mute frame. |
| 2:35–3:00 | Inspect fresh messages and History 01; inspect their `<time>` and day separators. | Fresh text is relative in page English; older times use local device time. `datetime` remains a complete timestamp and tooltip gives full date/time. Server-rendered day separators have text and `role="separator"`, one visible separator per day. Today appears in the current chat; historical days show weekday/date. Yesterday has unit coverage; verify with yesterday's messages when available. |
| 3:00–3:25 | Prefer German in the browser's language settings, keep the English demo page, send/poll/mute/search. Search `Car`, then an unmatched term, then erase to one character. | All refreshed labels stay English. `Car` finds Chat Carol. An unmatched sufficiently long query shows “No contacts found.” Below two characters the results area is empty, even if a previous search was still loading. |
| 3:25–4:20 | On the real phone, open Alice/Bob below the page header/login area. Focus the textarea, open/close the keyboard, rotate or resize while at bottom, then repeat while scrolled up. | Conversation fits the remaining visual viewport, compose remains reachable above the keyboard/safe area, and the page does not gain a second chat-height scroll region. At-bottom position follows resizing; reading position is not forced down otherwise. Touch Enter makes a newline; Send sends. Empty-state text is absent on mobile, present beside the list at desktop width. |
| 4:20–5:00 | Navigate back/open another conversation, background/restore the tab, and check requests and focus. | No doubled timers/requests after Turbo navigation. Hidden tabs and the hidden mobile sidebar do not poll. History reloads only on navigation, not on polls. Compose typing is not interrupted. No self-referencing-frame error in console. |

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
