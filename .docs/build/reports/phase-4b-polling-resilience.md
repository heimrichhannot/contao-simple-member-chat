# Phase 4b — Polling resilience

Implemented 2026-09-17. Only the two requested client defects were fixed.
Implementation and regression tests: commit `bf61a01`.
Original implementation used for failing checks: `b726fce9a3f5be10ff755a47684f8d38c87d44a7`.

## Defect 1: permanent stop after cache teardown

`start()` now handles native `pageshow` (persisted and non-persisted),
`turbo:render`, and visibility returning to visible, alongside existing load
signals. Active starts discover frames without rebuilding observers or timers.
The same teardown handles `turbo:before-cache` and native `pagehide`.
Frame discovery is disabled while stopped; restored message frames receive
only one scroll listener. Async completions check their original frame state
before advancing cursors or scheduling, preventing pre-teardown work from
rescheduling a restored frame.

Evidence: the original code fails `restore` with 2 requests instead of 3 after
cache teardown followed by pageshow without turbo:load. The fixed test passes
for both persisted values. It checks one timer, one relative-time interval,
unchanged observer count on redundant signals, one scroll listener and one
additional request per interval. Additional tests cover each fallback restart,
pagehide suspension and aborted pending fetches finishing after restart.

## Defect 2: long intervals starve when visibility toggles

Each frame stores its next deadline internally. Visibility rescheduling uses
`max(0, dueAt - Date.now())`, retaining elapsed time while hidden. Completion
starts the next interval with the existing exponential backoff and maximum.
Invisible/busy frames defer another interval instead of looping at zero delay;
loading frames wait for completion to schedule.

Evidence: the original code fails `remaining` with no badge request at 30 s.
With the fix, a full-mode 30-second badge polls at exactly 30 s despite five
4-second-visible/1-second-hidden cycles. It then stays hidden past the next
deadline, makes no hidden requests, and polls immediately on return at 70 s.
The harness also verifies failure request times 30/90/210/450/690 seconds,
the 240-second cap, reset after success, invisible/busy guards and no overlapping
owned fetches. Existing phase 3c and phase 4 client regressions pass unchanged.

## Decisions, scope and non-verifiable items

- Installed Turbo 8.0.23 source evidence is recorded in
  `../DECISIONS.md#phase-4b`. Its pagehide delegate only relinquishes scroll
  restoration; it does not emit before-cache there. Explicit native teardown
  addresses that difference from the prompt. Snapshot caching emits before-cache;
  render and load are separate notifications, and no pageshow handler exists.
- Lifecycle teardown intentionally clears frame state; resumed frames start
  their configured interval. Ordinary visibility pauses preserve the deadline.
- Existing data attributes, full badge reload mode, inclusive since/fingerprint
  semantics, after/before cursors, view contracts and server code are unchanged.
- No host application code was modified. Webpack regenerated host public/build
  artifacts. PHPUnit rebuilt dedicated member_chat_test fixtures. The existing
  HTTP harness logged in with demo members, sent its test message, updated
  read/activity state and toggled mute off again.
- **Not verified:** actual browser bfcache admission/restore, tab throttling,
  rendered request cadence in a browser, mobile/embedded browser lifecycle,
  iOS/Android/PWA behavior, screen readers, production builds and hosted CI.
  Synthetic event/fake-clock tests establish client logic, not device acceptance.
  The checklist contains exact manual reproductions for both defects.
- No additional unrelated defect was confirmed or fixed. Turbo-owned full-frame
  reloads continue to use Turbo's own request lifecycle and busy guard; the
  client's AbortController cancels its fetches, not Turbo's internal requests.

## Exact verification commands and captured output

Run all `ddev` commands from `/home/dev/Kunden/contao/contao_0507`;
HTTP and git commands from `/home/dev/Kunden/github/contao-simple-member-chat`.
PHPUnit's default configuration includes both Unit and Integration suites and
the command explicitly sets MEMBER_CHAT_TEST_DATABASE_URL. Output below has
trailing whitespace removed. All required checks completed with exit 0.
The two original-code regression checks deliberately exit 1.

The red restore command was run with `HEAD` before the implementation commit;
the reproducible command below pins that exact original revision instead.

### phpunit

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

................................................................. 65 / 85 ( 76%)
....................                                              85 / 85 (100%)

Time: 00:05.663, Memory: 16.00 MB

OK (85 tests, 526 assertions)
Exit: 0
```

### ecs

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text


 [OK] No errors found. Great job - your code is shiny in style!
Exit: 0
```

### phpstan

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.

 [OK] No errors
Exit: 0
```

### rector

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text

 [OK] Rector is done!
Exit: 0
```

### twig

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text

 [OK] All 17 Twig files contain valid syntax.
Exit: 0
```

### clients

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs && ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-3c-client.cjs && ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4-client.cjs
```

```text
PASS: restore
PASS: remaining
PASS: lifecycle
PASS: backoff
PASS: pending
PASS: captured passive scroll recalculates 435px to 812px with one queued frame
PASS: hidden post-render history completes, restores aria-live and releases loading without repaint
PASS: empty standalone badge sets its source, reloads repeatedly in full mode and pauses for hidden tab/navigation
Exit: 0
```

### webpack

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
 DONE  Compiled successfully in 1003ms4:00:36 PM

19 files written to public/build
webpack compiled successfully
Exit: 0
```

### http

```sh
bash .docs/build/verify-phase-3a-http.sh
```

```text
alice-login-form: HTTP 200
alice-login: HTTP 302
bob-login-form: HTTP 200
bob-login: HTTP 302
badge-anonymous: HTTP 401
badge: HTTP 200
backend-smoke: HTTP 302
anonymous: HTTP 401
search: HTTP 200
open: HTTP 303
legacy: HTTP 200
modern: HTTP 200
list: HTTP 200
initial-idle-list: HTTP 204
messages: HTTP 200
compose: HTTP 200
send: HTTP 200
invalid-message: HTTP 422
invalid-csrf: HTTP 400
incremental: HTTP 200
empty-poll: HTTP 204
list-poll: HTTP 200
foreign: HTTP 404
malformed: HTTP 404
locale: HTTP 200
word-search: HTTP 200
no-contacts: HTTP 200
short-search: HTTP 200
idle-list-one: HTTP 200
idle-message: HTTP 204
idle-list-two: HTTP 204
older-messages: HTTP 200
mixed-messages: HTTP 400
older-conversations: HTTP 200
mixed-conversations: HTTP 400
mute: HTTP 200
muted-list: HTTP 200
mute-invalid: HTTP 400
mute-csrf: HTTP 400
mute-foreign: HTTP 404
unmute: HTTP 200
legacy: head meta, Encore asset and all four frames verified
modern: head meta, Encore asset and all four frames verified
Badge polling markup, locale, privacy and backend authentication boundary verified
Stable idle X-Chat-Since: 1789653655
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.Gbz6OH
Exit: 0
```

### syntax

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && node --input-type=module --check < assets/js/member_chat.js && node --check .docs/build/verify-phase-4b-client.cjs'
```

```text
(no output)
Exit: 0
```

### diff

```sh
git diff --check
```

```text
(no output)
Exit: 0
```

### red-restore

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && git show b726fce9a3f5be10ff755a47684f8d38c87d44a7:assets/js/member_chat.js > /tmp/member-chat-phase4b-original.js && CHAT_CLIENT_SOURCE=/tmp/member-chat-phase4b-original.js node .docs/build/verify-phase-4b-client.cjs restore'
```

```text
AssertionError [ERR_ASSERTION]: pageshow must resume without turbo:load
    at restore (/home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs:93:20)
    at async /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs:188:9 {
  generatedMessage: false,
  code: 'ERR_ASSERTION',
  actual: 2,
  expected: 3,
  operator: 'strictEqual'
}
Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && git show HEAD:assets/js/member_chat.js > /tmp/member-chat-phase4b-original.js && CHAT_CLIENT_SOURCE=/tmp/member-chat-phase4b-original.js node .docs/build/verify-phase-4b-client.cjs restore`: exit status 1
Exit: 1
```

### red-remaining

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && CHAT_CLIENT_SOURCE=/tmp/member-chat-phase4b-original.js node .docs/build/verify-phase-4b-client.cjs remaining'
```

```text
AssertionError [ERR_ASSERTION]: toggles must preserve the original deadline
    at remaining (/home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs:113:16)
    at async /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs:188:9 {
  generatedMessage: false,
  code: 'ERR_ASSERTION',
  actual: [],
  expected: [Array],
  operator: 'deepStrictEqual'
}
Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && CHAT_CLIENT_SOURCE=/tmp/member-chat-phase4b-original.js node .docs/build/verify-phase-4b-client.cjs remaining`: exit status 1
Exit: 1
```

## Commit and verification notes

The first ordinary git add/commit attempt stopped before staging because the
sandbox mounted .git read-only (`Unable to create .../.git/index.lock:
Read-only file system`). The same explicitly requested commit succeeded with
elevated filesystem access. No push was performed.

Before the final badge-specific test fixture was added, both original-code
checks also failed independently with the same assertions using an incremental
message frame. The final fixture uses a full-mode badge for the 30-second case;
the captured red checks above were rerun against the original source after that
fixture change. No failed production-code checks remain.

