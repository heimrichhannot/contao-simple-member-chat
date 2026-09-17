# Phase 1 API decisions

Verified against the bundle's installed Contao 5.7.13 sources (2026-09-17).
Paths below are relative to this repository. No phase 2 implementation is included.

| Choice | Source evidence |
| --- | --- |
| `AbstractBundle`, `extensionAlias`, `configure`, `loadExtension` | `vendor/symfony/http-kernel/Bundle/AbstractBundle.php` |
| Manager bundle ordering and YAML route loading | `vendor/contao/manager-plugin/src/Bundle/BundlePluginInterface.php`, `Bundle/Config/BundleConfig.php`, `Routing/RoutingPluginInterface.php`; `vendor/contao/core-bundle/src/ContaoCoreBundle.php` |
| DCA schema arrays, binary UUID of length 16, indexes | `vendor/contao/core-bundle/src/Doctrine/Schema/DcaSchemaProvider.php` processes `SCHEMA_FIELDS`; `vendor/contao/core-bundle/contao/dca/tl_files.php` uses a binary UUID and unique index |
| DCA cascade through both child tables | `vendor/contao/core-bundle/contao/drivers/DC_Table.php::deleteChildren()` follows `ctable` and child `pid`; gateway deletion explicitly removes children because DBAL does not execute DCA cascades |
| Root and fallback root page selection | `vendor/contao/core-bundle/contao/dca/tl_page.php` palettes `root` and `rootfallback`; `vendor/contao/core-bundle/src/DataContainer/PaletteManipulator.php` verifies `create`, `addField`, `POSITION_APPEND`, `applyToPalette` |
| Frontend identity from security token only | `vendor/contao/core-bundle/contao/classes/FrontendUser.php`; `vendor/contao/core-bundle/contao/library/Contao/User.php` declares integer `id`; no singleton lookup |
| Member backend deletion callback | `vendor/contao/core-bundle/src/DependencyInjection/Attribute/AsCallback.php`; `vendor/contao/core-bundle/contao/drivers/DC_Table.php` invokes `config.ondelete` with the data container and undo ID; `contao/classes/DataContainer.php` exposes `id` |
| New frontend account closure | `vendor/contao/core-bundle/src/Event/CloseAccountEvent.php` exposes `getMember` and `getContentModel`; `src/Controller/ContentElement/CloseAccountController.php` dispatches before delete/deactivate; listener checks `reg_close === close_delete` |
| Legacy frontend account closure | `vendor/contao/core-bundle/src/DependencyInjection/Attribute/AsHook.php`; `contao/modules/ModuleCloseAccount.php` passes member ID, mode and module; unused trailing module argument is omitted |
| Host smoke loading | `vendor/contao/core-bundle/contao/library/Contao/Controller.php::loadDataContainer()` and `System.php::loadLanguageFile()`; the host-only kernel bootstrap was verified at `/home/dev/Kunden/contao/contao_0507/vendor/contao/manager-bundle/src/HttpKernel/ContaoKernel.php::fromInput()` |
| Symfony PHP translation keys | `vendor/contao/core-bundle/src/Translation/MessageCatalogue.php::populateGlobals()` and `LegacyGlobalsProcessor.php` require full keys such as `tl_page.memberChatPage.0`, even in domain `contao_tl_page` |
| UUIDv7 and binary conversion | `vendor/symfony/uid/Uuid.php`, `UuidV7.php`; `v7`, `toBinary`, `fromBinary`, `toRfc4122`, `isValid` |
| Own limiter, shared between sending and contact starts | `vendor/contao/core-bundle/src/DependencyInjection/ContaoCoreExtension.php` registers `RateLimiterFactory` with `CacheStorage(cache.app)`; `vendor/symfony/rate-limiter/RateLimiterFactory.php` supports `sliding_window` and `RateLimiterFactoryInterface`; external names alias `limiter.<name>` |
| Voter | `vendor/symfony/security-core/Authorization/Voter/Voter.php`; `Conversation` subject and authenticated `FrontendUser`, positive ID matching the ordered pair; writes additionally require actual participant rows |
| Immutable event payloads | `vendor/symfony/event-dispatcher-contracts/Event.php` is not readonly; final subclasses therefore expose readonly payload properties rather than declaring the entire subclass readonly |
| Transaction and conflict handling | `vendor/doctrine/dbal/src/Connection.php::transactional()` rolls back on exceptions; catch `UniqueConstraintViolationException` outside the transaction and reload the winner |
| Test support | `vendor/contao/test-case/src/ContaoTestCase.php` provides `createClassWithPropertiesStub`; gateway tests extend it indirectly and use DBAL with a dedicated MariaDB database |

## Foundation contracts

- The prompt explicitly defers `Viewer` and `ContactService` to phase 2. `openWith(int $initiatorId, int $memberId)` uses `ContactPermissionInterface::canContact(int, int)` as the authorized phase 1 seam. The default implementation denies new contacts. Phase 2 can supply an adapter that resolves `Viewer` without changing `ConversationService`. Existing conversations bypass the contact decision, as required.
- Both send and contact start consume the same per-member limiter. Polling/read tracking does not consume it. Messages retain literal HTML as plain text; phase 3 must use Twig escaping.
- All service writes own a top-level transaction. Nested invocations are rejected before writing, because an inner DBAL commit cannot guarantee that an event is dispatched after the outer transaction commits. Listener exceptions are logged after commit.
- Sends, reads, muting and erasure acquire the conversation row with `FOR UPDATE`. Erasure locks conversation IDs in ascending order, preserves text, removes participant rows and deletes empty conversations including messages. The ordered pair remains unchanged, following the specified schema and deletion rules; it is not an active participant list. Display resolves the remaining partner from participant rows.
- The concept's claim that every write ends with an event is broader than its specified event catalogue. Mute and erasure are transactional but introduce no unrequested events; only the three specified events exist.
- List `before` pagination uses `(lastMessageAt, id)` descending. Incremental `since` is inclusive and unbounded by page size: strict `>` with second-resolution timestamps would miss updates in the same second, and limiting changed rows would lose unseen changes. Phase 3 must upsert/deduplicate by UUID. Read/mute changes are not message activity; phase 3 must also refresh those states when needed.
- Integration tests rebuild only the three chat tables in an explicitly configured database whose name ends in `_test`. They do not use the demo database for fixtures. The duplicate-start test interleaves a second connection's committed creation between lookup and insertion, deterministically testing the losing request's conflict path.

## Nicht verifizierbar

- The concept's `UP_TO_CONTAO_57` Rector set cannot execute with current Rector: `vendor/contao/contao-rector/config/sets/contao/level/up-to-contao-57.php` references removed `SymfonySetList::SYMFONY_70` constants. `rector.php` expands all Contao rule sets from that cumulative chain; PHP 8.4 and Composer-based Symfony/Doctrine sets retain dependency migration coverage. No vendor files are patched.
- No tagged `contao/contao-rector` release matched the initial `^0.23` constraint; Composer offered only `dev-main`. The package uses that explicit development dependency.
- The supplied ECS configuration lists subsets already included by `common`; ECS rejects the duplicate declarations. Removed the redundant flags, retaining `common`, PSR-12, strict and the Symfony/PHP 8.4 sets.
- `contao/config` does not exist in phase 1. Its PHPStan exclusion is marked optional rather than adding an empty configuration file. The PHPStan level stays `max`.

- The root page relation follows the core `jumpTo` field: `foreignKey: tl_page.title` plus `hasOne`/`lazy`. Without the foreign key, `vendor/contao/core-bundle/contao/library/Contao/DcaExtractor.php` rejects it as an incomplete relation. This was caught and corrected during the migration dry run.
- Rector `PreferPHPUnitThisCallRector` conflicts with PHPStan strict static-method rules. It is skipped with a config comment; PHPUnit assertions and static factories remain static.
