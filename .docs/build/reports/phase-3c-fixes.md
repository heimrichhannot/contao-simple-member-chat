# Phase 3c fixes

Implemented 2026-09-17. Scope is exactly the five phase 3b findings.
Phase 4 was not started. Required automated checks pass. Browser rechecks
remain **not verified**; the browser login was rejected by automatic approval
review (details below). No browser success is inferred from HTTP or unit tests.

## 1. Recalculate height on document scroll

`assets/js/member_chat.js` captures passive document scroll events, including
non-bubbling events from scrolling ancestors. The existing animation-frame
batch, visualViewport calculation, CSS breakpoint detection and at-bottom
branch are retained. There are no per-ancestor registrations to leak across
Turbo navigation. The no-JavaScript `100dvh` fallback is unchanged.

Evidence: `.docs/build/verify-phase-3c-client.cjs` executes the actual source
with controlled geometry. Two scroll events schedule one callback; moving the
root from top 377 to 0 changes the measured height from 435 to 812px. This is
unit-level evidence, not a browser pixel measurement. Real scroll, bottom-state
and nested-container behavior remain in the focused checklist.

## 2. Protect history and compact the header

The message log has a configurable 12rem minimum. The header is a single flex
row with non-shrinking controls and a shrinking, single-line ellipsized heading.
Heading margins/padding, line height and control typography are explicitly
scoped. Compose does not shrink to make room for the history minimum.

Source evidence: the demo theme's unlayered h2 styles outrank the bundle's
original layered declaration. Narrow unlayered structural rules address this
without `!important`; all new values are documented custom properties in
`DECISIONS.md`. Very short viewports can require page scrolling when the
minimum history plus controls exceeds the viewport; this preserves reachability.

Evidence: webpack compiles the revised CSS. Actual 375px appearance, ellipsis,
keyboard layout, custom-property overrides and no-JavaScript rendering are
**not verified** in this run because browser login was blocked.

## 3. Visible mute state

The existing mute partial renders translated Mute/Unmute actions from
`view.muted`. English and German translations are present. Pressed controls
use a contrasting configurable fill/foreground and an inset border. Existing
`aria-pressed`, POST route, CSRF, frame replacement and focus restoration stay.

Evidence: the HTTP harness asserts both English labels and pressed values,
including English page locale with German Accept-Language. Both action
responses pass. Browser visual state and keyboard focus recheck are pending;
the earlier 3b review already confirmed focus restoration and list mute state.

## 4. Hidden history loader cleanup

Removed the extra animation-frame await after all wrapped Turbo streams finish.
The `finally` block restores aria-live, releases the loading lock and re-enables
the button without requiring a paint. Stream completion still precedes cleanup.

Evidence: the client test runs actual `loadMore` with completed stream rendering,
a hidden document and animation-frame callbacks that never execute. It asserts
`loading=false`, `disabled=false`, `aria-live=polite` and no queued repaint. A
real timeout makes the previous implementation fail rather than exit silently.
Turbo itself is stubbed only at the stream-completion seam. Actual tab-switch
and screen-reader timing are **not verified**. The manual reproduction is in
the checklist.

## 5. Idle conversation polls

The gateway retains inclusive, unbounded `since`. The server compares a SHA-256
fingerprint of the timestamp and conversation view values. Empty/matching
windows return private 204 without producing Turbo streams. Full frames/pages
seed the fingerprint. Response fingerprints describe the next inclusive cursor
boundary; filtered array keys are normalized. The client advances cursor and
fingerprint together after successful rendering. History pages change neither.
An incomplete initial timestamp boundary mismatches safely and delivers all
matching rows. No timestamp-only or ID-only strict comparison is introduced.

Additive contract: optional `fingerprint` query parameter,
`X-Chat-Fingerprint`, `data-chat-fingerprint`, and trailing optional
`ChatView.conversationFingerprint`. Existing routes, fields, attributes and
message/history cursor contracts remain. Standard view data determines the
fingerprint; custom templates using unrelated changing state must extend it.

Evidence: MariaDB integration assertions cover stable inclusive windows,
normalization of a broader window to the next boundary, and same-second
send/read/mute/unmute changes with a constant timestamp. The HTTP harness
verifies an initial frame's first idle poll and a later idle poll both return
204, with an empty body and stable cursor/fingerprint on the later poll.
Existing changed-list, history, access and CSRF checks pass.

## Decisions, limits and untouched work

See the appended Phase 3c section in `DECISIONS.md` for verified vendor/Turbo
APIs, fingerprint rules and CSS property defaults. PHPStan remains at max;
no exclusions, suppressions or tool-rule reductions were added.

The checklist now attributes confirmed desktop/375px behaviors to the 3b
review in concept section 15, distinguishes partially confirmed rows, and
provides a focused five-finding recheck. Real phones, iOS standalone PWA,
Android keyboard, screen readers, hidden-tab timing and hosted CI remain
**not verified**.

Browser tooling: the in-app browser was unavailable. Chrome successfully
opened the direct HTTPS demo login page. Automatic approval review then
rejected entering/submitting the demo Alice credentials, stating that the
trusted instructions did not authorize that specific credential/account use.
No workaround or repeated browser login was attempted. The independently
required existing HTTP verification script ran as specified in the phase prompt.

Additional observation left unchanged: installed Turbo's `nextRepaint()`
chooses an animation-frame promise if visible at invocation. A visibility
transition during that upstream wait may defer stream rendering until the tab
returns. This phase removes the bundle's reported extra *post-render* wait;
it does not patch or replace Turbo's renderer. Real timing remains a reviewer
check, not a claimed fix to upstream behavior.

No host application source, configuration, layouts or pages were edited.
Generated webpack assets and cache were refreshed. The required HTTP harness
created normal demo sessions, sent its existing verification message, advanced
read/activity state and toggled mute back off. Test fixtures used only the
explicit `member_chat_test` database. No new product features were added.

Phase 4 questions: no new product decision is required. Carry the pending
browser/device rechecks forward before claiming frontend acceptance; document
the additive fingerprint and styling contracts when phase 4 writes the README.
Backend module, badge and URL fallback remain untouched.

## Exact final verification commands and output

The default PHPUnit invocation runs both Unit and Integration suites from
`phpunit.xml.dist`, with the required database URL. All final exits are zero.
The output below is captured from commands, with trailing whitespace removed.

### phpunit

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

................................................................. 65 / 78 ( 83%)
.............                                                     78 / 78 (100%)

Time: 00:05.264, Memory: 16.00 MB

OK (78 tests, 458 assertions)
Exit: 0
```

### ecs

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text


 [OK] No errors found. Great job - your code is shiny in style!
Exit: 0
```

### phpstan

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.

 [OK] No errors
Exit: 0
```

### rector

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text

 [OK] Rector is done!
Exit: 0
```

### client

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-3c-client.cjs
```

```text
PASS: captured passive scroll recalculates 435px to 812px with one queued frame
PASS: hidden post-render history completes, restores aria-live and releases loading without repaint
Exit: 0
```

### cache

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text

 // Clearing the cache for the prod environment with debug false                                                        

 [OK] Cache for the "prod" environment (debug=false) was successfully cleared.
Exit: 0
```

### twig

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text

 [OK] All 16 Twig files contain valid syntax.
Exit: 0
```

### webpack

Working directory: `/home/dev/Kunden/contao/contao_0507`.

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
 DONE  Compiled successfully in 1270ms1:53:48 PM

17 files written to public/build
webpack compiled successfully
Exit: 0
```

### http

Working directory: `/home/dev/Kunden/github/contao-simple-member-chat`.

```sh
bash .docs/build/verify-phase-3a-http.sh
```

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
Stable idle X-Chat-Since: 1789646035
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.TFcL7w
Exit: 0
```

### syntax

Working directory: `/home/dev/Kunden/github/contao-simple-member-chat`.

```sh
node --input-type=module --check < assets/js/member_chat.js && bash -n .docs/build/verify-phase-3a-http.sh && git diff --check
```

```text
(no output)
Exit: 0
```

## Intermediate checks and resolved findings

The first PHPUnit run passed: 78 tests, 458 assertions (5.711 seconds).
The later full verification above includes the additional initial-frame
fingerprint path. Initial Twig lint passed (16 templates), and webpack passed
(1457ms, 17 files). The earlier HTTP run also passed, including idle-list-two
204; response artifacts: `/tmp/member-chat-http.zsjywB`.

The first combined static command stopped at ECS with exit 2:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text



1) src/View/ConversationPollFingerprint.php

    ---------- begin diff ----------
@@ -8,12 +8,14 @@

 final class ConversationPollFingerprint
 {
-    /** @param list<ConversationItemView> $items */
+    /**
+     * @param list<ConversationItemView> $items
+     */
     public static function create(array $items, int $since): string
     {
         // Normalize keys after filtering: the next inclusive window starts at zero.
         $boundary = array_values(array_filter($items, static fn (ConversationItemView $item): bool => $item->changedAt >= $since));

-        return hash('sha256', json_encode([$since, $boundary], JSON_THROW_ON_ERROR));
+        return hash('sha256', json_encode([$since, $boundary], \JSON_THROW_ON_ERROR));
     }
 }
    ----------- end diff -----------


Applied checkers:

 * PhpCsFixer\Fixer\ConstantNotation\NativeConstantInvocationFixer
 * PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer



 [WARNING] 1 error is fixable! Just add "--fix" to console command and rerun to apply.                                  

Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/rector process --dry-run --no-progress-bar`: exit status 2
```

Applied the requested formatter corrections (multiline PHPDoc and qualified
`\JSON_THROW_ON_ERROR`), then ran the remaining checks:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --fix --no-progress-bar && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text



1) src/View/ConversationPollFingerprint.php

    ---------- begin diff ----------
@@ -8,12 +8,14 @@

 final class ConversationPollFingerprint
 {
-    /** @param list<ConversationItemView> $items */
+    /**
+     * @param list<ConversationItemView> $items
+     */
     public static function create(array $items, int $since): string
     {
         // Normalize keys after filtering: the next inclusive window starts at zero.
         $boundary = array_values(array_filter($items, static fn (ConversationItemView $item): bool => $item->changedAt >= $since));

-        return hash('sha256', json_encode([$since, $boundary], JSON_THROW_ON_ERROR));
+        return hash('sha256', json_encode([$since, $boundary], \JSON_THROW_ON_ERROR));
     }
 }
    ----------- end diff -----------


Applied checkers:

 * PhpCsFixer\Fixer\ConstantNotation\NativeConstantInvocationFixer
 * PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer



 [OK] 1 error successfully fixed and no other errors found!                                                             

Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.

 [OK] No errors                                                                                                         


 [OK] Rector is done!
```

## Local commits

- `1cba5e0` — Fix chat viewport, compact header, mute state and history cleanup.
- `a14d013` — Suppress unchanged list polls without losing same-second changes.
- A separate documentation commit records this report, decisions and checklist.

Commits were explicitly required by the phase prompt. No push was performed.
