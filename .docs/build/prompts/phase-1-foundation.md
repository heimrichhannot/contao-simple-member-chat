# Phase 1 — Foundation of `heimrichhannot/contao-simple-member-chat`

You are implementing phase 1 of a new Contao 5.7 bundle in this repository.
The repository currently contains the concept, agent rules and tooling
configuration only. Nothing else exists yet.

## Read first, in this order

1. `AGENTS.md` and `AGENTS.local.md` — binding coding and structure rules.
2. `.docs/CONCEPT.md` — the single functional source. Read it completely
   before writing any code. Section 13 lists decisions that are final; do
   not reopen them. Section 14 lists Contao APIs already verified against
   `contao/core-bundle` 5.7.11 with file paths.
3. `.docs/build/PHASES.md` — what belongs to this phase and what does not.

## Scope of this phase

Build everything below the HTTP layer. No controllers, no routes, no Twig
templates, no JavaScript, no content element, no contact providers (those
are phase 2 and 3).

Deliverables, mapped to concept sections:

- `composer.json` per section 0.1: package name, type `contao-bundle`,
  PHP `^8.4`, `contao/core-bundle:^5.7`, PSR-4 namespaces for `src/` and
  `tests/`, manager plugin entry, dev dependencies listed in section 12b
  (PHPUnit 12, `contao/test-case`, ECS, PHPStan with symfony, phpunit and
  strict-rules extensions, Rector with `contao/contao-rector`). Run the
  install so `vendor/contao/core-bundle` exists.
- Bundle class extending `AbstractBundle` with the configuration tree from
  section 12 (all nodes, defaults as documented, `providers` with
  `ignoreExtraKeys`), `loadExtension` filling container parameters, the
  immutable `ChatOptions` object, and the rate limiter registration from
  section 7 (own `RateLimiterFactory` with `CacheStorage` on `cache.app`,
  optional reference to a project-wide limiter).
- `ContaoManager/Plugin.php` registering the bundle after
  `ContaoCoreBundle` and loading `config/routes.yaml` (the file may be empty
  for now but the plugin must be complete).
- DCA files for `tl_chat_conversation`, `tl_chat_participant`,
  `tl_chat_message` exactly as in section 3, including `uuid` as
  `binary(16)` with unique index, `memberLow`/`memberHigh` with unique
  index, `muted`, `lastPageId`, `ptable`/`ctable` declarations, Doctrine
  schema arrays, plus the `memberChatPage` field on `tl_page` root palettes
  (section 3.4). Backend list/label configuration is phase 4; keep the DCAs
  minimal but complete for schema and cascade.
- Domain objects in `src/Domain/`: `Conversation` (id, uuid, memberLow,
  memberHigh, createdAt, lastMessageAt, lastMessageId), `Message`,
  `ConversationListItem`. Immutable, no framework base classes.
- Gateways for the three tables using DBAL, including `findByUuid`,
  the `before`/`after` windows for messages, the list query with unread
  count honouring `muted`, `FOR UPDATE` where the concept requires it.
- Services from section 7 except `ConversationUrlGenerator` (phase 4) and
  the contact services (phase 2): `FrontendMemberProvider`,
  `ConversationService` (find-or-create with UUIDv7, unique-conflict
  handling, `ConversationCreatedEvent`), `MessageService` (sanitizing,
  length, rate limit, insert, `lastMessageAt/Id`, `MessageSentEvent`
  dispatched after commit), `ReadTracker`, `MuteService`,
  `MessageTextSanitizer`, `MemberDataEraser`.
  `ConversationService::openWith` takes the `canContact` decision as an
  injected callable or interface stub for now, so phase 2 can plug the
  `ContactService` in without changing the service.
- Events from section 8.1 as final classes.
- `ConversationVoter` from section 7 and 11.
- Member deletion handling from section 10: `tl_member` `config.ondelete`
  callback, `CloseAccountEvent` listener, `closeAccount` hook, all three
  delegating to `MemberDataEraser` with the anonymisation rules described.
- Translations as Symfony PHP resources under `translations/` for the
  DCA labels created in this phase.
- Tests: unit tests for sanitizer, voter, pair ordering, services with
  mocked gateways; gateway tests against a real database connection via
  `contao/test-case` for unique conflict, unread count with `muted`, the
  `before`/`after` windows and the anonymisation. `phpunit.xml.dist`
  with `Unit` and `Integration` suites.

## Rules that override everything else

- Verify every Contao class, method, attribute signature and constant in
  `vendor/contao/core-bundle/` before using it. Do not invent APIs. If
  something the concept assumes is not there, use the documented
  alternative and record it in `.docs/build/DECISIONS.md` under
  "Nicht verifizierbar" with the path you looked at.
- Record every central API choice in `.docs/build/DECISIONS.md` with the
  `vendor/` path as evidence, same format as section 14 of the concept.
- Never claim a check ran. Every check in your report shows the exact
  command and its real, shortened output. A check you could not run is
  reported as "not verified", which is acceptable; an invented success is
  not.
- All code, comments, commit messages and docs are English. German exists
  only in translation files.
- Registration exclusively via PHP attributes as described in `AGENTS.md`.
- Follow the no-trivial-wrapper rule in `AGENTS.md`.
- Do not modify the host DDEV project's code. Use it only to run commands
  (`ddev exec`, `ddev composer`, `ddev php`) and to test the schema via
  `contao:migrate` after linking the bundle as a Composer path repository.
  Clear the Contao cache after DCA or service changes.
- Run ECS, PHPStan and Rector (dry run) with the configurations in the
  repository root and fix what they report. If a rule is genuinely wrong
  for this codebase, skip it in the config with a comment explaining why;
  do not lower the PHPStan level.
- Commit in small, meaningful steps with English messages. Do not start
  phase 2.

## Report

Finish with `.docs/build/reports/phase-1-foundation.md` containing: what
was built (file list grouped by concept section), decisions and
non-verifiable items, the exact commands run for composer install,
`contao:migrate`, PHPUnit, ECS, PHPStan and Rector with their output, open
questions for phase 2, and anything in the concept that turned out to be
wrong or incomplete while implementing.
