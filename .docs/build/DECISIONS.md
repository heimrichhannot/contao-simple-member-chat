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

## Phase 2

Verified against the installed Contao 5.7.13 sources on 2026-09-17.
Phase 1 decisions above remain the historical record.

| Choice | Source evidence |
| --- | --- |
| Display from a member model delegates through `row()`; names use trimmed first/last name and an empty-name username fallback, preserving the literal name `0` | `vendor/contao/core-bundle/contao/library/Contao/Model.php::row()`, `contao/classes/FrontendUser.php::getDisplayName()` |
| Single avatar: `Studio::createFigureBuilder()->fromUuid()->setSize()->buildIfResourceExists()`; URL: `Figure::getImage()->getImageSrc()` | `vendor/contao/core-bundle/src/Image/Studio/{Studio,FigureBuilder,Figure,ImageResult}.php`; `buildIfResourceExists()` returns null for missing/non-image resources |
| Batch avatars: one `FilesModel::findMultipleByUuids()` through the core framework adapter, deduplicated UUIDs, then `fromFilesModel()` for each returned file | `vendor/contao/core-bundle/contao/models/FilesModel.php::findMultipleByUuids()`, `src/Framework/{ContaoFramework,Adapter}.php`, `src/Image/Studio/FigureBuilder.php::fromFilesModel()`; `fromUuid()` itself performs a file lookup, so repeating it would defeat batching. Explicit `Adapter::__call()` keeps the verified static-call seam testable without PHPStan's dynamic-static-call violation |
| DBAL for member display batches and provider queries | `vendor/contao/core-bundle/contao/library/Contao/Model.php::findMultipleByIds()` hydrates complete member rows; `contao/models/MemberModel.php` declares the inherited finder. DBAL selects only `id`, `firstname`, `lastname`, `username`, and the configured avatar column when it exists. Resolver IDs use one `IN` query, regardless of provider or member activity |
| Active viewer groups come from the member row, intersected with active `tl_member_group` rows; group time uses minute precision | `vendor/contao/core-bundle/contao/classes/FrontendUser.php::setUserFromDb()`, `contao/models/MemberGroupModel.php::findAllActive()`, `contao/library/Contao/Date.php::floorToMinute()` |
| Target members use `disable = 0`, `login = 1`, inclusive start and exclusive stop at current time | `vendor/contao/core-bundle/contao/dca/tl_member.php` defines actual Boolean SQL types, unlike the concept's legacy empty-string notation; `src/Security/User/UserChecker.php::checkIfAccountIsActive()` verifies time direction. Time query parameters are integers to avoid lexicographic comparison of timestamp strings |
| Serialized groups are decoded and post-filtered in `ContactGateway`; only positive integer IDs / digit strings are retained | `vendor/contao/core-bundle/contao/library/Contao/StringUtil.php::deserialize()` disables unserialized classes. Both integer and string IDs occur in serialized data; post-filtering avoids partial group-ID matches and applies the result limit after group filtering |
| Provider registration uses interface `#[AutoconfigureTag]`; registry uses `#[AutowireIterator(..., defaultIndexMethod: 'getAlias')]` | `vendor/symfony/dependency-injection/Attribute/{AutoconfigureTag,AutowireIterator}.php`; `Loader/FileLoader.php` discovers interface attributes during resource loading; `Compiler/{RegisterAutoconfigureAttributesPass,ResolveInstanceofConditionalsPass,PassConfig}.php` establishes the compiler-pass order |
| Unknown and duplicate aliases fail during container compilation | `ContactProviderPass` runs at default before-optimization priority, after attribute autoconfiguration at priority 100. It inspects tagged service classes without instantiating providers. The registry additionally throws a clear exception when constructed manually with an unknown alias. No deferred-validation deviation is needed |
| Viewer cache is scoped to the main Request and authenticated member ID | `vendor/symfony/http-foundation/RequestStack.php::{getMainRequest,push,pop}`. A `WeakMap` prevents a shared service retaining stale authorization data across requests; calls outside a Request deliberately do not cache |
| Deleted contact translation uses `MSC.member_chat.deleted_member` in `contao_default` | `vendor/contao/core-bundle/src/Translation/{MessageCatalogue,LegacyGlobalsProcessor}.php`, retaining the full Contao key as established in phase 1 |
| Tests stub static model access using the core framework adapter and model collection | `vendor/contao/test-case/src/ContaoTestCase.php::{createAdapterMock,createContaoFrameworkStub,createClassWithPropertiesStub}`, `vendor/contao/core-bundle/contao/library/Contao/Model/Collection.php::__construct()` |

### Phase 2 contracts and clarifications

- Controllers will call `ContactService::search(string, ?int)` and
  `canContact(int)`. The service obtains the authenticated identity itself;
  callers cannot supply another member's Viewer. The permission adapter
  additionally checks the phase 1 initiator ID against that Viewer.
- A missing authenticated member row rejects access. Empty or inactive
  viewer groups become an empty list. `member_groups` deliberately does not
  require the viewer to belong to the configured target groups; this
  preserves the specified asymmetric permission model.
- `shared_groups` uses the active viewer groups; both providers filter
  target account activity. `member_groups` checks configured group IDs
  against target membership, as specified, without inventing a viewer-side
  group requirement.
- All SQL filtering lives in `ContactGateway`. Prefixes escape `%`, `_`
  and the escape character `!`; ordering is lastname, firstname, username,
  id using database collation. PHP group filtering can scan many candidate
  rows; this is the explicitly accepted small-site tradeoff. No benchmark
  at large membership counts is claimed.
- Search limits are maximums, not a promise to fill the result count:
  removing self/duplicates centrally may return fewer results. Nonpositive
  limits and too-short trimmed Unicode queries return no results without
  consulting the provider. Invalid/nonpositive contact IDs are discarded.
- `resolveMany()` returns a map keyed by each requested ID; missing IDs,
  including zero, map to a Contact whose own memberId is zero. Empty input
  performs no query. No groups enter the display DTO.
- An absent configured avatar column is detected using schema metadata;
  identifiers are quoted. This adds a metadata lookup only when avatars
  are configured, not one member/file query per result. Binary file UUIDs
  are the specified field contract; arbitrary project avatar formats are
  not introduced.
- The active-group requirement also requires a minimal `tl_member_group`
  integration fixture, in addition to the prompt's `tl_member` fixture.
  Both live only in the explicitly configured `*_test` database.
- Existing-conversation lookup now precedes rate limiter consumption;
  existing conversations remain accessible even after contact permission
  is revoked or the member's limiter is exhausted.
- No host application source, DCA schema, routes, controllers, templates,
  JavaScript, content element or backend module was changed in phase 2.

## Phase 3a

Verified on 2026-09-17 against the bundle's Contao 5.7.13 / Symfony 7.4 /
Twig 3.28 vendor tree. Paths are relative to the bundle unless labelled
**host** (`/home/dev/Kunden/contao/contao_0507`). Phase 3b is not implemented.

| Central choice | Vendor evidence |
| --- | --- |
| Attribute content element, backend-only editor hint, current page and fragment response | `vendor/contao/core-bundle/src/DependencyInjection/Attribute/AsContentElement.php`; `src/Controller/ContentElement/AbstractContentElementController.php`; `src/Controller/AbstractFragmentController.php::{getPageModel,isBackendScope,createTemplate}` |
| Consume `auto_item`, including anonymous visits, before Contao's unused-parameter check | `vendor/contao/core-bundle/contao/library/Contao/Input.php::get()` defaults its third parameter to false. `ConversationAccess::fromItem()` uses the framework adapter's explicit `__call('get', ['auto_item'])`, matching phase 2's strict-PHPStan adapter convention. Anonymous visits consume the item but perform no conversation lookup |
| UUID syntax before lookup; voter after `findByUuid` | `vendor/symfony/uid/Uuid.php`; `vendor/symfony/security-core/Authorization/AuthorizationCheckerInterface.php`; `vendor/contao/core-bundle/src/Exception/{PageNotFoundException,NotFoundException}.php`. The public syntax is the concept's lower-case RFC 4122 notation |
| Attribute routes with frontend scope and CSRF checks | `vendor/symfony/routing/Attribute/Route.php`; `vendor/contao/core-bundle/src/EventListener/RequestTokenListener.php`. The actual field is **REQUEST_TOKEN**, supplied by `src/Csrf/ContaoCsrfTokenManager.php::getDefaultTokenValue()`. `_token_check: true` stays on both POST routes; no custom token validation |
| Page URL helper uses inherited page details and `parameters: /<uuid>` | `vendor/contao/core-bundle/contao/models/PageModel.php::findWithDetails()` calls `loadDetails()`; `src/Routing/ContentUrlGenerator.php::generate()`; `src/Routing/Content/ArticleResolver.php` demonstrates `parameters`; service ID in `config/services.yaml`. `ChatPageUrlGenerator` validates a regular page before a write, supplies an explicit empty parameter for the back link, and is the extension point for phase 4's fallback policy |
| Turbo cache meta works in **both** layout types through `HtmlHeadBag` | `vendor/contao/core-bundle/src/Controller/AbstractController.php::getHtmlHeadBag()`; `src/Routing/ResponseContext/HtmlHeadBag/HtmlHeadBag.php::{removeMetaTag,addMetaTag}`; `src/String/HtmlAttributes.php`. Legacy `contao/pages/PageRegular.php` assigns `getMetaTags()` and `contao/templates/frontend/fe_page.html5` renders them. Modern `contao/templates/twig/page/layout.html.twig` reads `response_context.head.metaTags` in a deferred block. Both were verified in rendered HTTP responses. No `TL_HEAD` workaround or remaining meta gap |
| Private content-element response reaches the complete page | `vendor/contao/core-bundle/src/EventListener/SubrequestCacheSubscriber.php` merges fragment cache policy; actual legacy and modern pages return `must-revalidate, no-cache, no-store, private` |
| All endpoint responses, including kernel errors, prohibit storage | `vendor/symfony/http-foundation/{Response,ResponseHeaderBag}.php`; `vendor/symfony/http-kernel/Event/ResponseEvent.php`; `vendor/symfony/event-dispatcher/Attribute/AsEventListener.php`. `ChatResponseListener` is limited to main requests below `/_member_chat/`, priority -1016, after Contao's -1012 private-response listener. This covers CSRF and routing exceptions outside the controller. `TurboResponseFactory` supplies the normal HTML/stream headers and `Vary: Accept` |
| Attribute Twig runtime, safe escaping before linking | `vendor/twig/twig/src/Attribute/AsTwigFilter.php`; `vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php`; `DependencyInjection/Compiler/AttributeExtensionPass.php` adds both the attribute extension and `twig.runtime` for non-static methods. The filter splits raw text, escapes every part and constructs only http/https anchors. Templates apply `nl2br` afterwards; no `raw` filter or Markdown |
| Page timestamp format on full pages and fragment requests | `vendor/contao/core-bundle/src/Twig/Global/ContaoVariable.php::getDatim_format()` reads `PageModel::datimFormat`; `contao/models/PageModel.php::loadDetails()` inherits it. Every frame URL/form carries the current page ID; `ChatContextFactory` supplies its resolved `datimFormat`, so standalone fragment routes need no ambient current page |
| Extensible attribute objects and template namespace | `vendor/contao/core-bundle/src/String/HtmlAttributes.php::{set,addClass,mergeWith}`; `contao/templates/twig/content_element/_base.html.twig`; `src/Twig/Loader/TemplateLocator.php`. All partial roots/frames and stream roots expose merge attributes |
| Type and category share the requested `member_chat` key | `vendor/contao/core-bundle/contao/library/Contao/Widget.php::getAttributesFromDca()` uses element zero for both array-valued group references and options. `CTE.member_chat.0` therefore translates both; `.1` supplies help without a conflicting scalar `CTE.member_chat` |
| Encore entry and automatic activation | `vendor/heimrichhannot/contao-encore-contracts/{EncoreEntry,EncoreExtensionInterface,PageAssetsTrait,AddPageEntrypointTrait}.php` (1.5.0). **Host:** `vendor/heimrichhannot/contao-encore-bundle/src/DependencyInjection/EncoreExtension.php` autoconfigures the interface; `src/Asset/FrontendAsset.php` registers entries in the response context. Legacy layouts must have Encore enabled. Turbo remains project-provided |
| Turbo full reload, streams and redirect/focus lifecycle | **Host:** `node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js`: `FrameElement::reload`, `FrameController::sourceURLReloaded`, `FetchResponse::{succeeded,redirected,location}`, stream rendering and `turbo:before-frame-render`/`turbo:before-stream-render` events. `reload()` returns `element.loaded`, not a FetchResponse; completion is checked on the frame. No second Turbo import |

### Contracts and corrections

- `ConversationListItem::changedAt` is `GREATEST(lastMessageAt, viewer.tstamp)`.
  `since` remains inclusive and unbounded. An independent DB connection proves
  read and mute changes surface without changing message activity time.
- A changed conversation does **not** necessarily move to the top: a read or mute
  change alters only participant time. Responses use remove + prepend streams;
  the client then sorts present items by `lastMessageAt` descending and UUID
  descending for equal timestamps. UUID provides a stable public tie-breaker;
  the gateway retains its existing integer-ID tie-breaker. Equal-time ordering
  may therefore differ after a poll, but message-time ordering is preserved and
  internal conversation IDs never reach the template. Phase 3b must preserve
  this ordering when adding older items.
- Initial messages are already rendered. Their frame records incremental mode
  and its delivered cursor; Turbo's initial `src` request refreshes that state.
  Full reload support remains available through `frame.reload()`. The client
  copies cursor attributes explicitly because Turbo retains the outer frame.
- Message polls return `X-Chat-After` and `X-Chat-Count`; list polls return
  `X-Chat-Since`. The client drains full message pages. Successful send streams
  **do not advance** the polling cursor: another member's earlier unseen message
  could otherwise be skipped. Turbo's append behavior deduplicates direct child
  message elements with matching IDs; this is append replacement, not morphing.
- An empty poll calls `markRead` with zero, preserving the stored monotonic read
  position while refreshing activity/page tracking. Client `after` is never
  treated as proof that a message was delivered.
- `ChatReader` owns read-window orchestration and delegates writes to `ReadTracker`.
  View mapping itself is side-effect-free. Initial list/partner/author display
  uses one combined `ContactResolver::resolveMany()` call, not separate row calls.
- Public template objects: `ChatView` has `conversations`, `messages`, `partner`,
  `lastMessageId`, `changedAt`; `ConversationItemView` has `uuid`, `partner`,
  `url`, `excerpt`, `lastMessageAt`, `changedAt`, `unreadCount`, `muted`;
  `MessageView` has `id`, `author`, `body`, `createdAt`, `own`, `readByPartner`.
  `message_status` stays empty. Context also provides page/route URLs, options,
  CSRF token, page time format and form values. Full README documentation is phase 4.
- Contact-start forms target `chat-search` for validation errors. A successful
  redirected response is promoted to `Turbo.visit()`; ordinary list/back links
  explicitly opt into Drive. No mute, badge or history-loading route was added.
- Avatar display-column metadata, including a missing configured field, is
  memoized on the gateway instance for its process lifetime. The gateway's
  dependencies remain readonly; only the private cached projection is mutable.

## Phase 3b

Verified on 2026-09-17 against the installed Contao 5.7.13 / Symfony 7.4 /
Twig 3.28 sources and the host's installed Turbo distribution. Phase 4 is
not started. Host paths below mean `/home/dev/Kunden/contao/contao_0507`.

| Choice | Evidence and consequence |
| --- | --- |
| Resolve the page locale before building views and rendering any successful frame/stream or application validation response | `vendor/contao/core-bundle/contao/models/PageModel.php::loadDetails()` assigns both `rootLanguage` and `language` from the root. `src/Routing/Page/PageRoute.php` derives `_locale` through `LocaleUtil::formatAsLocale()`. `src/Util/LocaleUtil.php` converts locale IDs and HTML language tags. `vendor/symfony/translation/LocaleSwitcher.php::setLocale()` updates locale-aware services, including translation and the routing context; `vendor/symfony/http-foundation/Request.php::setLocale()` updates `_locale`. `ChatContextFactory` does both and provides `language` for HTML. The messages controller now creates context before its reader, so placeholder contacts and day labels also use the page locale. The switcher implements `reset()` for kernel reset; these routes do not run a second page locale in the same response. |
| Preserve list change timestamps on idle activity | `ParticipantGateway::markRead()` reads the state under the existing service-owned conversation lock (`ReadTracker`, `ChatTransaction`). A greater delivered message ID changes `lastReadMessageId`, activity/page and `tstamp` immediately. Otherwise it skips writes until `polling.activity_throttle` seconds have elapsed (default 30; zero disables throttling), then updates only activity/page. `setMuted()` has a SQL difference predicate, so setting the same value is a no-op. `ConversationGateway::listForMember()` continues using `GREATEST(lastMessageAt, p.tstamp)`. Integration tests prove the boundary, unchanged list cursor, immediate new read, repeated mute and configurable zero. |
| Look ahead one row for history availability | `MessageGateway::window()` already returns chronological `before` pages; `ConversationGateway::listForMember()` already supports the `(lastMessageAt,id)` boundary. `ChatReader` requests `page_size + 1`, discards the lookahead, and maps only delivered records. `after` remains the earliest unseen page of exactly `page_size`; `since` remains inclusive and unbounded. A before response only has `X-Chat-Before` (empty at exhaustion), never `X-Chat-After` or `X-Chat-Since`. |
| Explicit scroll correction, not native overflow anchoring | Capture the first visible message ID and its offset relative to the log immediately before a stream renders. Reconcile message order/day boundaries and formatted time text, then restore that offset. `overflow-anchor: none` avoids double correction. New messages scroll down only when already at the bottom; own sends still scroll down. Actual pixel stability in a browser is **not verified**; the checklist includes both prepend and final-button removal. |
| Wait for stream rendering before releasing the history request lock | **Host** `node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js`: `StreamElement::render()` dispatches `turbo:before-stream-render`, waits for the next repaint, and awaits the replaceable `event.detail.render`. `renderStreamMessage()` returns no completion promise. A client batch marker and counter therefore resolve only after every wrapped render. `StreamActions.append/prepend` deduplicate direct children by ID. The button is disabled during fetch/render, and polls share the same per-frame loading guard. |
| IntersectionObserver observes both load-more buttons | A native `IntersectionObserver` triggers the same guarded load function as a click. New buttons are discovered by the existing mutation observer; registration is deferred while that frame is loading. `data-chat-auto-load="false"` on either frame disables automatic observation; lack of the API leaves the button usable. The observer disconnects before Turbo cache. Failure leaves manual retry available. Browser intersection behavior is **not verified**. |
| Window-local day boundaries with client boundary reconciliation | `DaySeparatorFactory` emits Today/Yesterday via the translator, localized weekdays via PHP Intl for the preceding six calendar days, otherwise the page `dateFormat`. PHP's configured timezone is used for both server day keys and Twig dates. Each window emits a separator before its first message and at day transitions. The client orders message/separator pairs by message ID and hides redundant separators when adjacent message dates match, removing duplicates from visible and accessible output while retaining candidates for later stream deduplication. This also handles an incoming message preceding an already displayed local send. |
| Page-language time display, device-timezone formatting | HTML `lang` is supplied on chat roots/messages/list/mute. The client uses the nearest `lang`, `Intl.RelativeTimeFormat` for recent times, and `Intl.DateTimeFormat` for older times and a full tooltip. It runs at load, every frame/stream render and every 30 seconds. Server text and the original `datetime` stay the fallback and machine-readable source. |
| Dynamic viewport height follows CSS layout | The script detects the single-column state from sidebar visibility, so it does not duplicate a hardcoded breakpoint. Only with `visualViewport` does it set `--member-chat-viewport-height` to visual height minus the visible actual root top (accounting for `offsetTop`). CSS subtracts `--member-chat-offset-top`. Resize/scroll, Turbo load and ResizeObserver events recalculate it. Desktop removes the measured property; `100dvh` stays the fallback. Bottom state is preserved when height changes. Real iOS/standalone/Android keyboard behavior is **not verified**. |
| Accessible mute remains a non-polled frame | Existing attribute `Route` / `_token_check` / `REQUEST_TOKEN` APIs verified in `vendor/symfony/routing/Attribute/Route.php`, `vendor/contao/core-bundle/src/EventListener/RequestTokenListener.php` and `src/Csrf/ContaoCsrfTokenManager.php`. Strict `muted=0|1`, UUID access checking and `MuteService` retain the existing transaction contract. The stable toggle label uses `aria-pressed`; focus is remembered before Turbo disables the submit button and restored after replacement. |
| Extensible markup | `vendor/contao/core-bundle/src/String/HtmlAttributes.php::{set,mergeWith}` accepts the typed values used here. New controls and separators use partial blocks with merge attributes. All templates stay under `contao/templates/`. |

### Additive contracts and concept corrections

- `ChatView` adds `beforeMessageId: ?int`, `beforeConversation: ?string`,
  `muted: bool`. `MessageView` adds `daySeparator: ?DaySeparatorView` with
  `day` (`Y-m-d`) and translated `label`. Existing properties remain intact.
- Before cursors are independent of poll cursors. The list history cursor
  includes the integer tie-breaker exactly as the phase prompt requires;
  this is an explicit exception to the concept's blanket statement that an
  internal conversation ID never leaves the server. Conversation navigation
  and authorization continue to use UUIDs. No standalone ID field is exposed.
- New frame state: `data-chat-auto-load`, `data-chat-loaded-before`,
  `data-chat-before`, `data-chat-at-bottom`, `data-chat-has-new`; existing
  polling, `after`, `since` and page-size attributes are preserved.
- New controls: `chat-more-messages`, `chat-more-conversations`, `chat-mute`,
  `chat-mute-button`; history buttons carry `data-chat-load-more`,
  `data-chat-before`, `data-chat-url`. Message day keys and separator ownership
  use `data-chat-message-day` / `data-chat-day-message`.
- After a history page, polling stays incremental. Send responses never move
  `after`; before responses never move `after` or `since`. Empty list polls
  echo at least the supplied `since`, avoiding an apparent cursor regression.
- Activity-only page tracking is now delayed along with `lastReadAt`, including
  switching pages inside the throttle period. That is the requested activity
  throttle; phase 4 URL fallback must account for up to 30 seconds of staleness.
- Concept 5.7's ban on inline styles has a narrow required exception: the
  script supplies the measured viewport custom property. CSS still controls
  layout and subtracts the project offset. The script does not choose a
  desktop breakpoint. No generic styling policy was changed.
- The mute toggle retains a fixed label ("Mute conversation") with
  `aria-pressed` describing state. The list reflects muting on its next poll.
- Search matches literal field or word prefixes, not arbitrary substrings.
  Below the minimum, the results area is empty; stale in-flight query responses
  cannot restore old results after the user clears the query.
- All browser behavior remains **not verified**; see
  `.docs/BROWSER_CHECKLIST.md`. HTTP login in the curl harness is distinct from
  browser login, which the prompt reserves for the reviewer.

## Phase 3c

Scope: only the five findings at the end of concept section 15. Phase 4 is
not started. Verified against the installed vendor tree and host Turbo sources.

| Decision | Evidence / contract |
| --- | --- |
| Capture passive document scroll events and use the existing animation-frame batch | Scroll does not bubble; capture also observes scrolling ancestors without per-container registration or lifecycle leaks. The existing visualViewport calculation, CSS breakpoint detection, bottom-state handling and `100dvh` fallback remain. The client regression harness exercises 377 → 0 root top, producing 435 → 812 measured height. |
| Protect history with a 12rem minimum and compact header | The demo's generated `assets/css/files_contaodemo_theme_src_scss_app.scss.css` has unlayered `h2` size/margin rules, which outrank layered bundle rules regardless of specificity. Only compact header structure, history minimum, compose shrink protection and pressed-state rules are unlayered and scoped to `.member-chat`; no `!important`. The heading remains semantic, single-line and ellipsized; controls do not wrap. On a viewport shorter than the history minimum plus controls, document overflow is intentional to keep history and compose reachable. |
| State-dependent translated mute action and visible pressed state | Existing Twig `trans` and conditional expressions; verified `vendor/symfony/twig-bridge/Extension/TranslationExtension.php::trans()` and `vendor/contao/core-bundle/src/String/HtmlAttributes.php::set()`. `aria-pressed`, frame/route/CSRF contracts and focus restoration stay unchanged. This supersedes 3b's fixed-label decision. |
| Release the history lock immediately after wrapped stream rendering | Host `node_modules/@hotwired/turbo/dist/turbo.es2017-esm.js::StreamElement.render()` awaits the wrapped render after `nextRepaint()`; `renderStreamMessage()` returns void. Keep the batch completion mechanism but remove our additional post-render animation-frame await. The isolated regression test supplies completed streams with a hidden document and a repaint queue that never runs; cleanup still finishes. Turbo's own pre-render repaint behavior is unchanged. |
| Keep inclusive, unbounded `since`; compare a fingerprint of the delivered view window | `ConversationGateway::listForMember()` is unchanged. SHA-256 covers the timestamp and normalized conversation view values (including UUID/order, contact, excerpt, unread and muted state). A timestamp plus conversation ID alone cannot distinguish a second change to the same row in one second. Integration coverage proves same-second send/read/mute/unmute changes. Empty or matching windows return private 204 before stream rendering. |
| Fingerprint belongs to its cursor, not to HTTP cache state | New optional `fingerprint` query parameter, `X-Chat-Fingerprint` response header and `data-chat-fingerprint` attribute; `ChatView` adds trailing optional `conversationFingerprint: string`. Full pages/frames seed it through the factory. The next fingerprint covers only rows at/after the returned `X-Chat-Since`, not the old broader window. Normalize array keys after filtering. A truncated initial timestamp boundary fails comparison safely and delivers the full unbounded window. History responses never advance either polling value. The client copies the attribute on frame replacement and advances both values only after successful stream rendering. No ETag/304 or server session state. |
| Existing response and request APIs | `vendor/symfony/http-foundation/InputBag.php::getString()`, `HeaderBag.php::set()`, `Response.php` status 204 and empty-response preparation; existing `TurboResponseFactory` supplies private/no-store and `Vary: Accept`, including 204. No registration changes or new external library APIs. |

New CSS custom properties (all on `.member-chat`, overridable by project CSS):

| Property | Default |
| --- | --- |
| `--member-chat-history-min-height` | `12rem` |
| `--member-chat-header-font-size` | `1rem` |
| `--member-chat-header-line-height` | `1.25` |
| `--member-chat-header-gap` | `.5rem` |
| `--member-chat-header-control-font-size` | `.875rem` |
| `--member-chat-header-control-padding` | `.5rem` |
| `--member-chat-muted-bg` | `#334155` |
| `--member-chat-muted-fg` | `#fff` |

All prior data attributes and view fields are preserved. The fingerprint
represents the standard view data, not arbitrary project template output;
projects adding independent dynamic list state must extend that contract.
Browser/device verification limits and exact commands are in the phase 3c report.

## Phase 4

Verified against installed Contao 5.7.13, Symfony 7.4, Twig 3.28 and Encore
contracts 1.5.0. Host paths refer to `/home/dev/Kunden/contao/contao_0507`.

| Decision | Source evidence and contract |
| --- | --- |
| Replace `ChatPageUrlGenerator` with `ConversationUrlGenerator`, retaining the known-page path | `vendor/contao/core-bundle/src/Routing/ContentUrlGenerator.php::generate()` and existing phase 3a tests. `generate(PageModel, ?uuid)` still clears auto-item with an explicit empty `parameters` string. CE contexts, row URLs and contact-start redirects all use the same generator with the known page; no per-row participant/root query is introduced. The phase 3a placeholder class name was not a stable public API; view/data contracts are unchanged. |
| Published root enumeration and destination guards | `vendor/contao/core-bundle/contao/models/PageModel.php::findPublishedRootPages()` filters `type='root'`, publication and minute-resolution start/stop. `Model.php::isPreviewMode()` honors `ignoreFePreview`; pass it explicitly so backend preview cannot leak unpublished destinations into push links. Set `order: tl_page.sorting, tl_page.id`, since the finder itself supplies no order. Rootfallback is a palette, not another page type. `findPublishedById()` rejects missing/unpublished/scheduled pages; require `regular`, then `loadDetails()` and `isPublic`/`rootIsPublic`. Skip invalid root targets rather than returning a broken URL. |
| Define list-page tracking where the concept had no selection rule | `lastPageId` lives per conversation participant, not per member. `listPage()` takes the latest positive tracked page ordered by `lastReadAt DESC, id DESC`; if it is invalid, proceed to roots. `forConversation()` uses only that conversation's participant row before roots. The existing 30-second activity throttle may delay page changes; no new tracking writes or fake presence accuracy. |
| Attribute Twig function | `vendor/twig/twig/src/Attribute/AsTwigFunction.php` accepts `isSafe: ['html']` and injects Environment for the first typed parameter. `vendor/symfony/twig-bundle/DependencyInjection/{TwigExtension.php,Compiler/AttributeExtensionPass.php}` registers the attribute extension and runtime. `vendor/contao/core-bundle/src/Twig/Extension/ContaoExtension.php` registers `HtmlAttributes` as safe through `EscaperRuntime::addSafeClass()`; unit fixtures reproduce that. Attributes and links are escaped by the template. Host smoke confirms the real function and anonymous rendering. |
| Standalone badge assets and privacy | `vendor/heimrichhannot/contao-encore-contracts/EncoreEntry.php` separates entry names/paths and `requiresCss`. Full chat entry now wraps CSS plus the unchanged shared script module; the badge entry includes just the shared JS. Explicitly activate `huh_member_chat_badge` and Turbo on badge-only pages, avoiding late runtime asset activation after a layout has emitted its head. README requires Drive no-cache meta on these pages. `RequestStack::getMainRequest()` and `ParameterBag::{set,getBoolean}` mark complete responses; existing attribute response listener applies private/no-store, including anonymous function renders. |
| Badge poll remains a full frame at zero | Existing installed host Turbo `FrameElement::{reload,loaded}` and `FrameController` permit setting src once and reloading later. Markup carries `data-chat-url`, no response src. Both the poll branch and before-frame-render handler exempt badges from the incremental-mode transition. Visibility uses the badge's parent so an empty inline frame can gain content; hidden tabs/navigation still pause. `Request::setLocale()`/`LocaleSwitcher::setLocale()` preserve the originating locale supplied as `_locale`. Existing message/list cursor logic is untouched. |
| Backend module registration | Core `vendor/contao/core-bundle/contao/config/config.php` declares `$GLOBALS['BE_MOD']` including the accounts group. This registry has no equivalent listener attribute; use the mandated config.php entry with exactly conversation/message tables. All callbacks use `#[AsCallback]`, verified in `src/DependencyInjection/Attribute/AsCallback.php`. `contao/classes/DataContainer.php` defines MODE_SORTED/MODE_PARENT and invokes `list.label.label` for both; child_record_callback is deprecated in 5.7, so use label callbacks. `notCreatable`/`notEditable` and explicit operations restrict moderation to view/delete. |
| Resolve moderation names once per table/request | `ContactResolver::resolveMany()` remains the display authority. `MemberLabels` loads distinct IDs once per table and caches the resolved map in a WeakMap keyed by current Request. No row-by-row member lookup or shared-worker stale name cache. This bounded-query approach scans distinct IDs across the table and is a deliberate small-site tradeoff; large-scale pagination optimization is not claimed. Escape complete labels, including message excerpts. |
| Repair runs before message deletion | `vendor/contao/core-bundle/contao/drivers/DC_Table.php::delete()` at ondelete_callback invokes callbacks with DC and undo ID **before** the DELETE loop. `DataContainer::getCurrentRecord()` supplies id/pid. Use a conditional SQL update (`WHERE lastMessageId = deleted.id`) with latest remaining ID/time subqueries excluding the pending row, reset to zero if empty. A concurrent newer send is not overwritten. Update participant tstamp so count/excerpt changes can surface in inclusive list polls even when message time decreases. MariaDB integration executes the registered listener before simulating the core delete order for middle/tail/final messages. |
| Keep the existing narrow participant accessor as public integration API | `ParticipantGatewayInterface::state()` already exposes typed mute, activity/read and page state with null for removed participants. A new read-only wrapper would only rename it. Mark/document this method, `MessageGateway::find()`, Conversation/Message values and the three events as stable. Events remain after commit; recipients need fresh state checks at queued delivery time. No Messenger/PWA classes or dependencies are added. |

Concept corrections/limits: listPage's tracking selection and root ordering were
unspecified; invalid fallback destinations are skipped. Backend registration is
an explicit core registry, not a listener registration. The concept's generic
post-delete description must be implemented with pre-delete exclusion. The
backend batches distinct IDs from the whole table. Deletion does not broadcast
tombstones to already-open message frames; reload is required. Core undo retains
serialized data and restoring moderated rows is not verified; concurrent backend
deletions are not verified. Badge-only layouts must activate the JS-only entry
and the documented Turbo meta. No new browser/device/UI rights claims are made.

The checklist's old phase-3c pending text was superseded by the later reviewer
verification in concept section 15. Preserve that provenance; phase 4 automation
does not amount to another browser pass. Exact commands, failed intermediate
checks and final outputs are in the phase 4 report.
