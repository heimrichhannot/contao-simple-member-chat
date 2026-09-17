# Phase 2 contacts report

Completed on 2026-09-17. Phase 3 has not been started. No controllers,
routes, templates, JavaScript, content element or backend module were
added. Host application code was not modified.

## Delivered files by concept section

### Section 4.1: viewer, provider contract and service

- `src/Contact/Viewer.php`: immutable member ID and active group IDs,
  with no display data.
- `src/Contact/ContactProviderInterface.php`: attribute registration,
  static alias, search and asymmetric permission contracts.
- `src/Contact/ContactService.php`: authenticated identity, viewer built
  from the member row once per main request/member, trimmed Unicode
  minimum query length, configured/requested limits, own-ID exclusion,
  deduplication and own-ID permission denial. Request caching does not
  carry authorization data into subsequent requests.
- `tests/Unit/ContactServiceTest.php`: normalization, default/smaller/capped
  limits, invalid limits, deduplication, self-exclusion, minimum length,
  deleted viewer and request cache lifetime.

### Sections 4.2 and 4.2a: display and known-member resolution

- `src/Domain/Contact.php`: immutable display value, no static factory.
- `src/Contact/ContactFactory.php`: model/row conversion, name fallback,
  optional single avatars and batched file resolution. Missing fields,
  empty values and missing files yield null avatars.
- `src/Contact/ContactResolver.php`: provider-independent batch lookup,
  requested-ID map and translated placeholder contacts for missing IDs,
  including zero. Inactive members still resolve for existing history.
- `translations/contao_default.{en,de}.php`: full Contao placeholder key
  `MSC.member_chat.deleted_member`.
- `tests/Unit/ContactFactoryTest.php`, `ContactResolverTest.php`: stubbed
  Studio/model access, exactly one batch file lookup, name fallback,
  missing-avatar paths, placeholder and batch contracts.

### Section 4.3: registry and configuration

- `src/Contact/ContactProviderRegistry.php`: tagged iterator indexed by
  static `getAlias`, selection using `ChatOptions::contactProvider`.
- `src/DependencyInjection/Compiler/ContactProviderPass.php`: compile-time
  rejection of unknown/duplicate aliases and invalid provider classes.
- `src/HeimrichHannotSimpleMemberChatBundle.php`, `config/services.yaml`:
  compiler pass, constructor injection of provider-specific options,
  gateway alias and permission adapter alias.
- `tests/Unit/ContactProviderRegistryTest.php`,
  `tests/Unit/BundleConfigurationTest.php`: alias selection/rejection,
  actual extension loading/autoconfiguration, option preservation/binding.

### Section 4.4: built-in providers and storage

- `src/Contact/Provider/{MemberGroupsContactProvider,SharedGroupsContactProvider}.php`:
  constructor-validated options, configured target groups versus the
  viewer's active groups.
- `src/Gateway/{ContactGateway,ContactGatewayInterface}.php`: member
  display projection, active viewer groups, literal prefix matching,
  target activity filters and serialized-group post-filtering.
- `tests/Unit/ContactProvidersTest.php`: both policies with mocked storage
  and invalid option rejection.
- `tests/DatabaseTestCase.php`, `tests/Integration/GatewaysTest.php`:
  extracted phase 1 database setup, retaining the explicit `*_test`
  database guard; adds minimal `tl_member` and `tl_member_group` fixtures.
- `tests/Integration/ContactsTest.php`: both providers against MariaDB,
  activity/start/stop boundaries, integer/string serialized group IDs,
  partial-ID rejection, literal wildcard prefixes, limit after group
  filtering, asymmetry, active groups, inactive-member display, missing
  avatar column and complete conversation creation through the adapter.

### Sections 7 and 15: phase 1 follow-ups

- `src/Service/ContactPermission.php`: real adapter implementing the
  retained `ContactPermissionInterface`; checks the initiating identity
  and delegates the permission decision to `ContactService`.
- Removed `src/Service/DenyContactPermission.php` and switched its alias.
- `src/Service/ConversationService.php`, `tests/Unit/ServicesTest.php`:
  existing-conversation lookup precedes limiter consumption. Tests prove
  existing opens consume no token and still work with an exhausted
  limiter. The integration case also revokes contact permission after
  creation and proves reopening still works without another event.

## Decisions and concept corrections

API evidence and detailed contracts are appended under **Phase 2** in
[DECISIONS.md](../DECISIONS.md).

- DBAL projects the few display columns in one member batch query; it
  avoids loading complete member models and works independently of the
  configured provider.
- Group filtering uses Contao deserialization and PHP post-filtering in
  one gateway. SQL applies activity and prefix filtering first; the
  result limit applies after group filtering. No serialized substring
  matching or N+1 member lookup is used.
- Active viewer groups require checking `tl_member_group`, an additional
  fixture omitted from the prompt's minimal-table description. Group
  timing follows Contao's minute precision; target account timing uses
  current time with integer-bound timestamps.
- The actual Contao 5.7 DCA defines `disable`/`login` as Boolean columns.
  Queries therefore use `0`/`1`, rather than treating the concept's
  historical `disable = ''` notation as a string-storage contract.
- Single avatars use `fromUuid()`. Batch avatars first call
  `FilesModel::findMultipleByUuids()` once and then `fromFilesModel()`;
  repeating `fromUuid()` would itself repeat the file lookups. Figure
  URLs come from `getImage()->getImageSrc()`, not a guessed Figure method.
- Unknown aliases fail at container-build time, as requested. No
  first-use-only deviation was necessary. Built-in providers remain
  private, autowired and autoconfigured.
- Limits are maximum counts. Removing a self-result or duplicates can
  leave fewer results; the provider interface has no offset/refill
  contract. This does not bypass the configured cap.

## Exact commands and observed output

All DDEV commands ran from `/home/dev/Kunden/contao/contao_0507`.
Repository commands ran from `/home/dev/Kunden/github/contao-simple-member-chat`.
Output below is shortened, with no claim that omitted checks ran.

### PHPUnit: both suites and the dedicated database

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
.............................................................     61 / 61 (100%)
Time: 00:05.071, Memory: 14.00 MB
OK (61 tests, 323 assertions)
```

42 unit tests and 19 integration tests passed, with no skips, notices or
warnings. The previously created dedicated database was reused; no host
member fixtures or application schema were changed.

An earlier unit-only check used:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpunit --testsuite Unit'
```

It initially reported `Tests: 40, Assertions: 204, Failures: 1`: the
bare test container had not loaded the interface resource, so it did not
exercise attribute discovery. The test now loads the real bundle
extension before compiling. An intermediate combined run reported
`Tests: 58, Assertions: 272, Failures: 1` because the test expected the
wrong database sort order. The corrected run passed 58 tests; added
boundary/null-file/limit cases produce the final 61-test result above.

### ECS

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --no-progress-bar'
```

```text
[OK] No errors found. Great job - your code is shiny in style!
```

### PHPStan

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
```

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
[OK] No errors
```

The initial run reported three errors: the legacy member-row array type,
calling a static FilesModel method dynamically through its adapter, and
a short ternary. These were fixed in code/types without suppressions or
lowering PHPStan's `max` level.

### Rector

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

```text
[OK] Rector is done!
```

Initial dry run: `[OK] 13 files would have been changed (dry-run) by Rector`.
Formatting and Rector recommendations were applied, including imports,
request-stack construction and private-method style. The final fix pass
used this exact command and exited 0 with redirected output:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --no-progress-bar > /tmp/member-chat-phase2-rector-fix.log 2>&1 && vendor/bin/ecs check --fix --no-progress-bar > /tmp/member-chat-phase2-ecs-fix.log 2>&1'
```

The independent checks above followed that pass.

### Host container compilation and real provider wiring

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
[OK] Cache for the "prod" environment (debug=false) was successfully cleared.
```

```sh
ddev exec 'php bin/console debug:container "HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService" --env=prod'
```

```text
Autowired        yes
Autoconfigured   yes
Arguments        Service(HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderRegistry)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGateway)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions)
                 Service(request_stack)
Usages           HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermission
```

```sh
ddev exec 'php bin/console debug:container "HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermission" --env=prod'
```

```text
Autowired        yes
Autoconfigured   yes
Arguments        Service(HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService)
Usages           HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermissionInterface
                 HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService
```

```sh
ddev exec 'php bin/console debug:container "HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderRegistry" --env=prod'
```

```text
Arguments        Tagged Iterator for "contao_member_chat.contact_provider" (2 element(s))
                 - Service(HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\MemberGroupsContactProvider)
                 - Service(HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\SharedGroupsContactProvider)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions)
```

All three commands exited 0. Symfony notes that these private services
were removed or inlined; phase 2 still has no HTTP consumer. Earlier
non-nested quoting of the adapter name caused DDEV to strip namespace
separators and the ambiguous service search aborted. The exact commands
above preserve the name and succeeded. `--show-arguments` was also
removed after the console reported it deprecated (arguments are shown
by default).

### Migration dry run

```sh
ddev exec php bin/console contao:migrate --schema-only --dry-run --env=prod --no-interaction
```

Exit 0. Shortened output:

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

Other output consists of legacy column drops on `tl_content`, `tl_module`
and `tl_page`. These unrelated drop proposals were already documented in
phase 1. There is no phase 2 schema change or chat-table migration. No
migration was executed.

### Whitespace

```sh
git diff --check
```

Exit 0, no output.

## Verification limits and phase 3 handoff

- Real avatar resizing through the host image pipeline is **not verified**:
  Studio, FigureBuilder and FilesModel behavior is source-checked and
  stub-tested, including one batch lookup and missing resources.
- Placeholder translations are provided in Symfony PHP format; actual
  localized browser rendering is **not verified** and belongs to phase 3.
- Search performance for large member populations is **not verified**.
  PHP group filtering is the explicitly accepted small-site approach.
- Arbitrary project-defined providers are **not verified** in the host;
  interface tagging, alias handling and custom option preservation are
  covered by unit/container tests. Host defaults still use the deliberately
  restrictive empty `member_groups.groups` list.
- Phase 3 must use `ContactService` for search/new-contact decisions and
  `ContactResolver` for partner/author display, deriving surviving partners
  from participant rows. Zero/deleted IDs always receive a placeholder.
- The phase 1 polling question remains open for phase 3: `since` based
  only on message time cannot refresh read/mute-only changes in other
  tabs. Message-window draining and UUID-based list upserts also remain
  phase 3 work.
- No UI, browser, routes, CSRF, template escaping, polling, moderation or
  push-delivery acceptance is claimed by this phase.

## Commits

- `68a6955` — Do not consume contact-start tokens for existing conversations.
- `562b98f` — Implement configurable contact providers and batched member display.
- The final phase 2 commit adds the shared database fixture, integration
  coverage and this report. No push was performed.
