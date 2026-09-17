# Phase 3b frontend refinements

Implemented on 2026-09-17. Phase 4 was not started. Server, template and build
checks pass; real browser interaction is **not verified**. The reviewer pass
is in [BROWSER_CHECKLIST.md](../../BROWSER_CHECKLIST.md).

## Files by concept section

- **15 / locale:** `src/View/ChatContextFactory.php`,
  `src/Controller/ChatFrameController.php`. Page locale is established before
  contact/view mapping and rendering; frame, stream and application validation
  labels use the page language rather than Accept-Language.
- **15 / participant activity:** `src/Configuration/ChatOptions.php`,
  `src/HeimrichHannotSimpleMemberChatBundle.php`,
  `src/Gateway/ParticipantGateway.php`. Configurable activity throttling;
  `tstamp` changes only for a changed read position or mute state.
- **15 / search:** `src/Gateway/ContactGateway.php`,
  `contao/templates/member_chat/contact_results.html.twig`, `assets/js/member_chat.js`.
  Escaped word prefixes, empty-search feedback, no results below the minimum,
  and rejection of stale search renders after the query changes.
- **5.3a / history:** `src/Service/ChatReader.php`,
  `src/Controller/ChatFrameController.php`, `src/View/ChatViewFactory.php`,
  `src/View/Model/ChatView.php`; `member_chat/{load_more,message_list,
  conversation_list,messages.stream,conversations.stream}.html.twig` under
  `contao/templates/`. Lookahead, independent before cursors, prepend/append
  streams and keyboard-accessible load buttons.
- **5.1 / mute:** `src/Controller/ChatActionController.php`, `ChatReader`,
  `ChatContextFactory`, `ChatView`, `content_element/member_chat.html.twig`,
  `member_chat/{mute,mute.stream}.html.twig`. Strict POST value, existing
  CSRF/access/service checks, non-polled frame and pressed-state toggle.
- **5.6 / time and day boundaries:** `src/View/DaySeparatorFactory.php`,
  `src/View/Model/{DaySeparatorView,MessageView}.php`, `ChatViewFactory`,
  `member_chat/{day_separator,message,message_list,messages.stream}.html.twig`,
  `assets/js/member_chat.js`. Translated server day labels; client relative
  times and boundary reconciliation, including overlapping send/poll windows.
- **5.1, 5.6a, 5.7 / client, CSS, accessibility:**
  `assets/{js/member_chat.js,css/member_chat.css}`,
  `member_chat/new_messages.html.twig`, the list/CE templates and new partials.
  Pointer-only Enter detection, explicit visible-message anchoring, bottom/new
  state, muted/history focus restoration, live-region suspension during prepend,
  visualViewport/ResizeObserver height, desktop-only empty state and control
  custom properties. New elements have `attrs().mergeWith(...)` extension points.
- **Translations:** `translations/messages.{en,de}.php`.
- **Tests:** `tests/Unit/FrontendTest.php`,
  `tests/Integration/{GatewaysTest,ContactsTest}.php`. Day labels/transitions,
  before and mute view state, SQL escaping, real word-prefix searches,
  throttle boundaries and unchanged list cursors, real reader history windows.
- **Reproduction/docs:** `.docs/build/verify-phase-3a-http.sh` extended in place,
  `.docs/build/phase-3b-demo.php`, appended Phase 3b section in `DECISIONS.md`,
  `.docs/BROWSER_CHECKLIST.md` and this report.

## Decisions and verification limits

[DECISIONS.md](../DECISIONS.md#phase-3b) records the vendor evidence and public
contracts. Key details:

- `polling.activity_throttle` is in **seconds**, default 30, minimum zero.
  Read/mute changes bypass the activity throttle. Activity-only `lastPageId`
  updates can be delayed by that interval.
- Before responses carry `X-Chat-Before`, empty when exhausted. They never
  advance `after` or `since`. Send responses still never advance `after`.
  The list cursor's integer tie-breaker is explicitly required by this phase,
  despite the concept's earlier blanket prohibition on exposing internal IDs.
- Day separators are emitted at each server window boundary; redundant boundary
  candidates are hidden by the client after message-ID ordering. Their text is
  server-side and translated. Dates use the configured PHP/Twig timezone;
  client time text uses the device timezone.
- Native scroll anchoring is disabled. The first visible message and its offset
  are restored after history insertion/reconciliation/time formatting. The
  history loader waits for all Turbo streams to render before releasing its
  per-frame lock. Intersection observation retries after an overlapping poll
  or lazy frame finishes.
- The measured viewport custom property is a narrow exception to the concept's
  no-inline-styles rule, required by the explicit visualViewport instruction.
  CSS remains the source of the layout breakpoint.
- **Not verified:** real browser scrolling, auto-loading intersections, focus,
  screen-reader announcements, background/visibility behavior, desktop/touch
  Enter, search debounce, relative time appearance, real iOS/Android keyboard,
  standalone PWA, custom theme overrides, production webpack builds and hosted
  CI. The checklist gives exact actions and expected outcomes. No browser login
  was attempted. Curl used the existing real Contao login form as requested.

## Commands and observed output

All PHP and webpack commands below ran from
`/home/dev/Kunden/contao/contao_0507` using DDEV. Shell/Node syntax, curl and Git
commands ran from `/home/dev/Kunden/github/contao-simple-member-chat`.
Output below omits only terminal padding/blank lines.

### PHPUnit and automatic formatting

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --no-progress-bar > /tmp/chat-3b-rector-fix.log 2>&1 && vendor/bin/ecs check --fix --no-progress-bar > /tmp/chat-3b-ecs-fix.log 2>&1 && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist
................................................................. 65 / 78 ( 83%)
.............                                                     78 / 78 (100%)
Time: 00:05.045, Memory: 16.00 MB
OK (78 tests, 452 assertions)
```

The dedicated `member_chat_test` database was used. No skipped tests.
The two redirected fix-pass logs are in the DDEV container's `/tmp`.

### Independent static checks

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
[OK] No errors
```

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text
[OK] No errors found. Great job - your code is shiny in style!
[OK] Rector is done!
```

PHPStan remains `max`; no suppressions, lowered rules or new tool exclusions.

### Cache, Twig, JavaScript, shell

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
[OK] Cache for the "prod" environment (debug=false) was successfully cleared.
```

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text
[OK] All 16 Twig files contain valid syntax.
```

```sh
node --input-type=module --check < assets/js/member_chat.js
bash -n .docs/build/verify-phase-3a-http.sh
git diff --check
```

All exited 0, no output.

### Demo fixtures

```sh
ddev describe -j
ddev exec php /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3b-demo.php
```

`ddev describe -j` reported the running `contao0507.contao` project, PHP 8.4,
MariaDB 10.11, and direct HTTPS URL `https://127.0.0.1:32773`.
Fixture output (the member ID array is shown on one line):

```json
{
  "memberIds": [12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62],
  "conversationCount": 51,
  "historyConversation": {"id": 3, "uuid": "01a0aee4-7cea-7d92-aad3-2f1441e6a8e2"},
  "messageCount": 105
}
```

### Asset build and demo-script syntax

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

Final build after the observer retry adjustment:

```text
DONE  Compiled successfully in 1686ms12:30:19 PM
17 files written to public/build
webpack compiled successfully
```

```sh
ddev exec php -l /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3b-demo.php
```

```text
No syntax errors detected in /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3b-demo.php
```

### HTTP acceptance

```sh
bash .docs/build/verify-phase-3a-http.sh
```

The script now defaults to direct HTTPS port 32773, overridable using
`CHAT_BASE_URL`. It records request/response files under `/tmp`, logs in through
Contao's actual login form, obtains real REQUEST_TOKEN values and removes its
cookie jars/token/login-data files on exit. Exact curl invocations and all
assertions are in the script, including page 86 plus `Accept-Language: de`,
older windows, two idle polls separated by two seconds, strict mute values,
CSRF and foreign-conversation rejection.

```text
alice-login-form: HTTP 200
alice-login: HTTP 302
bob-login-form: HTTP 200
bob-login: HTTP 302
anonymous: HTTP 401
search: HTTP 200
open: HTTP 303
legacy: HTTP 200
modern: HTTP 200
list: HTTP 200
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
idle-list-two: HTTP 200
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
Stable idle X-Chat-Since: 1789640961
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.B116CG
```

### Failed intermediate checks, resolved

- First PHPStan pass: a negated `preg_match` result and two short ternaries;
  replaced with strict comparisons. A later pass caught three nullable-state
  offsets in the new test; assertions now handle null explicitly.
- One PHPUnit pass failed the configuration constructor test because the new
  option's definition order differed from its constructor order. The option was
  moved to the end in both places, preserving all existing positional arguments.
- One HTTP pass ran while webpack cleared its output directory. It failed on
  Bob's login page with `NoEntrypointsException`: `public/build/entrypoints.json`
  did not exist. Response evidence: `/tmp/member-chat-http.imnbqC/bob-login-form.html`.
  The sequential rerun above passed after webpack completed. This was not a
  chat controller failure; do not run the HTTP check concurrently with webpack.
- The first Git commit attempt hit the sandbox's read-only `.git/index.lock`.
  The authorized local commits succeeded using the normal escalation mechanism.

## Host changes

No host application PHP, Twig, JS, configuration, DCA, layout or page source/data
was edited in this phase. No migration was applied. Authorized changes were:

- Demo members **12–62**, usernames `phase3b-history-01` through `-51`,
  non-login accounts with `example.invalid` addresses.
- **51** Alice/history conversations (IDs 3–53), **105** fixture messages;
  History 01 contains 55 messages and the other 50 contain one each. Fixture
  timestamps span older days. The script is idempotent and skips existing pairs.
- HTTP runs sent verification messages in the existing Alice/Bob conversation,
  advanced activity/read/page fields, toggled Alice's mute state and restored
  it to unmuted, and created ordinary demo login sessions.
- Generated `public/build` assets/manifest (17 files), normal cache files, and
  isolated test-database fixtures. Host webpack/application source was untouched.

## Phase 4 handoff

No unresolved product decision blocks phase 4, but browser acceptance remains
pending. Backend module, external unread badge, URL fallback, Messenger
preparation and README are still phase 4 work. README should document the
new view/data contracts, history cursor exception, throttle seconds and page
tracking delay, custom properties, Turbo requirement and Android viewport meta.
Use the checklist's recorded device results before claiming frontend acceptance.

## Local commits

- `424d6d0` — Throttle chat activity writes and search contact word prefixes.
- `f3c4cde` — Add localized history windows, day separators and mute controls.
- `0d7a530` — Refine chat history loading, scrolling and mobile viewport behavior.
- A final follow-up records the observer retry and the verification artifacts.

No push was performed.
