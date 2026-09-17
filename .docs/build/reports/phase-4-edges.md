# Phase 4 — Edges

Implemented 2026-09-17. URL policy, unread badge, backend moderation,
integration API preparation and documentation are complete. Required automated
checks pass. No browser login or new browser acceptance is claimed; phase 5
remains a separate repository/package. PHPStan remains at max with no new
exclusions or suppressed rules.

## Files by concept section

- **7 / decision 4:** `src/Service/ConversationUrlGenerator.php` replaces the
  phase 3a placeholder. `ParticipantGateway::{state,lastPageId}` and its
  interface supply conversation/member tracking; controller/context/view
  consumers use the same generator. Known-page `generate()` retains the old
  no-lookup path. `tests/Unit/ConversationUrlGeneratorTest.php`, updated
  frontend/gateway fixtures and `BackendDeletionTest::testLatestTrackedPageAndPublicRecipientState`
  cover fallback/clearing/state behavior.
- **5.5 / 5.6a:** `src/Twig/ChatRuntime.php`,
  `src/Controller/UnreadBadgeController.php`,
  `contao/templates/member_chat/unread_badge.html.twig`, message translations,
  and `ChatResponseListener` provide authenticated/private rendering, empty
  zero-count frames, translated counts, locale preservation and poll settings.
  `EncoreExtension`, `assets/js/member_chat_entry.js` and the shared
  `assets/js/member_chat.js` separate CSS from the badge entry. Full-mode badge
  polling is covered by `tests/Unit/UnreadBadgeTest.php`, the HTTP harness and
  `.docs/build/verify-phase-4-client.cjs`.
- **9:** `contao/config/config.php`, conversation/message DCA, module/table
  translations, `src/Backend/MemberLabels.php`, and attribute callbacks under
  `src/EventListener/DataContainer/{ChatConversation,ChatMessage}/` provide
  moderation. `tests/Integration/BackendDeletionTest.php` covers pre-delete
  repair for middle/tail/final messages in MariaDB. `BackendLabelsTest` proves
  a single member batch across multiple rows and escaped missing-member/text
  labels. Participants have no module.
- **8.2 / decision 3:** comments on the three events, Conversation/Message and
  gateway read APIs identify stable contracts. README explains queued recipient
  eligibility and fresh state reads. No Messenger message/handler, PWA
  dependency or bridge implementation was introduced.
- **12 / 12b / 15:** `README.md`, `CHANGELOG.md`, appended Phase 4 decisions,
  updated browser checklist; `.docs/build/verify-host.php` moved to
  `tools/verify-host.php` and extended with real container/DCA/Twig smoke checks.
  The build adapter and HTTP script include badge assets/route and an anonymous
  backend authentication-boundary smoke check.

## Decisions and limits

Detailed installed-vendor evidence is in `../DECISIONS.md#phase-4`.

- Published roots use `PageModel::findPublishedRootPages()` with preview disabled
  and explicit sorting/ID order. The concept did not specify deterministic
  ordering or which participant row `listPage()` should use. Latest tracked
  positive page wins there; invalid destinations fall back to usable roots.
  Content-element page links/redirects use their known page and add no row query.
- `ondelete` is a **before-delete** callback. Its conditional atomic repair
  excludes the still-existing target message. Participant change timestamps
  make decreased tail/count values visible to inclusive list polling.
- The existing narrow `ParticipantGatewayInterface::state()` is the public
  read-only recipient-state API; an extra service would be a trivial wrapper.
  Missing/muted recipients must be skipped, and queued handlers must re-read
  message/state. Activity tracking and page changes retain the throttle delay.
- Badge-only pages explicitly enable `huh_member_chat_badge` plus Turbo and the
  Drive no-cache meta described in README. The shared script contains no CSS;
  the full chat entry imports it through a CSS wrapper. Actual built entrypoint
  metadata confirms badge CSS is absent and chat CSS remains.
- Backend label batching scans distinct IDs across each table once per request.
  This avoids N+1 member reads at the expected small-site scale; large-data
  behavior has not been benchmarked.
- The later concept section 15 records reviewer-confirmed phase 3c browser
  behavior. The checklist now distinguishes it from the earlier report's
  pending status. No new browser results are inferred from this phase's tests.

**Not verified:** authenticated backend UI/permissions/deletion/Undo, concurrent
backend moderation, a real badge-only page in the browser, screen readers,
real iOS/Android keyboard and standalone PWA behavior, project template/CSS
customization, production webpack builds, hosted CI and any push delivery.
The HTTP backend smoke proves only the unauthenticated redirect; the host PHP
smoke proves module/callback/Twig registration. The database test invokes the
real delete callback in core order but does not click through DC_Table's UI.
Already-open message logs need reload after moderation (no tombstone streams).
Contao Undo/backups may retain deleted content; restoration is not verified.

## Host/demo effects

No host application source, configuration, templates, layouts, pages or member
fixtures were edited or created. No migrations were applied and no new schema
columns were introduced. The required HTTP harness used its existing demo
members/pages, created ordinary login sessions, sent its verification message,
updated delivered read/activity/page tracking, and toggled mute back off.
The dedicated `member_chat_test` database was rebuilt by both-suite PHPUnit
runs. Generated host `public/build` assets/manifest and cache were refreshed.
No browser login was attempted. Demo HTTP checks used the existing direct
`https://127.0.0.1:32773` endpoint successfully.

## Phase 5 handoff

In the separate bridge repository, consume `MessageSentEvent`, enqueue the
message ID/recipient IDs using the appropriate Contao low-priority Messenger
contract, then load current message/participant/subscriber state and resolve
recipient deep links. Implement optional recent-activity/already-read
suppression and missing/muted-recipient checks there. PWA click payloads,
subscriber selection, delivery retries and worker behavior still need source
verification and end-to-end acceptance. This package adds none of those classes.

## Verification commands and observed output

All PHP tools ran inside `ddev` from
`/home/dev/Kunden/contao/contao_0507`. PHPUnit uses `phpunit.xml.dist`, which runs
both Unit and Integration suites, with the dedicated database URL. Logs below
are captured output with trailing whitespace removed. Successful checks without
an explicit Exit line printed success and returned normally; they are not
inferred from a different check. Webpack completed before the HTTP harness.

### PHPUnit (both suites)

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

................................................................. 65 / 85 ( 76%)
....................                                              85 / 85 (100%)

Time: 00:09.333, Memory: 16.00 MB

OK (85 tests, 526 assertions)
Exit: 0
```

### ECS

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text
[OK] No errors found. Great job - your code is shiny in style!

Exit: 0
```

### PHPStan

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.

 [OK] No errors

Exit: 0
```

### Rector

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text
[OK] Rector is done!

Exit: 0
```

### Host cache

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
// Clearing the cache for the prod environment with debug false

 [OK] Cache for the "prod" environment (debug=false) was successfully cleared.
```

### Twig

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text
[OK] All 17 Twig files contain valid syntax.
```

### Webpack

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
DONE  Compiled successfully in 999ms2:13:16 PM

19 files written to public/build
webpack compiled successfully
```

### Host module and Twig smoke

```sh
ddev exec php /home/dev/Kunden/github/contao-simple-member-chat/tools/verify-host.php
```

```text
{
    "root": true,
    "rootfallback": true,
    "label": [
        "Chat-Seite",
        "Wählen Sie die Chat-Seite für diesen Startpunkt."
    ]
}
Backend module, read-only lists, label/deletion callbacks and anonymous Twig badge verified
```

### Moved tool syntax

```sh
ddev exec php -l /home/dev/Kunden/github/contao-simple-member-chat/tools/verify-host.php
```

```text
No syntax errors detected in /home/dev/Kunden/github/contao-simple-member-chat/tools/verify-host.php
Exit: 0
```

### Client regressions

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4-client.cjs
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-3c-client.cjs
```

```text
PASS: empty standalone badge sets its source, reloads repeatedly in full mode and pauses for hidden tab/navigation
PASS: captured passive scroll recalculates 435px to 812px with one queued frame
PASS: hidden post-render history completes, restores aria-live and releases loading without repaint
```

### HTTP (bundle working directory)

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
Stable idle X-Chat-Since: 1789647239
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.kGnQVg
```

### Asset and syntax inspection (bundle working directory)

```sh
python3 - <<'PYCODE'
import json
from pathlib import Path
p=Path('/home/dev/Kunden/contao/contao_0507/public/build/entrypoints.json')
x=json.loads(p.read_text())['entrypoints']
assert not x['huh_member_chat_badge'].get('css')
assert x['huh_member_chat'].get('css')
print('Badge entry has no CSS; chat entry retains CSS.')
PYCODE
node --input-type=module --check < assets/js/member_chat.js
bash -n .docs/build/verify-phase-3a-http.sh
git diff --check
```

```text
Badge entry has no CSS; chat entry retains CSS.
(other commands: no output, exit 0)
```

`ddev describe -j` was run from the host directory to inspect the active
project. An initial attempt in the bundle directory failed because it contains
no `.ddev/config.yaml`; the host invocation succeeded. The actual direct HTTPS
endpoint was confirmed by all HTTP responses above, not guessed from a hostname.

## Intermediate findings, all resolved

- Initial ECS fix pass formatted 14 files; later added tests required formatting.
  A final blank line in `FrontendTest` was removed and the clean ECS check above
  passed. No style rules were disabled.
- Initial PHPStan found mixed custom root-page field casts and one missing
  constructor argument in a gateway test. Added the precise field annotation
  and injected the participant gateway. A later pass found a nullable test
  assertion and a mixed test callback parameter; explicit assertions fixed both.
- The first new badge unit fixture escaped the entire HtmlAttributes object.
  The installed Contao Twig extension marks that class safe; the isolated Twig
  fixture now mirrors it. The real HTTP badge markup was already correct.
- Rector requested explicit instanceof return branches in the URL service,
  a test assignment newline and RequestStack constructor usage. Applied its
  changes, then ran the clean dry run above.
- Earlier PHPUnit pass before the badge fixture: 83 tests, 493 assertions.
  After the fixture correction: 84 tests, 519 assertions. The final backend
  batch-label test yields the 85 tests / 526 assertions recorded above.

Commands used for the correction passes (same host working directory):

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --fix --no-progress-bar'
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --no-progress-bar && vendor/bin/ecs check --fix --no-progress-bar'
```

The following captured failed-check outputs document the actual intermediate
findings. Their commands are the corresponding PHPUnit/PHPStan/Rector commands
listed in the final verification section above.

### Intermediate `phase4-phpstan-first.log`

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
 ------ ------------------------------------------
  Line   src/Service/ConversationUrlGenerator.php
 ------ ------------------------------------------
  68     Cannot cast mixed to int.
         🪪  cast.int
  73     Cannot cast mixed to int.
         🪪  cast.int
 ------ ------------------------------------------

 ------ ---------------------------------------------------------------------------------------------------------------------------------
  Line   tests/Integration/GatewaysTest.php
 ------ ---------------------------------------------------------------------------------------------------------------------------------
  215    Class HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator constructor invoked with 2 parameters, 3 required.
         🪪  arguments.count
 ------ ---------------------------------------------------------------------------------------------------------------------------------

 [ERROR] Found 3 errors

Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: exit status 1
```

### Intermediate `phase4-phpstan.log`

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
 ------ --------------------------------------------------------------------------------------------------------------------------------
  Line   tests/Integration/BackendDeletionTest.php
 ------ --------------------------------------------------------------------------------------------------------------------------------
  40     Using nullsafe property access on non-nullable type HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation. Use -> instead.
         🪪  nullsafe.neverNull
 ------ --------------------------------------------------------------------------------------------------------------------------------

 ------ ---------------------------------------------------------------------
  Line   tests/Unit/ConversationUrlGeneratorTest.php
 ------ ---------------------------------------------------------------------
  38     Binary operation "." between '/chat' and mixed results in an error.
         🪪  binaryOp.invalid
 ------ ---------------------------------------------------------------------

 [ERROR] Found 2 errors

Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: exit status 1
```

### Intermediate `phase4-phpunit.log`

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

..........................................................F...... 65 / 84 ( 77%)
...................                                               84 / 84 (100%)

Time: 00:06.123, Memory: 16.00 MB

There was 1 failure:

1) HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\UnreadBadgeTest::testBadgeRendersZeroPositiveAndAnonymousWithoutLosingPolling
Failed asserting that '<turbo-frame class=&quot;nav&amp;quot;badge&quot; id=&quot;chat-unread&quot; lang=&quot;en&quot; aria-live=&quot;off&quot; data-chat-poll=&quot;badge&quot; data-chat-mode=&quot;full&quot; data-chat-url=&quot;/_member_chat/unread?_locale=en&quot; data-chat-poll-interval=&quot;30000&quot; data-chat-poll-max-interval=&quot;240000&quot;></turbo-frame>\n
' [ASCII](length: 350) contains "id="chat-unread"" [ASCII](length: 16).

/home/dev/Kunden/github/contao-simple-member-chat/tests/Unit/UnreadBadgeTest.php:65

FAILURES!
Tests: 84, Assertions: 495, Failures: 1.
Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit`: exit status 1
```

### Intermediate `phase4-rector.log`

```text
3 files with changes
====================

1) src/Service/ConversationUrlGenerator.php:29

    ---------- begin diff ----------
@@ Line 29 @@
     {
         $page = $this->resolvePage($this->participants->state($conversation->id, $memberId)['lastPageId'] ?? 0);

-        return $page === null ? null : $this->generate($page, $conversation->uuid);
+        return $page instanceof PageModel ? $this->generate($page, $conversation->uuid) : null;
     }

     /**
@@ Line 39 @@
     {
         $page = $this->resolvePage($this->participants->lastPageId($memberId));

-        return $page === null ? null : $this->generate($page);
+        return $page instanceof PageModel ? $this->generate($page) : null;
     }

     private function resolvePage(int $pageId): ?PageModel
    ----------- end diff -----------

Applied rules:
 * FlipTypeControlToUseExclusiveTypeRector
 * FlipNegatedTernaryInstanceofRector


2) tests/Integration/BackendDeletionTest.php:21

    ---------- begin diff ----------
@@ Line 21 @@
         $conversation = $conversations->insert('01994daa-1111-7111-8111-111111111111', 7, 9, 100);
         $participants->add($conversation->id, 7, 100);
         $participants->add($conversation->id, 9, 100);
+
         $first = $messages->insert($conversation->id, 7, 'first', 101);
         $middle = $messages->insert($conversation->id, 7, 'middle', 102);
         $last = $messages->insert($conversation->id, 7, 'last', 103);
    ----------- end diff -----------

Applied rules:
 * NewlineBeforeNewAssignSetRector


3) tests/Unit/UnreadBadgeTest.php:37

    ---------- begin diff ----------
@@ Line 37 @@
             ]), self::createStub(ContentUrlGenerator::class), $participants);
             $routes = self::createStub(UrlGeneratorInterface::class);
             $routes->method('generate')->willReturn('/_member_chat/unread?_locale=en');
-            $requests = new RequestStack();
             $request = Request::create('/ordinary-page');
             $request->setLocale('en');
-            $requests->push($request);
+            $requests = new RequestStack([$request]);
             $runtime = new ChatRuntime($this->memberProvider($count < 0 ? 0 : 7), $participants, $urls, $routes, new ChatOptions(), $requests);
             $loader = new FilesystemLoader();
             $loader->addPath(__DIR__ . '/../../contao/templates', 'Contao');
    ----------- end diff -----------

Applied rules:
 * PushRequestToRequestStackConstructorRector


 [OK] 3 files would have been changed (dry-run) by Rector

Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar`: exit status 2
```

## Local commits

- `09bb559` — Resolve conversation destinations and publish integration read contracts.
- `c13be3d` — Add backend conversation moderation and repair deleted message tails.
- `bf90c56` — Render and poll a standalone unread badge without chat styles.
- A final documentation commit adds README/changelog, this report, API decisions,
  the updated browser checklist and the moved host verification tool.

The phase prompt explicitly required these local commits. No push was performed.
