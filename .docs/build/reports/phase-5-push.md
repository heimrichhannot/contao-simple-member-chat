# Phase 5 — Optional PWA push integration

Implemented 2026-09-18. No separate package, PWA source changes, host application
source changes or remote push to Git were made.

Implementation commit: `bce2f11` (`Add optional queued PWA notifications for chat messages`).
Test commit: `a76e0f9` (`Verify optional push wiring and current recipient filtering`).
This report and the documentation are recorded in the subsequent documentation commit.

## Files by concept section

| Concept section | Files and outcome |
| --- | --- |
| 8.1 / 8.2, events and push | `src/Integration/Pwa/MessageSentListener.php`, `SendChatPushMessage.php`, `SendChatPushHandler.php`: attributed post-commit listener enqueues IDs; low-priority handler reloads current state, limits recipients, logs failures and continues. |
| 8.2, notification payload | `src/Integration/Pwa/ChatNotification.php`: sender display-name title, optional UTF-8 excerpt, optional `data.clickJumpTo`; real PWA getter serialization. |
| 13 decision 3, optional in-bundle integration | `composer.json`, `config/services.yaml`, `config/pwa.yaml`, `src/HeimrichHannotSimpleMemberChatBundle.php`: suggestion plus real-class development dependency, excluded optional namespace, conditional service registration and push configuration tree. |
| 13 decision 4, deep links / 15, stable API | `src/Service/ConversationUrlGenerator.php`: optional reference-type argument on `forConversation()` and `generate()`; existing absolute-path defaults retained, handler requests absolute URLs per recipient. |
| 13 decision 8, mute / phase 4 recipient state | `SendChatPushHandler.php` reuses `ParticipantGatewayInterface::state()`; no gateway, mute, read, cursor, data-attribute or view contract changes. |
| Tests | `tests/Unit/Pwa/{MessageSentListenerTest,SendChatPushHandlerTest,PwaContainerTest}.php`, `tests/PwaTestTrait.php`, `tests/Integration/PushRecipientTest.php`. |
| Documentation | `README.md`, `CHANGELOG.md`, `.docs/CONCEPT.md`, `.docs/build/DECISIONS.md`, this report. |

## Configuration strategy and behavior

A **fixed PWA configuration ID** deliberately limits delivery to the selected
site/PWA. Subscribers must match both that ID and an eligible event recipient.
It avoids unintentionally notifying subscriptions attached to other configurations.
The selected configuration must still exist and have `supportPush` enabled at
worker time. No automatic all-configurations mode is introduced.

Defaults are `push.enabled: false`, `push.configuration: 0` (no target),
`push.active_recipient_grace: 60` seconds and `push.body_length: 0` UTF-8
characters. Text excerpts are optional and bounded to 500 characters; zero
omits the body property. The title still exposes the sender's display name.
README documents all defaults/units and the privacy implications.

The event's recipient IDs bound delivery. The handler reloads the message and
conversation, checks current participant existence, mute and activity, rejects
the author, deduplicates IDs, and queries subscribers per recipient. It never
passes an empty subscriber list to PWA, whose sender would broadcast instead.
No current subscriber outside the recipient/configuration is sent a payload.
`lastReadAt` can lag actual activity by the 30-second default activity throttle,
plus polling/network delays. Grace zero disables activity suppression; no
additional permanent already-read suppression is applied.

The URL uses the recipient's current tracked page or existing published root
fallback. It is requested as absolute; missing destinations still allow sending
without `data`. Root domains/router context must be valid in workers. Original
URL defaults and rendered client contracts remain intact.

Listener bus exceptions are handled by the existing post-commit event logging.
Handler setup/recipient failures are logged and swallowed; later recipients are
still attempted. PWA logs individual endpoint failures internally, and its true
return value is not proof of delivery. Handled failures are not automatically
retried; duplicate queue redelivery can produce duplicate notifications.

## Verification scope and decisions

- **99 tests / 690 assertions**, both PHPUnit suites with the dedicated
  `member_chat_test` database; no skipped tests. This includes actual database
  changes after enqueue (mute, participant removal, activity, erasure), eligible
  delivery, recipient-specific absolute links, no-link/no-body payloads,
  UTF-8 truncation, empty/foreign subscriptions, failure continuation and queue
  transport selection without handler execution in the request.
- The present-PWA container test compiles the optional service graph using the
  real PWA classes and synthetic existing host services. The separate-process
  absent test blocks PWA autoloading and throws on any integration autoload;
  no optional definitions are present and the container compiles. It does not
  represent a full PWA-enabled Contao installation with device delivery.
- The real DDEV host, which has no PWA classes, clears its production cache and
  boots successfully. Its compiled container contains no push services,
  including removed private service IDs. The complete HTTP regression script
  passes there. This proves the existing chat remains operational without PWA.
- A separate production dependency installation under DDEV's
  `/tmp/member-chat-phase5-production` used `composer install --no-dev` without
  changing the repository's development installation. PWA is absent there and
  the bundle extension loads without push services even when push is enabled.
- ECS, PHPStan at the unchanged `max` level, Rector, all 17 Twig templates, all
  existing client regressions, webpack, Composer validation and diff checks pass.
- PWA 0.10.1 is a development dependency because tests mock its actual sender
  and exercise its actual notification/model classes; no fake vendor API is
  shipped. Its sender source matches the read-only sibling repository.
- Host public/build artifacts were regenerated as required, cache rebuilt, and
  the existing HTTP script used demo accounts/data (send, read and mute/unmute).
  The integration suite rebuilt its dedicated test tables. No host source or
  PWA bundle file was edited; existing host working-tree changes were preserved.
- All source evidence and unavailable PWA features are listed in the appended
  Phase 5 section of `DECISIONS.md`. No external API documentation was assumed.

## Concept corrections

Section 8.2 contradicted the revised decision 3 by still specifying an external
bridge; it now describes this optional integration. The uncertain click target
is resolved through the getter payload protocol, without creating a backend
notification record. The manager's low-priority transport routing is now
verified, superseding section 14's earlier unverified note. The required core
interface is deprecated since 5.6 and needs migration for Contao 6.

The phase 4 URL helper default was an absolute **path**, not an absolute URL;
its new optional reference type addresses push without changing the default.
PWA's falsey subscriber fallback is a broadcast hazard and is guarded. Its
`sendWithLog()` boolean is not a device success guarantee; expired subscription
cleanup remains TODO upstream. PWA only suggests the Web Push library.

## What real end-to-end acceptance still needs

**Not verified:** real push delivery, full PWA-enabled host boot, actual worker
to browser delivery/click behavior, iOS/Android/standalone PWA, production asset
builds and hosted CI. No VAPID credentials or subscriber secrets were read, and
no real push was sent.

An end-to-end setup needs the enabled PWA bundle and compatible
`minishlink/web-push`, valid VAPID credentials, a selected push-enabled
configuration, an installed service worker on an HTTPS origin, member-bound
device subscriptions with permission, and a running low-priority worker.
Confirm notification display and privacy, authenticated deep-link navigation,
no-link behavior, mute/activity suppression and failed/expired subscriptions on
the target devices. Set root domains/router default URI for CLI URL generation.

## Preliminary findings and corrections

Before the final verification below:

- The targeted unit run (`vendor/bin/phpunit --testsuite Unit --filter
  "Pwa|BundleConfiguration|ConversationUrlGenerator"` through DDEV in this
  repository) passed: `OK (14 tests, 94 assertions)`.
- The first full run using the same PHPUnit command below reached
  `Tests: 96, Assertions: 658, Errors: 1.` Its new database fixture failed with
  `SQLSTATE[22007]: Invalid datetime format: 1366 Incorrect string value:
  '\xF0\x9F\x8C\x8D!' for column member_chat_test.tl_chat_message.body at row 1`.
  The existing database fixture schema does not accept the four-byte emoji.
  Recipient filtering now uses plain text, and an independent handler unit
  test verifies UTF-8 truncation with that emoji. Production schema was unchanged.
- Initial PHPStan runs using the same command below reported a negated string
  `supportPush`, mixed callback/target test values, and an always-true interface
  assertion. Explicit boolean conversion and meaningful type assertions fixed
  these without changing the analysis level or adding exclusions.
- An intermediate full run passed `OK (97 tests, 686 assertions)` and PHPStan
  reported `[OK] No errors`. Two final tests then added actual bus transport
  routing and custom compiled options; the final run below is authoritative.
- DDEV ECS and Rector fix runs formatted the new files and applied their existing
  rules. The final read-only checks below pass. Commands were
  `vendor/bin/ecs check --fix --no-progress-bar` and
  `vendor/bin/rector process --no-progress-bar`, each run with
  `ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && …'`
  from the host directory. No quality configuration or vendor source was changed.
- The initial additional host absence command hit DDEV shell quoting
  (`bash: line 1: kernel: unbound variable`) before PHP ran. Passing the complete
  shell-quoted PHP invocation fixed it; the successful exact command is below.

## Exact final commands and captured output

Every required check below exited 0. Commands are shown with their working
directory. Captured output has only trailing whitespace removed. The no-dev
installation includes Composer's existing root-version/abandoned-package notices;
these did not prevent installation.

### phpunit

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/dev/Kunden/github/contao-simple-member-chat/phpunit.xml.dist

................................................................. 65 / 99 ( 65%)
..................................                                99 / 99 (100%)

Time: 00:10.896, Memory: 18.00 MB

OK (99 tests, 690 assertions)
Exit: 0
```

### ecs

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text


 [OK] No errors found. Great job - your code is shiny in style!

Exit: 0
```

### phpstan

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.

 [OK] No errors

Exit: 0
```

### rector

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text

 [OK] Rector is done!

Exit: 0
```

### twig

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec php bin/console lint:twig /home/dev/Kunden/github/contao-simple-member-chat/contao/templates --env=prod
```

```text

 [OK] All 17 Twig files contain valid syntax.

Exit: 0
```

### clients

Working directory: `/home/dev/Kunden/contao/contao_0507`

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

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec node node_modules/webpack-cli/bin/cli.js --config /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/phase-3a-webpack.cjs
```

```text
 DONE  Compiled successfully in 1630ms8:35:40 AM

19 files written to public/build
webpack compiled successfully
Exit: 0
```

### http

Working directory: `/home/dev/Kunden/github/contao-simple-member-chat`

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
Stable idle X-Chat-Since: 1789713387
Locale, word search, before windows, mute/CSRF/access and error association verified
Frame responses carry no self-referencing src
Headers, escaping, form reset, error text preservation and empty poll verified
Responses saved to /tmp/member-chat-http.xY1skg
Exit: 0
```

### cache

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec php bin/console cache:clear --env=prod
```

```text

 // Clearing the cache for the prod environment with debug false

 [OK] Cache for the "prod" environment (debug=false) was successfully cleared.

Exit: 0
```

### composer

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && composer validate --no-check-lock --strict'
```

```text
./composer.json is valid
Exit: 0
```

### host-absent

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'php -r '"'"'require '"'"'"'"'"'"'"'"'/var/www/html/vendor/autoload.php'"'"'"'"'"'"'"'"';
if (class_exists('"'"'"'"'"'"'"'"'HeimrichHannot\\PwaBundle\\Sender\\PushNotificationSender'"'"'"'"'"'"'"'"')) { throw new RuntimeException('"'"'"'"'"'"'"'"'PWA unexpectedly installed'"'"'"'"'"'"'"'"'); }
$kernel = Contao\ManagerBundle\HttpKernel\ContaoKernel::fromInput('"'"'"'"'"'"'"'"'/var/www/html'"'"'"'"'"'"'"'"', new Symfony\Component\Console\Input\ArgvInput(['"'"'"'"'"'"'"'"'console'"'"'"'"'"'"'"'"', '"'"'"'"'"'"'"'"'--env=prod'"'"'"'"'"'"'"'"']));
$kernel->boot();
$container = $kernel->getContainer();
foreach (['"'"'"'"'"'"'"'"'MessageSentListener'"'"'"'"'"'"'"'"', '"'"'"'"'"'"'"'"'SendChatPushHandler'"'"'"'"'"'"'"'"', '"'"'"'"'"'"'"'"'PushOptions'"'"'"'"'"'"'"'"'] as $name) {
$id = '"'"'"'"'"'"'"'"'HeimrichHannot\\SimpleMemberChatBundle\\Integration\\Pwa\\'"'"'"'"'"'"'"'"'.$name;
if ($container->has($id) || array_key_exists($id, $container->getRemovedIds())) { throw new RuntimeException('"'"'"'"'"'"'"'"'Unexpected optional service: '"'"'"'"'"'"'"'"'.$id); }
}
echo '"'"'"'"'"'"'"'"'Host boots without PWA; no optional push services (including removed private services).'"'"'"'"'"'"'"'"', PHP_EOL;
echo json_encode($container->getParameter('"'"'"'"'"'"'"'"'contao_member_chat.push'"'"'"'"'"'"'"'"'), JSON_THROW_ON_ERROR), PHP_EOL;
$kernel->shutdown();'"'"''
```

```text
Host boots without PWA; no optional push services (including removed private services).
{"enabled":false,"configuration":0,"active_recipient_grace":60,"body_length":0}
Exit: 0
```

### no-dev-install

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'mkdir -p /tmp/member-chat-phase5-production && cp /home/dev/Kunden/github/contao-simple-member-chat/composer.json /home/dev/Kunden/github/contao-simple-member-chat/composer.lock /tmp/member-chat-phase5-production/ && ln -sfn /home/dev/Kunden/github/contao-simple-member-chat/src /tmp/member-chat-phase5-production/src && cd /tmp/member-chat-phase5-production && composer install --no-dev --no-interaction --no-scripts'
```

```text
contao/manager-plugin: Dumping generated plugins file...
contao/manager-plugin: ...done dumping generated plugins file
Composer could not detect the root package (heimrichhannot/contao-simple-member-chat) version, defaulting to '1.0.0'. See https://getcomposer.org/root-version
Installing dependencies from lock file
Verifying lock file contents can be installed on current platform.
Package operations: 189 installs, 0 updates, 0 removals
    0 [>---------------------------]    0 [->--------------------------]
  - Installing contao-components/installer (1.4.1): Extracting archive
  - Installing php-http/discovery (1.20.0): Extracting archive
  - Installing symfony/polyfill-mbstring (v1.38.2): Extracting archive
  - Installing symfony/polyfill-ctype (v1.37.0): Extracting archive
  - Installing psr/container (2.0.2): Extracting archive
  - Installing symfony/deprecation-contracts (v3.7.1): Extracting archive
  - Installing psr/log (3.0.2): Extracting archive
  - Installing psr/event-dispatcher (1.0.0): Extracting archive
  - Installing symfony/service-contracts (v3.7.3): Extracting archive
  - Installing symfony/event-dispatcher-contracts (v3.7.1): Extracting archive
  - Installing symfony/http-foundation (v7.4.19): Extracting archive
  - Installing symfony/event-dispatcher (v7.4.17): Extracting archive
  - Installing symfony/var-dumper (v7.4.18): Extracting archive
  - Installing symfony/polyfill-php85 (v1.41.0): Extracting archive
  - Installing symfony/error-handler (v8.1.5): Extracting archive
  - Installing symfony/http-kernel (v7.4.19): Extracting archive
  - Installing symfony/polyfill-deepclone (v1.42.0): Extracting archive
  - Installing symfony/var-exporter (v8.1.6): Extracting archive
  - Installing symfony/dependency-injection (v7.4.17): Extracting archive
  - Installing symfony/filesystem (v7.4.18): Extracting archive
  - Installing symfony/config (v7.4.17): Extracting archive
  - Installing symfony/routing (v7.4.18): Extracting archive
  0/20 [>---------------------------]   0%
 20/20 [============================] 100%
  - Installing contao/manager-plugin (2.13.6): Extracting archive
  - Installing wikimedia/less.php (1.8.2): Extracting archive
  - Installing webignition/disallowed-character-terminated-string (2.0): Extracting archive
  - Installing webignition/robots-txt-file (3.0): Extracting archive
  - Installing brick/math (1.0.0): Extracting archive
  - Installing spomky-labs/pki-framework (1.6.3): Extracting archive
  - Installing web-auth/cose-lib (4.8.2): Extracting archive
  - Installing symfony/polyfill-uuid (v1.37.0): Extracting archive
  - Installing symfony/uid (v7.4.17): Extracting archive
  - Installing symfony/serializer (v8.0.15): Extracting archive
  - Installing symfony/type-info (v8.1.5): Extracting archive
  - Installing symfony/polyfill-intl-normalizer (v1.42.0): Extracting archive
  - Installing symfony/polyfill-intl-grapheme (v1.41.0): Extracting archive
  - Installing symfony/string (v7.4.19): Extracting archive
  - Installing symfony/property-info (v8.1.7): Extracting archive
  - Installing symfony/property-access (v7.4.16): Extracting archive
  - Installing symfony/polyfill-php83 (v1.41.0): Extracting archive
  - Installing psr/clock (1.0.0): Extracting archive
  - Installing symfony/clock (v7.4.8): Extracting archive
  - Installing symfony/polyfill-php81 (v1.38.1): Extracting archive
  - Installing spomky-labs/cbor-php (3.4.2): Extracting archive
  - Installing webmozart/assert (1.12.1): Extracting archive
  - Installing phpstan/phpdoc-parser (2.3.5): Extracting archive
  - Installing phpdocumentor/reflection-common (2.2.0): Extracting archive
  - Installing doctrine/deprecations (1.1.6): Extracting archive
  - Installing phpdocumentor/type-resolver (2.0.0): Extracting archive
  - Installing phpdocumentor/reflection-docblock (6.0.3): Extracting archive
  - Installing paragonie/constant_time_encoding (v3.1.3): Extracting archive
  - Installing web-auth/webauthn-lib (5.3.9): Extracting archive
  - Installing symfony/translation-contracts (v3.7.1): Extracting archive
  - Installing symfony/validator (v7.4.19): Extracting archive
  - Installing symfony/password-hasher (v7.4.8): Extracting archive
  - Installing symfony/security-core (v7.4.18): Extracting archive
  - Installing symfony/security-http (v7.4.19): Extracting archive
  - Installing symfony/security-csrf (v7.4.8): Extracting archive
  - Installing symfony/security-bundle (v7.4.18): Extracting archive
  - Installing symfony/http-client-contracts (v3.7.3): Extracting archive
  - Installing symfony/http-client (v7.4.19): Extracting archive
  - Installing symfony/finder (v7.4.19): Extracting archive
  - Installing psr/cache (3.0.0): Extracting archive
  - Installing symfony/cache-contracts (v3.7.1): Extracting archive
  - Installing symfony/cache (v7.4.19): Extracting archive
  - Installing symfony/framework-bundle (v7.4.19): Extracting archive
  - Installing web-auth/webauthn-symfony-bundle (5.3.9): Extracting archive
  - Installing composer/ca-bundle (1.5.14): Extracting archive
  - Installing ua-parser/uap-php (v3.10.0): Extracting archive
  - Installing twig/twig (v3.28.0): Extracting archive
  - Installing twig/string-extra (v3.24.0): Extracting archive
  - Installing symfony/process (v7.4.19): Extracting archive
  - Installing symfony/lock (v7.4.18): Extracting archive
  - Installing toflar/cronjob-supervisor (2.1.4): Extracting archive
  - Installing doctrine/lexer (3.0.1): Extracting archive
  - Installing doctrine/annotations (2.0.2): Extracting archive
  - Installing terminal42/service-annotation-bundle (1.2.2): Extracting archive
  - Installing masterminds/html5 (2.11.0): Extracting archive
  - Installing symfony/dom-crawler (v7.4.17): Extracting archive
  - Installing psr/http-message (2.0): Extracting archive
  - Installing psr/http-factory (1.1.0): Extracting archive
  - Installing nyholm/psr7 (1.8.2): Extracting archive
  - Installing terminal42/escargot (1.7.0): Extracting archive
  - Installing symfony/yaml (v7.4.18): Extracting archive
  - Installing symfony/twig-bridge (v7.4.19): Extracting archive
  - Installing symfony/twig-bundle (v7.4.19): Extracting archive
  - Installing symfony/translation (v7.4.17): Extracting archive
  - Installing symfony/options-resolver (v7.4.8): Extracting archive
  - Installing symfony/rate-limiter (v7.4.18): Extracting archive
  - Installing symfony/polyfill-php84 (v1.38.1): Extracting archive
  - Installing symfony/polyfill-intl-idn (v1.42.0): Extracting archive
  - Installing monolog/monolog (3.12.0): Extracting archive
  - Installing symfony/monolog-bridge (v7.4.18): Extracting archive
  - Installing symfony/mime (v7.4.19): Extracting archive
  - Installing symfony/messenger (v7.4.19): Extracting archive
  - Installing egulias/email-validator (4.0.4): Extracting archive
  - Installing symfony/mailer (v7.4.19): Extracting archive
  - Installing symfony/intl (v7.4.17): Extracting archive
  - Installing league/uri-interfaces (7.8.1): Extracting archive
  - Installing league/uri (7.8.1): Extracting archive
  - Installing symfony/html-sanitizer (v7.4.19): Extracting archive
  - Installing symfony/polyfill-intl-icu (v1.38.0): Extracting archive
  - Installing symfony/form (v7.4.19): Extracting archive
  - Installing symfony/expression-language (v7.4.18): Extracting archive
  - Installing doctrine/event-manager (2.1.1): Extracting archive
  - Installing doctrine/persistence (3.4.5): Extracting archive
  - Installing symfony/doctrine-bridge (v7.4.19): Extracting archive
  - Installing symfony/console (v7.4.19): Extracting archive
  - Installing symfony/asset (v7.4.8): Extracting archive
  - Installing symfony-cmf/routing (3.0.5): Extracting archive
  - Installing symfony-cmf/routing-bundle (3.2.0): Extracting archive
  - Installing spomky-labs/otphp (11.5.0): Extracting archive
  - Installing spatie/schema-org (4.0.2): Extracting archive
  - Installing simplepie/simplepie (1.9.0): Extracting archive
  - Installing scssphp/source-span (v1.1.0): Extracting archive
  - Installing scssphp/scssphp (v2.1.0): Extracting archive
  - Installing scrivo/highlight.php (v9.18.1.10): Extracting archive
  - Installing scheb/2fa-bundle (v8.6.1): Extracting archive
  - Installing lcobucci/jwt (5.6.0): Extracting archive
  - Installing lcobucci/clock (3.6.0): Extracting archive
  - Installing scheb/2fa-trusted-device (v8.6.1): Extracting archive
  - Installing scheb/2fa-backup-code (v8.6.1): Extracting archive
  - Installing phpspec/php-diff (v1.1.3): Extracting archive
  - Installing psr/http-client (1.0.3): Extracting archive
  - Installing php-http/promise (1.3.1): Extracting archive
  - Installing php-http/httplug (2.4.1): Extracting archive
  - Installing php-feed-io/feed-io (v6.4.1): Extracting archive
  - Installing nikic/php-parser (v5.9.0): Extracting archive
  - Installing nelmio/security-bundle (v3.9.0): Extracting archive
  - Installing nelmio/cors-bundle (2.6.1): Extracting archive
  - Installing matthiasmullie/path-converter (1.1.3): Extracting archive
  - Installing matthiasmullie/minify (1.3.75): Extracting archive
  - Installing league/mime-type-detection (1.17.0): Extracting archive
  - Installing league/flysystem-local (3.35.3): Extracting archive
  - Installing league/flysystem (3.36.0): Extracting archive
  - Installing league/flysystem-bundle (3.7.1): Extracting archive
  - Installing symfony/polyfill-php80 (v1.37.0): Extracting archive
  - Installing nette/utils (v4.1.5): Extracting archive
  - Installing nette/schema (v1.3.6): Extracting archive
  - Installing dflydev/dot-access-data (v3.0.3): Extracting archive
  - Installing league/config (v1.2.0): Extracting archive
  - Installing league/commonmark (2.10.1): Extracting archive
  - Installing knplabs/knp-time-bundle (2.5.0): Extracting archive
  - Installing knplabs/knp-menu (v3.8.0): Extracting archive
  - Installing knplabs/knp-menu-bundle (v3.7.0): Extracting archive
  - Installing imagine/imagine (1.5.4): Extracting archive
  - Installing guzzlehttp/promises (2.5.3): Extracting archive
  - Installing clue/stream-filter (v1.7.0): Extracting archive
  - Installing php-http/message (1.16.2): Extracting archive
  - Installing php-http/client-common (2.7.3): Extracting archive
  - Installing friendsofsymfony/http-cache (3.2.0): Extracting archive
  - Installing enshrined/svg-sanitize (0.22.0): Extracting archive
  - Installing dragonmantank/cron-expression (v3.6.0): Extracting archive
  - Installing symfony/polyfill-php86 (v1.41.0): Extracting archive
  - Installing doctrine/instantiator (2.1.0): Extracting archive
  - Installing doctrine/inflector (2.1.0): Extracting archive
  - Installing doctrine/dbal (3.10.6): Extracting archive
  - Installing doctrine/collections (3.1.0): Extracting archive
  - Installing doctrine/orm (3.7.1): Extracting archive
  - Installing doctrine/sql-formatter (1.5.4): Extracting archive
  - Installing doctrine/doctrine-bundle (2.19.1): Extracting archive
  - Installing contao/imagine-svg (1.0.4): Extracting archive
  - Installing symfony/polyfill-php73 (v1.37.0): Extracting archive
  - Installing contao/image (1.2.4): Extracting archive
  - Installing contao-components/tristen-tablesort (5.7.1): Extracting archive
  - Installing contao-components/tinymce4 (8.9.1): Extracting archive
  - Installing contao-components/tablesorter (2.31.3.1): Extracting archive
  - Installing contao-components/tablesort (4.0.2): Extracting archive
  - Installing contao-components/swiper (14.2.0): Extracting archive
  - Installing contao-components/swipe (2.2.2): Extracting archive
  - Installing contao-components/simplemodal (3.1.2): Extracting archive
  - Installing contao-components/mootools (1.6.0.9): Extracting archive
  - Installing contao-components/mediabox (1.5.4.3): Extracting archive
  - Installing contao-components/jquery-ui (1.13.2): Extracting archive
  - Installing contao-components/jquery (3.7.1): Extracting archive
  - Installing contao-components/handorgel (1.0.0.1): Extracting archive
  - Installing contao-components/dropzone (5.9.3): Extracting archive
  - Installing contao-components/datepicker (3.0.3): Extracting archive
  - Installing contao-components/contao (9.4.2): Extracting archive
  - Installing contao-components/colorbox (1.6.4.5): Extracting archive
  - Installing contao-components/choices (11.2.4): Extracting archive
  - Installing contao-components/altcha (2.2.4): Extracting archive
  - Installing contao-components/ace (1.44.0): Extracting archive
  - Installing cmsig/seal (0.12.16): Extracting archive
  - Installing cmsig/seal-symfony-bundle (0.12.16): Extracting archive
  - Installing dasprid/enum (1.0.7): Extracting archive
  - Installing bacon/bacon-qr-code (v3.1.1): Extracting archive
  - Installing ausi/slug-generator (v1.1.1): Extracting archive
  - Installing contao/core-bundle (5.7.13): Extracting archive
  - Installing heimrichhannot/contao-encore-contracts (1.5.0): Extracting archive
   0/166 [>---------------------------]   0%
  40/166 [======>---------------------]  24%
  68/166 [===========>----------------]  40%
  88/166 [==============>-------------]  53%
 109/166 [==================>---------]  65%
 125/166 [=====================>------]  75%
 138/166 [=======================>----]  83%
 151/166 [=========================>--]  90%
 166/166 [============================] 100%
Package doctrine/annotations is abandoned, you should avoid using it. No replacement was suggested.
Generating autoload files
112 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
Exit: 0
```

### no-dev-load

Working directory: `/home/dev/Kunden/contao/contao_0507`

```sh
ddev exec 'php -r '"'"'require '"'"'"'"'"'"'"'"'/tmp/member-chat-phase5-production/vendor/autoload.php'"'"'"'"'"'"'"'"';
if (Composer\InstalledVersions::isInstalled('"'"'"'"'"'"'"'"'heimrichhannot/contao-pwa-bundle'"'"'"'"'"'"'"'"') || class_exists('"'"'"'"'"'"'"'"'HeimrichHannot\\PwaBundle\\Sender\\PushNotificationSender'"'"'"'"'"'"'"'"')) { throw new RuntimeException('"'"'"'"'"'"'"'"'PWA unexpectedly installed'"'"'"'"'"'"'"'"'); }
$container = new Symfony\Component\DependencyInjection\ContainerBuilder();
$container->setParameter('"'"'"'"'"'"'"'"'kernel.environment'"'"'"'"'"'"'"'"', '"'"'"'"'"'"'"'"'test'"'"'"'"'"'"'"'"');
$container->setParameter('"'"'"'"'"'"'"'"'kernel.build_dir'"'"'"'"'"'"'"'"', '"'"'"'"'"'"'"'"'/tmp/member-chat-phase5-production/cache'"'"'"'"'"'"'"'"');
$extension = new HeimrichHannot\SimpleMemberChatBundle\HeimrichHannotSimpleMemberChatBundle()->getContainerExtension();
$extension->load([['"'"'"'"'"'"'"'"'push'"'"'"'"'"'"'"'"' => ['"'"'"'"'"'"'"'"'enabled'"'"'"'"'"'"'"'"' => true, '"'"'"'"'"'"'"'"'configuration'"'"'"'"'"'"'"'"' => 3]]], $container);
foreach (array_keys($container->getDefinitions()) as $id) {
if (str_contains($id, '"'"'"'"'"'"'"'"'Integration\\Pwa'"'"'"'"'"'"'"'"')) { throw new RuntimeException('"'"'"'"'"'"'"'"'Optional service without PWA: '"'"'"'"'"'"'"'"'.$id); }
}
echo '"'"'"'"'"'"'"'"'No-dev install excludes PWA and loads the chat extension without integration services, even with push enabled.'"'"'"'"'"'"'"'"', PHP_EOL;'"'"''
```

```text
No-dev install excludes PWA and loads the chat extension without integration services, even with push enabled.
Exit: 0
```

### diff

Working directory: `/home/dev/Kunden/github/contao-simple-member-chat`

```sh
git diff --check
```

```text
(no output)
Exit: 0
```
