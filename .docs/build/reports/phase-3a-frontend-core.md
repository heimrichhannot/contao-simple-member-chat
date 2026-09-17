# Phase 3a frontend core report

Completed on 2026-09-17. Phase 3b was not started. The six requested HTTP
routes, content element, templates, polling and baseline CSS are implemented.
No load-more controls, mute action, badge, relative dates, day separators,
iOS keyboard handler or new-message indicator were added.

## Files by concept section

- **0.2 / assets:** `composer.json`, `src/Asset/EncoreExtension.php`,
  `assets/js/member_chat.js`, `assets/css/member_chat.css`. Encore contracts
  required; project Turbo suggested. Missing Turbo logs both supported entry names.
- **5.1 / content element:** `src/Controller/ContentElement/MemberChatController.php`,
  `src/Service/ConversationAccess.php`, `contao/dca/tl_content.php`,
  `translations/contao_default.{en,de}.php`. UUID item consumption, voter,
  login/editor hints, initial read tracking, two rendered areas and private pages.
- **5.2–5.4 / HTTP and polling:** `src/Controller/{ChatFrameController,ChatActionController}.php`,
  `config/routes.yaml`, `src/Service/{ChatReader,ChatPageUrlGenerator}.php`,
  `src/View/TurboResponseFactory.php`, `src/EventListener/ChatResponseListener.php`.
  Full/incremental frames, message sending, contact search/start, 303 redirect,
  CSRF, 401/404 behavior, page tracking and error response cache policy.
- **5.3a / changed polling:** `src/Gateway/ConversationGateway.php`,
  `src/Domain/ConversationListItem.php`, `tests/Integration/GatewaysTest.php`.
  Inclusive `changedAt` cursors include read/mute changes from another connection.
- **5.6, 5.6a, 5.7 / rendering:** `src/View/{ChatContextFactory,ChatViewFactory}.php`,
  `src/View/Model/{ChatView,ConversationItemView,MessageView}.php`,
  `src/Twig/MessageRuntime.php`, `contao/templates/.twig-root`,
  `contao/templates/content_element/member_chat.html.twig`, ten partial/stream
  templates under `contao/templates/member_chat/`, `translations/messages.{en,de}.php`.
  One combined member batch, public view properties, safe autolinks, page-format
  times, empty status block, extensible blocks/attributes, labels and live log.
- **15 / phase 2 follow-up:** `src/Gateway/ContactGateway.php` memoizes the avatar
  projection, including absent columns. `tests/Unit/FrontendTest.php` covers
  this and views, item access, URL generation, escaping and response policy.
- **Verification:** `.docs/build/phase-3a-demo.php`, `phase-3a-webpack.cjs`,
  `verify-phase-3a-http.sh`, this report and the appended Phase 3a section in
  `../DECISIONS.md`.

## Decisions and limits

See [DECISIONS.md](../DECISIONS.md#phase-3a) for the vendor evidence and public
view contract. The important corrections to the concept are:

- `HtmlHeadBag` renders the Turbo no-cache meta in both layout types; both are
  confirmed in actual `<head>` output. No legacy-global workaround is needed.
- Read/mute changes do not change activity order. Remove/prepend responses are
  followed by a client sort on message time, with UUID as the equal-time tie-breaker.
- A send response must not advance the polling cursor past unseen incoming
  messages. Only delivered polling windows advance it. Empty polls record
  activity without trusting a supplied cursor as the read position.
- Contao-generated errors need a response listener to retain `no-store`; normal
  factory responses alone do not cover failures before controller execution.
- The same requested type/category key uses `CTE.member_chat.0` for both labels,
  supported by Contao's array-valued option/group reference handling.
- Legacy Encore must be enabled in the layout, and a newly registered entry must
  be built. The host's original legacy layout had Encore disabled; its original
  modern test layout had no main-slot content. Separate demo layouts fix these
  demo prerequisites without modifying existing layouts.

**Not verified:** browser interaction (actual Turbo polling, backoff, background
pause, visibility, search debounce, focus restoration, scroll, desktop Enter and
touch input), responsive appearance on real devices, hosted CI, custom theme
block overrides and production asset builds. JavaScript passed syntax parsing
and the real development webpack build; HTTP/Twig checks do not prove browser
behavior. No phase 3b browser checklist or iOS implementation is claimed.

## Exact commands and shortened results

DDEV commands ran in `/home/dev/Kunden/contao/contao_0507`. Other commands ran
in `/home/dev/Kunden/github/contao-simple-member-chat`. Each result below is
observed output, not a proposed command.

### Composer

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && composer require heimrichhannot/contao-encore-contracts --no-interaction'
```

```text
Lock file operations: 1 install, 0 updates, 0 removals
  - Locking heimrichhannot/contao-encore-contracts (1.5.0)
  - Installing heimrichhannot/contao-encore-contracts (1.5.0): Extracting archive
Generating autoload files
No security vulnerability advisories found.
Using version ^1.5 for heimrichhannot/contao-encore-contracts
```

Composer also reported the existing abandoned `doctrine/annotations` package.
The lock file remains ignored by the repository's existing policy. The suggest
reason was added to `composer.json`; no host composer source file was changed.

### PHPUnit and formatting fix pass

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --no-progress-bar > /tmp/member-chat-phase3a-rector.log 2>&1 && vendor/bin/ecs check --fix --no-progress-bar > /tmp/member-chat-phase3a-ecs.log 2>&1 && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
................................................................. 65 / 73 ( 89%)
........                                                          73 / 73 (100%)
Time: 00:05.618, Memory: 16.00 MB
OK (73 tests, 400 assertions)
```

Both suites ran against the existing dedicated `member_chat_test` database:
53 unit tests and 20 integration tests, no skips or warnings. Initial attempts
exposed the adapter mock signature and the need to mock explicit `__call`;
those fixture mistakes were corrected. The first PHPStan run found dynamic
static-adapter calls and a redundant nullsafe expression; code was corrected
without suppressions or changing level `max`.

### ECS, PHPStan, Rector (independent final checks)

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text
[OK] No errors found. Great job - your code is shiny in style!
```

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
[OK] No errors
```

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text
[OK] Rector is done!
```

No new rule skips were added. PHP tools retain their existing PHP-file scope;
Twig and JavaScript are checked with their applicable tools below.

### Host cache, routes, templates and assets

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
[OK] Cache for the "prod" environment (debug=false) was successfully cleared.
```

```sh
ddev exec php bin/console debug:router --env=prod | rg member_chat
```

```text
contao_member_chat_message_create       POST /_member_chat/conversations/{uuid}/messages
contao_member_chat_conversation_create  POST /_member_chat/conversations
contao_member_chat_conversations        GET  /_member_chat/conversations
contao_member_chat_messages             GET  /_member_chat/conversations/{uuid}/messages
contao_member_chat_compose              GET  /_member_chat/conversations/{uuid}/compose
contao_member_chat_contacts             GET  /_member_chat/contacts
```

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text
[OK] All 11 Twig files contain valid syntax.
```

```sh
node --input-type=module --check < assets/js/member_chat.js
```

Exit 0, no output. The final script additionally passed this real DDEV build:

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
DONE  Compiled successfully in 1385ms
17 files written to public/build
webpack compiled successfully
```

The temporary build configuration adds the entry to the existing Encore
configuration in memory. It updates generated `public/build` assets/manifest,
not host `webpack.config.js`, `encore.bundles.js` or package manifests. Normal
project installation should run its usual Encore entry preparation/build.

### Migration dry run

```sh
ddev exec php bin/console contao:migrate --schema-only --dry-run --env=prod --no-interaction
```

```text
Pending database migrations (dd7a732e1b5977f5eb749447d3e1c04ee558d76636326608586cbbf4d571f02e)
 * DROP TABLE tl_newsletter_deny_list
 * DROP TABLE tl_newsletter
 * DROP TABLE tl_filecredit_page
 * DROP TABLE tl_newsletter_channel
 * DROP TABLE tl_newsletter_recipients
 * DROP TABLE tl_filecredit
 * ALTER TABLE tl_files DROP copyright, DROP copyrightUrl
 * ALTER TABLE tl_member DROP newsletter
 * ALTER TABLE tl_news DROP dateAdded
 * ALTER TABLE tl_user DROP newsletters, DROP backendLostPasswordActivation
 * ALTER TABLE tl_user_group DROP newsletters
```

Exit 0. Additional output proposed the same unrelated legacy column drops on
`tl_content`, `tl_module` and `tl_page` reported in phases 1/2. There is no chat
schema change. No migration was applied.

### Demo fixtures and the curl session

```sh
ddev exec php /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-demo.php
```

```json
{
    "foreignUuid": "01a0ae9e-4c90-7a3e-a0e8-ad8caacf4c85",
    "legacyLayout": 28,
    "modernLayout": 27,
    "group": 4,
    "members": {"alice": 9, "bob": 10, "carol": 11},
    "pages": {"legacy": 86, "modern": 87}
}
```

The exact repeatable curl session is in
[verify-phase-3a-http.sh](../verify-phase-3a-http.sh). It extracts all hidden
fields from Contao's real login form, logs in both members with separate cookie
jars, extracts `REQUEST_TOKEN` from the returned form and posts it. It never
inserts a session or bypasses authentication. Tokens/cookies are not pasted
into this report. The command executed was:

```sh
bash .docs/build/verify-phase-3a-http.sh
```

The script's actual curl invocation is:

```sh
curl -ksS -D "$out/$name.headers" -o "$out/$name.html" "$@"
```

Its login and required route commands (the `request` helper records/checks HTTP
status and delegates to that curl invocation) are:

```sh
request "$member-login-form" 200 -c "$out/$member.cookies" "$base/phase3a-chat-legacy.html"
request "$member-login" 302 -b "$out/$member.cookies" -c "$out/$member.cookies" --data-binary "@$out/login-data" "$base/phase3a-chat-legacy.html"
request search 200 -b "$out/alice.cookies" "$base/_member_chat/contacts?page=86&q=Chat"
request open 303 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&member=10' "$base/_member_chat/conversations"
request list 200 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86"
request messages 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86"
request compose 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/compose?page=86"
request send 200 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86' --data-urlencode 'text=Phase 3a verification https://example.org/?a=1&b=2 <script>alert(1)</script>' "$base/_member_chat/conversations/$chat_uuid/messages"
request incremental 200 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=0"
request empty-poll 204 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=$after"
request list-poll 200 -b "$out/alice.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations?page=86&since=0"
request foreign 404 -b "$out/alice.cookies" "$base/_member_chat/conversations/$foreign_uuid/messages?page=86"
```

Here `base=https://contao0507.contao.hhdev`,
`chat_uuid=01a0ae9d-2c0d-7b36-8209-a415ae1e4ed5`, and
`foreign_uuid=01a0ae9e-4c90-7a3e-a0e8-ad8caacf4c85`. The script contains the
exact extraction commands and additional negative checks. Final output:

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
legacy: head meta, Encore asset and all four frames verified
modern: head meta, Encore asset and all four frames verified
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.M4p7OB
```

Shortened response examples:

```text
HTTP/2 303
cache-control: no-store, private
location: /phase3a-chat-legacy/01a0ae9d-2c0d-7b36-8209-a415ae1e4ed5.html
vary: Accept
```

```html
<turbo-stream action="append" target="chat-messages"><template>
<article id="chat-message-1" class="member-chat__message member-chat__message--own" data-chat-message-id="1">
<strong>Chat Alice Phase3a</strong>
<p>Hello Bob! <a href="https://example.org/?a=1&amp;b=2" rel="noopener nofollow" target="_blank">https://example.org/?a=1&amp;b=2</a> &lt;script&gt;alert(1)&lt;/script&gt;</p>
<time datetime="2026-09-17T11:06:14+02:00">2026-09-17 11:06</time>
</article></template></turbo-stream>
<turbo-stream action="replace" target="chat-compose"><template><turbo-frame id="chat-compose" class="member-chat__compose">
```

The message example is from the first successful send, before the repeatable
session. The final script verifies equivalent escaping, an empty textarea in
the success replacement and preserved spaces with HTTP 422 on validation failure.
204 bodies are empty. Actual source URLs carry `page=86`/`page=87`. Rendered
legacy and modern heads each contain exactly one:

```html
<meta name="turbo-cache-control" content="no-cache">
```

A readback verified persistent read/page tracking:

```sh
ddev exec mysql -e 'SELECT id,pid,title FROM tl_article WHERE pid IN (86,87); SELECT id,pid,type FROM tl_content WHERE pid IN (SELECT id FROM tl_article WHERE pid IN (86,87)); SELECT member,lastReadMessageId,lastPageId FROM tl_chat_participant ORDER BY pid,member;'
```

```text
id  pid title
98  86  Phase 3a chat
99  87  Phase 3a chat
id  pid type
478 98  login
479 98  member_chat
480 99  login
481 99  member_chat
member lastReadMessageId lastPageId
9      3                 86
10     4                 86
10     0                 0
11     0                 0
```

Earlier failed probes are not counted as acceptance: the bare project name
`contao0507.contao` did not resolve (DDEV reports the `.hhdev` URL); an initial
login omitted `_target_path` and received 400; copying a layout required quoted
SQL column names because `rows` is reserved. The fixture script and final login
session correct those issues. The initial modern page returned 500 until the
new Encore entry was built. The legacy asset assertion then identified that
Encore was disabled in the source demo layout; separate layout 28 enables it.

## Host changes

No host application PHP, Twig, JS, DCA or build configuration source was edited.
Authorized demo data and generated artifacts are:

- Group 4, `Phase 3a chat demo`.
- Members 9/10/11, usernames `phase3a-chat-alice`, `phase3a-chat-bob`,
  `phase3a-chat-carol`, demo-only `example.invalid` email addresses. Carol
  supplies a real nonparticipant authorization case. Credentials are local
  demo fixtures in `phase-3a-demo.php`.
- Hidden published pages 86/87, aliases `phase3a-chat-legacy` and
  `phase3a-chat-modern`; articles 98/99; login elements 478/480 and chat
  elements 479/481.
- Separate layout copies 28 (legacy with Encore enabled and project Turbo)
  and 27 (modern with a main article slot and project Turbo). Existing layouts
  21 and 26 remain untouched.
- Alice/Bob conversation `01a0ae9d-2c0d-7b36-8209-a415ae1e4ed5` with four
  verification messages at report time; Bob/Carol foreign conversation
  `01a0ae9e-4c90-7a3e-a0e8-ad8caacf4c85` without messages. Participant read
  timestamps and page IDs changed through the HTTP checks.
- The already existing `config/config.yaml` gained only this demo configuration:

```yaml
# Phase 3a member chat demo.
contao_member_chat:
  providers:
    member_groups:
      groups: [4]
```

- Rebuilt generated `public/build` assets (17 files), cache clears, normal demo
  login sessions, and the existing isolated test database fixtures. Temporary
  curl cookie jars/token files are removed by the verification script.

## Phase 3b handoff

No product decision is blocked. Remaining work belongs to the supplied phase 3b
scope: older-item loading, mute controls, relative times/day separators,
iOS keyboard handling, incoming-message scroll indicators and remaining
accessibility/browser validation. Preserve the exposed data attributes and
cursor rules when adding these features. Real multi-tab/device interaction,
including focus after stream replacement and error-frame rendering, must be
checked in a browser. Full URL fallback policy and README belong to phase 4.

## Commits

- `552e5f2` — Track participant changes in chat polling and cache avatar columns.
- `5ffa0a8` — Add member chat content element, HTTP frames and incremental polling.
- The final documentation commit adds the vendor decisions, demo/build/HTTP
  reproduction scripts and this report. No push was performed.

Final repository/script checks:

```sh
bash -n .docs/build/verify-phase-3a-http.sh
git diff --check
```

Both exited 0 with no output.
