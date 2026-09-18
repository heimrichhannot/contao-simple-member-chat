# Phase 6 — Backend list fixes

Completed 2026-09-18. Exactly the two backend defects were fixed. The required
DDEV checks passed, including 100 PHPUnit tests / 702 assertions across both
suites. Frontend and host application source are unchanged. No backend login or
authenticated UI acceptance is claimed.

## Defect 1: conversation list HTTP 500

`ListLabelLabelListener` now passes an ordered list of member/member/time values;
English and German `tl_chat_conversation.summary` strings use `%s`. The existing
Contao domain and complete-label escaping remain intact. No new feature or
translation domain was introduced.

`BackendLabelsTest::testConversationLabelsUseContaoFormattingInBothLanguages`
executes the actual Contao `Translator::trans()` method, substituting only its
catalogue-loading method with a catalogue populated from the real translation
files. It covers both languages, missing members, escaped member names, literal
percent signs, a positive timestamp and the existing empty-conversation em dash.
Batch member lookups remain once per table/request. Restoring the old English
translation temporarily made this test fail at the same vendor `vsprintf()`
line with `ValueError: Unknown format specifier`; the fixed file was restored
in a `finally` block before the complete successful suite run.

The host smoke also calls both registered label callbacks through the booted
container with deterministic, read-only rows and the actual translator. It
checks exact escaped output in both languages, using a separate request per
locale so request-scoped member labels are resolved afresh.

## Defect 2: raw timestamp in the child-list header

The parent conversation's `lastMessageAt` DCA field now declares
`eval.rgxp = datim`. Core `tl_article` timestamp fields demonstrate the metadata;
`DC_Table::parentView()` passes parent fields to `ValueFormatter`, which uses
configured `datimFormat` and `Date::parse()`. Zero becomes an empty value and the
core omits that header row. The SQL definition and sorting remain unchanged.

`tools/verify-host.php` now follows that actual header-field list and parent
DCA, calls the container's core formatter with the same DC context, and asserts
the configured formatted value and zero behavior. On this host, `1789653654`
renders as `2026-09-17 16:00` with English and German header labels. This is
executed service-level coverage of the header formatting, not just a metadata
assertion. Full backend Twig layout and authenticated HTTP rendering still need
the precise manual checks added to `.docs/BROWSER_CHECKLIST.md`.

## Translation audit and decisions

- The only other PHP `trans()` call targeting a `contao_*` domain is
  `src/Contact/ContactResolver.php::resolveMany()`:
  `MSC.member_chat.deleted_member`, empty parameters, `contao_default`.
  Both files contain plain text; no fix is needed. The new test and host smoke
  exercise this call as well.
- `DaySeparatorFactory` and frontend Twig translations use the normal messages
  domain. Their named parameters do not reach the Contao formatting branch.
  Conversation and message delete descriptions already use positional `%s`.
- Keep standard DCA metadata instead of adding a header callback. No registration
  changes, wrappers, schema migrations or frontend changes are needed.
- Vendor API evidence, including the callback invocation and formatter context,
  is appended under Phase 6 in `.docs/build/DECISIONS.md`.

## Limits, side effects and observations

**Not verified:** authenticated backend list/child-list UI, visual layout,
permissions, deletion/Undo reacceptance, hosted CI or browser/device acceptance.
The HTTP script's backend check is an unauthenticated 302 to login. It must not
be presented as an authenticated backend success.

No additional product defect was found in this scoped review. The existing
conversation/message row time format remains `Y-m-d H:i`; the header now uses
the host's configured format as requested. Core omits the zero-time header;
that behavior is intentionally retained. The unit regression isolates catalogue
loading, while the host check verifies actual language loading and services.

No host source/configuration/templates/fixtures were edited. Host tracked status
was clean before and after checks. Cache and generated `public/build` assets
were refreshed. PHPUnit rebuilds its dedicated `member_chat_test` database.
The existing HTTP harness uses existing demo accounts/pages, creates frontend
sessions, sends its verification message, updates read/activity state, and toggles
mute back off. The expanded PHP host smoke itself performs no data writes.

## Verification commands and captured output

Unless stated otherwise, commands ran from
`/home/dev/Kunden/contao/contao_0507`. All PHP and client/build checks ran in DDEV
project `contao0507.contao`. Output below has trailing whitespace removed.

### cache

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
// Clearing the cache for the prod environment with debug false

 [OK] Cache for the "prod" environment (debug=false) was successfully cleared.
Exit: 0
```

### phpunit

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

...............................................................  63 / 100 ( 63%)
.....................................                           100 / 100 (100%)

Time: 00:06.002, Memory: 18.00 MB

OK (100 tests, 702 assertions)

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

### webpack

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
DONE  Compiled successfully in 1062ms8:46:09 AM

19 files written to public/build
webpack compiled successfully

Exit: 0
```

### client3c

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-3c-client.cjs
```

```text
PASS: captured passive scroll recalculates 435px to 812px with one queued frame
PASS: hidden post-render history completes, restores aria-live and releases loading without repaint

Exit: 0
```

### client4

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4-client.cjs
```

```text
PASS: empty standalone badge sets its source, reloads repeatedly in full mode and pauses for hidden tab/navigation

Exit: 0
```

### client4b

```sh
ddev exec node /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-phase-4b-client.cjs
```

```text
PASS: restore
PASS: remaining
PASS: lifecycle
PASS: backoff
PASS: pending

Exit: 0
```

### host

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
en tl_chat_conversation: Deleted member ↔ Deleted member, last message: 2026-09-17 16:00
en tl_chat_message: Deleted member · 2026-09-17 16:00 · &lt;script&gt; &amp; verification
en header: {"Last message":"2026-09-17 16:00"}
de tl_chat_conversation: Gelöschtes Mitglied ↔ Gelöschtes Mitglied, letzte Nachricht: 2026-09-17 16:00
de tl_chat_message: Gelöschtes Mitglied · 2026-09-17 16:00 · &lt;script&gt; &amp; verification
de header: {"Letzte Nachricht":"2026-09-17 16:00"}
Conversation/message labels and parent header values rendered in English and German; zero timestamp omitted
Exit: 0
```

### host-syntax

```sh
ddev exec php -l /home/dev/Kunden/github/contao-simple-member-chat/tools/verify-host.php
```

```text
No syntax errors detected in /home/dev/Kunden/github/contao-simple-member-chat/tools/verify-host.php
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
Stable idle X-Chat-Since: 1789713994
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.f4qNH8

Exit: 0
```

### diff

Working directory: `/home/dev/Kunden/github/contao-simple-member-chat`.

```sh
git diff --check
```

```text
Exit: 0
```

### host-status

```sh
git -C /home/dev/Kunden/contao/contao_0507 status --short
```

```text
A  .ddev/config.yaml
AM config/config.yaml
?? .ddev/docker-compose.mounts.yaml
?? .env
?? .gitignore
?? composer.json
?? files/
?? ide-twig.json
?? package.json
?? public/
?? system/
?? templates/
?? var/
?? webpack.config.js
Exit: 0
```

## Intermediate checks and regression proof

The first targeted label run passed but emitted one PHPUnit notice because the
catalogue double had no expectation. Adding `expects(atLeastOnce())` removed
it; the final full-suite run above has no notices. Exact command:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit --filter BackendLabelsTest'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

N.                                                                  2 / 2 (100%)

Time: 00:00.026, Memory: 10.00 MB

OK, but there were issues!
Tests: 2, Assertions: 17, PHPUnit Notices: 1.
Exit: 0
```

ECS applied only array formatting and import ordering to the new test and DCA
metadata. The final clean check is above; correction command/output:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --fix --no-progress-bar'
```

```text
1) contao/dca/tl_chat_conversation.php

    ---------- begin diff ----------
@@ -84,7 +84,9 @@
             ],
         ],
         'lastMessageAt' => [
-            'eval' => ['rgxp' => 'datim'],
+            'eval' => [
+                'rgxp' => 'datim',
+            ],
             'sql' => [
                 'type' => 'integer',
                 'unsigned' => true,
    ----------- end diff -----------


Applied checkers:

 * PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer
 * PhpCsFixer\Fixer\Whitespace\ArrayIndentationFixer
 * Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer



2) tests/Unit/BackendLabelsTest.php

    ---------- begin diff ----------
@@ -18,14 +18,17 @@
 use PHPUnit\Framework\TestCase;
 use Symfony\Component\HttpFoundation\Request;
 use Symfony\Component\HttpFoundation\RequestStack;
+use Symfony\Component\Translation\MessageCatalogue;
 use Symfony\Contracts\Translation\TranslatorInterface;
-use Symfony\Component\Translation\MessageCatalogue;

 final class BackendLabelsTest extends TestCase
 {
     public function testConversationLabelsUseContaoFormattingInBothLanguages(): void
     {
-        foreach (['en' => 'last message', 'de' => 'letzte Nachricht'] as $locale => $wording) {
+        foreach ([
+            'en' => 'last message',
+            'de' => 'letzte Nachricht',
+        ] as $locale => $wording) {
             // Only catalogue loading is isolated; trans() executes Contao's real vsprintf path.
             $translator = $this->getMockBuilder(Translator::class)
                 ->disableOriginalConstructor()
@@ -39,13 +42,20 @@
             $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([7, 9]);
             $gateway = $this->createMock(ContactGatewayInterface::class);
             $gateway->expects(self::once())->method('findMembers')->with([7, 9])->willReturn([
-                ['id' => 7, 'username' => '<Alice> 100%'],
+                [
+                    'id' => 7,
+                    'username' => '<Alice> 100%',
+                ],
             ]);
             $resolver = new ContactResolver($gateway, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), $translator);
             $listener = new ConversationLabelListener(new MemberLabels($connection, $resolver, new RequestStack([Request::create('/contao')])), $translator);
             $deleted = $translator->trans('MSC.member_chat.deleted_member', [], 'contao_default');
             foreach ([1789653654, 0] as $timestamp) {
-                $label = $listener(['memberLow' => 7, 'memberHigh' => 9, 'lastMessageAt' => $timestamp]);
+                $label = $listener([
+                    'memberLow' => 7,
+                    'memberHigh' => 9,
+                    'lastMessageAt' => $timestamp,
+                ]);
                 self::assertSame('&lt;Alice&gt; 100% ↔ ' . $deleted . ', ' . $wording . ': ' . ($timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '—'), $label);
             }
         }
    ----------- end diff -----------


Applied checkers:

 * PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer
 * PhpCsFixer\Fixer\Import\OrderedImportsFixer
 * PhpCsFixer\Fixer\Whitespace\ArrayIndentationFixer
 * Symplify\CodingStandard\Fixer\ArrayNotation\ArrayListItemNewlineFixer
 * Symplify\CodingStandard\Fixer\ArrayNotation\ArrayOpenerAndCloserNewlineFixer



 [OK] 2 errors successfully fixed and no other errors found!
Exit: 0
```

With only the English summary temporarily restored to the original named
placeholders, the following command failed as expected (translation restored
in `finally`). The first Python capture failed decoding the invalid byte in
PHP's error text; the repeat captured bytes and replaced invalid UTF-8 for this
report. Neither attempt left the old translation in place.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit --filter testConversationLabelsUseContaoFormattingInBothLanguages'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

E                                                                   1 / 1 (100%)

Time: 00:00.020, Memory: 10.00 MB

There was 1 error:

1) HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\BackendLabelsTest::testConversationLabelsUseContaoFormattingInBothLanguages
ValueError: Unknown format specifier "�"

/home/dev/Kunden/github/contao-simple-member-chat/vendor/contao/core-bundle/src/Translation/Translator.php:57
/home/dev/Kunden/github/contao-simple-member-chat/src/EventListener/DataContainer/ChatConversation/ListLabelLabelListener.php:26
/home/dev/Kunden/github/contao-simple-member-chat/tests/Unit/BackendLabelsTest.php:54

ERRORS!
Tests: 1, Assertions: 1, Errors: 1.
Failed to execute command `cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit --filter testConversationLabelsUseContaoFormattingInBothLanguages`: exit status 2

Exit: 2
```

## Commits

- `ba839e7` — Fix Contao formatting in conversation labels (listener,
  translations, real-formatter regression).
- `1893124` — Format moderation header timestamps and verify host rendering
  (DCA metadata and booted-host assertions).
- A final documentation commit records decisions, manual checks and this report.
  No push was performed.

The first local `git add` could not create `.git/index.lock` because the sandbox
mounts `.git` read-only. Retrying with escalated permissions succeeded; the
Phase 6 prompt explicitly requests small local commits.
