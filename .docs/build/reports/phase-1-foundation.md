# Phase 1 foundation report

Completed on 2026-09-17. Phase 2 has not been started. No controllers,
HTTP routes, templates, JavaScript, content element, contact providers,
backend module or PWA integration were added.

## Delivered files by concept section

### Sections 0, 2 and 12: package, configuration and domain

- `composer.json`, `.gitignore`: package metadata, PHP 8.4 / Contao 5.7,
  runtime dependencies, development tools and PSR-4 autoloading.
- `src/HeimrichHannotSimpleMemberChatBundle.php`: `AbstractBundle`, alias,
  complete configuration tree, parameters, options and rate limiter wiring.
- `src/Configuration/ChatOptions.php`: immutable configured values.
- `src/ContaoManager/Plugin.php`, `config/services.yaml`,
  `config/routes.yaml`: manager integration, autowiring, attribute discovery,
  gateway interfaces and an intentionally empty route resource.
- `src/Domain/Conversation.php`, `Message.php`, `ConversationListItem.php`:
  immutable values with no framework base classes.

### Section 3: schema and storage

- `contao/dca/tl_chat_conversation.php`, `tl_chat_participant.php`,
  `tl_chat_message.php`: Doctrine schema arrays, binary UUID, ordered pair
  uniqueness, participant uniqueness, indexes, `ptable`/`ctable` cascade.
- `contao/dca/tl_page.php`: root/rootfallback `memberChatPage` picker.
- `src/Gateway/ConversationGateway.php`, `ParticipantGateway.php`,
  `MessageGateway.php`, and their three `*GatewayInterface.php` files:
  DBAL storage, UUID conversion, chronological message windows,
  conversation pagination, unread aggregation, state tracking and erasure.
- `translations/contao_tl_chat_conversation.{en,de}.php`,
  `contao_tl_chat_participant.{en,de}.php`,
  `contao_tl_chat_message.{en,de}.php`, `contao_tl_page.{en,de}.php`:
  Symfony PHP resources with full Contao translation keys.

### Sections 7, 8.1 and 11: services, events and authorization

- `src/Service/ConversationService.php`, `MessageService.php`,
  `ReadTracker.php`, `MuteService.php`, `FrontendMemberProvider.php`,
  `MessageTextSanitizer.php`: identity, authorization, UUIDv7 creation,
  duplicate-conflict recovery, rate limiting, text validation, sending,
  read tracking and muting.
- `src/Service/ChatTransaction.php`, `ChatEventDispatcher.php`: top-level
  transaction ownership and logged post-commit listener failures.
- `src/Service/ContactPermissionInterface.php`, `DenyContactPermission.php`:
  the explicitly permitted phase 1 authorization seam; denies new contacts
  until phase 2 supplies its adapter.
- `src/Event/ConversationCreatedEvent.php`, `MessageSentEvent.php`,
  `MessagesReadEvent.php`: final events with readonly payloads.
- `src/Security/Voter/ConversationVoter.php`: loaded conversation subject,
  authenticated frontend member and ordered-pair access check.
- `src/Exception/ChatException.php`, `AuthenticationRequiredException.php`:
  status codes and translation keys for the later HTTP layer.

### Section 10: member deletion

- `src/Service/MemberDataEraser.php`: anonymizes authors, retains text,
  removes participation and deletes conversations with no participants.
- `src/EventListener/DataContainer/Member/ConfigOnDeleteListener.php`,
  `src/EventListener/CloseAccountEventListener.php`,
  `src/EventListener/Hook/CloseAccountListener.php`: all three specified
  deletion entry points, registered with attributes. Deactivation is ignored.

### Section 12b: verification and records

- `phpunit.xml.dist`, `tests/ServiceTestCase.php`.
- `tests/Unit/MessageTextSanitizerTest.php`, `ConversationVoterTest.php`,
  `ServicesTest.php`, `BundleConfigurationTest.php`,
  `MemberDeletionListenersTest.php`.
- `tests/Integration/GatewaysTest.php`: 13 real MariaDB cases, including a
  second connection winning the creation race and a second writer blocked
  by `FOR UPDATE`.
- `ecs.php`, `phpstan.neon`, `rector.php`: compatibility corrections
  described in `../DECISIONS.md`; PHPStan remains at `max`.
- `.docs/build/DECISIONS.md`: verified vendor API evidence and deviations.
- `.docs/build/verify-host.php`: read-only host palette/translation smoke check.
- This report.

## Decisions and implementation discoveries

The full API evidence is in [DECISIONS.md](../DECISIONS.md).

- Verified the installed Contao 5.7.13 APIs rather than assuming the
  concept's earlier 5.7.11 snapshot.
- `Viewer` belongs to phase 2. `openWith(int $initiatorId, int $memberId)`
  accepts the authorization decision through `ContactPermissionInterface`.
  The phase 2 adapter can resolve `Viewer` and call `ContactService`
  without modifying the conversation service. Existing conversations do
  not recheck contact permission.
- Event payloads are readonly; the Symfony event parent itself cannot be
  readonly because it supports stopping propagation.
- Nested service transactions are rejected before writes, ensuring that
  the specified events run only after the actual commit.
- Mute and erasure remain transactional without inventing extra events.
  The concept's generic statement that every write dispatches an event
  exceeds its explicit three-event catalogue.
- Incremental conversation `since` is inclusive and returns all changed
  rows. Strict `>` can miss activity within one second; a page-size cap can
  drop updates. Phase 3 must upsert by UUID. Message `after` windows return
  the earliest unseen page in chronological order.
- DCA `text` maps to MariaDB `LONGTEXT` through Doctrine. The configured
  message length is enforced by the sanitizer.
- The root page relation requires `foreignKey: tl_page.title`; the first
  migration dry run exposed the omission. The final relation and both
  palettes were checked in the host.
- Symfony Contao translation resources need the table prefix in their
  keys, even with a table-specific domain. The host smoke check verified
  the corrected keys.
- Gateways are final implementations behind mockable interfaces. No
  custom classes use the reserved `Model` suffix.

## Commands and observed output

All DDEV commands below were issued with working directory
`/home/dev/Kunden/contao/contao_0507`. Bundle commands explicitly change
into `/home/dev/Kunden/github/contao-simple-member-chat` inside DDEV.
Output excerpts are shortened; omitted lines do not indicate extra checks.

Installed versions: Contao core/test-case 5.7.13, DBAL 3.10.6, PHPUnit
12.5.35, ECS 12.6.2, PHPStan 2.2.14, Rector 2.6.7 and Contao Rector
`dev-main` at `75f3a92`.

### Composer installation and validation

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && composer install --no-interaction --no-progress'
```

The first attempt failed:

```text
Root composer.json requires contao/contao-rector ^0.23, found contao/contao-rector[dev-main] but it does not match the constraint.
```

After selecting `dev-main`, installation initially stopped at the
unconfigured `contao-components/installer` plugin. It was explicitly
set to `false` for this library's development installation. The next run
installed 220 packages and generated the autoloader. No vendor files were patched.

Final repeat and validation:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && composer install --no-interaction --no-progress && composer validate --strict'
```

```text
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
Package doctrine/annotations is abandoned, you should avoid using it. No replacement was suggested.
Generating autoload files
contao/manager-plugin: ...done dumping generated plugins file
./composer.json is valid
```

### Host linking and container

```sh
ddev composer config repositories.member-chat '{"type":"path","url":"/home/dev/Kunden/github/contao-simple-member-chat","options":{"symlink":true}}'
ddev composer require heimrichhannot/contao-simple-member-chat:@dev --no-scripts --no-interaction --no-progress
```

```text
Lock file operations: 1 install, 1 update, 0 removals
- Upgrading heimrichhannot/contao-qna-bundle (dev-main da44063 => dev-main 134fcc8)
- Locking heimrichhannot/contao-simple-member-chat (dev-main b05ca40)
- Upgrading heimrichhannot/contao-qna-bundle ...: Source already present
- Installing heimrichhannot/contao-simple-member-chat ...: Symlinking from /home/dev/Kunden/github/contao-simple-member-chat
No security vulnerability advisories found.
```

Composer refreshed the already-linked Q&A package's lock reference; its
source was already present. Host changes were limited to the authorized
Composer link, generated autoload/cache, schema and test database. No host
application source was edited.

```sh
ddev exec php bin/console cache:clear --env=prod --no-warmup
```

```text
[OK] Cache for the "prod" environment (debug=false) was successfully cleared.
```

This command was repeated after DCA/service changes and before the final
host smoke check.

```sh
ddev exec php bin/console debug:container 'HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService' --env=prod
```

```text
Autowired        yes
Autoconfigured   yes
Arguments        Service(HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Service\DenyContactPermission)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider)
                 Service(contao_member_chat.rate_limiter)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Service\ChatTransaction)
                 Service(HeimrichHannot\SimpleMemberChatBundle\Service\ChatEventDispatcher)
```

The private conversation service is removed/inlined by the compiled
container while it has no HTTP consumer; this is expected in phase 1.

### Contao schema migration

```sh
ddev exec php bin/console contao:migrate --help
ddev exec php bin/console contao:migrate --schema-only --dry-run --env=prod --no-interaction
```

Initial dry-run output:

```text
Incomplete relation defined for tl_page.memberChatPage
```

After adding the core-pattern foreign key and clearing cache, the same
command showed the three expected `CREATE TABLE tl_chat_*` statements,
`ALTER TABLE tl_page ADD memberChatPage`, and pre-existing unrelated drops.
The migration was run without `--with-deletes`:

```sh
ddev exec php bin/console contao:migrate --schema-only --env=prod --no-interaction
```

```text
[INFO] Creating a database dump to "backup__20260917073910.sql.gz" with the default options.
Execute database migrations
* CREATE TABLE tl_chat_conversation ...
* CREATE TABLE tl_chat_message ...
* CREATE TABLE tl_chat_participant ...
* ALTER TABLE tl_page ADD memberChatPage INT UNSIGNED DEFAULT 0 NOT NULL
[OK] Executed 4 SQL queries.
...
[OK] Executed 0 SQL queries.
```

The remaining migration proposals are the pre-existing unrelated drops;
none was executed. The migrated UUID is `BINARY(16)`, muted is `CHAR(1)`,
and the unique/index definitions appear in the actual generated SQL.

### PHPUnit

Dedicated database setup:

```sh
ddev mysql -e 'CREATE DATABASE IF NOT EXISTS member_chat_test; GRANT ALL PRIVILEGES ON member_chat_test.* TO "db"@"%";'
```

Exit 0, no output. Integration tests rebuild only the three chat tables
in this dedicated database and reject database names not ending in `_test`.

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && MEMBER_CHAT_TEST_DATABASE_URL=mysql://db:db@db/member_chat_test vendor/bin/phpunit'
```

Final output:

```text
PHPUnit 12.5.35 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.24
.....................................                             37 / 37 (100%)
Time: 00:03.113, Memory: 12.00 MB
OK (37 tests, 150 assertions)
```

There are 24 unit tests and 13 integration tests. Earlier unit-only runs
used the following exact command:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/phpunit --testsuite Unit'
```

Intermediate failures were resolved: unused mock notices (replaced by
stubs), missing `kernel.environment` / `kernel.build_dir` in the isolated
container fixture, and an order-sensitive assertion on provider option
maps. The final combined run has no notices, failures or skipped tests.

### ECS

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --fix --no-progress-bar'
```

The initial supplied configuration failed with:

```text
The following sets are already included in the "common" set: arrays, spaces, namespaces, docblocks, controlStructures, phpunit, comments. Please remove them.
```

After removing only those redundant set flags, formatting was applied.
Subsequent fix passes captured output inside DDEV using:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/ecs check --fix --no-progress-bar > /tmp/member-chat-ecs-fix.log 2>&1; tail -n 5 /tmp/member-chat-ecs-fix.log'
```

Final independent check:

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

Intermediate results included an invalid exclusion for the absent
`contao/config` directory, database/config array typing findings,
exception-base finality, fixture stub types, and Rector's dynamic calls
to static PHPUnit APIs. These were corrected without reducing the level
or adding PHPStan error suppressions.

Final output:

```text
Note: Using configuration file /home/dev/Kunden/github/contao-simple-member-chat/phpstan.neon.
[OK] No errors
```

### Rector

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --dry-run --no-progress-bar'
```

The initial supplied cumulative Contao set failed with:

```text
[ERROR] Undefined constant Rector\Symfony\Set\SymfonySetList::SYMFONY_70
```

The equivalent Contao rule chain was expanded in the repository config,
with Composer-based dependency sets retained. The static PHPUnit call
conflict is documented and skipped in `rector.php`. Requested changes
were applied using:

```sh
ddev exec 'cd /home/dev/Kunden/github/contao-simple-member-chat && vendor/bin/rector process --no-progress-bar > /tmp/member-chat-rector-fix.log 2>&1; tail -n 5 /tmp/member-chat-rector-fix.log'
```

Final dry-run output:

```text
[OK] Rector is done!
```

### Host palette and translation check

```sh
ddev exec php /home/dev/Kunden/github/contao-simple-member-chat/.docs/build/verify-host.php
```

Exit 0. Shortened output:

```json
{
    "root": true,
    "rootfallback": true
}
```

The same output contained the expected two German label/help strings
from `translations/contao_tl_page.de.php`.
An earlier inline `php -r` attempt failed with `kernel: unbound variable`
because DDEV re-quotes arguments; it was replaced with this inspectable
script. Initial redirected/inline DDEV attempts also encountered Docker
socket sandbox denial; ordinary DDEV commands and the reviewed retry
were available. Those failures are not counted as successful checks.

### Repository whitespace check

From `/home/dev/Kunden/github/contao-simple-member-chat`:

```sh
git diff --check
```

Exit 0, no output.

## Verification limits and phase 2 handoff

- No open product decisions were reopened. Phase 2 must replace the
  deny-default authorization seam with its `ContactService` adapter and
  implement the already-approved Viewer/provider contracts.
- New-contact creation intentionally remains denied in the installed host
  until that adapter exists. Unit/integration tests inject a permitted
  decision to exercise creation.
- The three deletion entry points are unit-tested; erasure, cascade and
  row locks use the real database. Actual account deletion through the
  backend/frontend UI is not verified. The DCA cascade is source-verified;
  the explicit DBAL cascade is integration-tested.
- Full simultaneous multi-process stress testing and deletion racing with
  new conversation creation are not verified. The unique-conflict race
  is deterministically interleaved across two connections; lock blocking
  is separately verified against MariaDB.
- Host defaults and compilation are verified; the external limiter alias
  and arbitrary provider option preservation are tested in the isolated
  container definition, not with a project-defined limiter in the host.
- HTTP, browser behavior, template escaping, frontend polling, backend
  moderation and push delivery belong to later phases and are not verified.
- Direct SQL member deletion and external privacy tools do not invoke the
  three supported deletion callbacks. Phase 4 documentation must state this.
- For phase 3, timestamp-based polling alone cannot report read/mute-only
  changes from another tab; those states need an appropriate refresh path.

## Commits

- `b05ca40` — Add Contao member chat package, configuration and schema.
- `7267d31` — Implement transactional chat services and member data erasure.
- The final verification commit contains the tests, tool compatibility
  adjustment, host smoke script and this report. No push was performed.
