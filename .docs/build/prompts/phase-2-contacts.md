# Phase 2 — Contacts for `heimrichhannot/contao-simple-member-chat`

Phase 1 is complete and committed. You are implementing phase 2: the
contact layer that decides whom a member can find and write to, and how
members are displayed.

## Read first, in this order

1. `AGENTS.md` and `AGENTS.local.md` — binding coding and structure rules.
2. `.docs/CONCEPT.md` — the single functional source. Section 4 is this
   phase. Section 13 lists final decisions (notably 1 and 14); section 15
   lists what phase 1 discovered and what this phase must pick up.
3. `.docs/build/reports/phase-1-foundation.md` and
   `.docs/build/DECISIONS.md` — what exists, why, and with which evidence.
4. `.docs/build/PHASES.md` — scope boundaries.

Then read the phase 1 code you will build on: `ConversationService`,
`ContactPermissionInterface`, `DenyContactPermission`, `ChatOptions`,
the bundle class, `services.yaml`, `ParticipantGateway::memberIds`.

## Scope of this phase

Everything in concept section 4 plus the two phase 1 follow-ups listed
below. No controllers, routes, templates, JavaScript, content element or
backend module (phases 3 and 4).

Deliverables:

- `src/Contact/Viewer.php` (section 4.1): `memberId`, `groupIds` of the
  member's active groups. Built once per request by the `ContactService`
  from the member row. `Viewer` is not `Contact` and carries no display
  data.
- `src/Domain/Contact.php` (section 4.2): `memberId`, `displayName`,
  `subtitle`, `avatarUrl`. Dumb value object, no static factory.
- `src/Contact/ContactFactory.php` (section 4.2): `fromMemberModel()`,
  `fromMemberRow()`, and a batch `fromMemberRows()` that resolves avatars
  with a single `FilesModel::findMultipleByUuids()` call. Display name is
  `trim(firstname.' '.lastname)` with `username` as fallback. Avatar
  resolution via `Studio::createFigureBuilder()->fromUuid()->setSize()`
  only when `contact.avatar_field` is configured; a missing field, empty
  value or missing file yields `null`. Verify the exact FigureBuilder API
  in `vendor/contao/core-bundle/src/Image/Studio/` before use, and check
  how a `Figure` exposes the image URL.
- `src/Contact/ContactResolver.php` (section 4.2a): `resolve(int)` and
  `resolveMany(list<int>)` for known members regardless of provider.
  Unknown or deleted members (including `0`) yield a placeholder contact
  with `memberId = 0` and a translated "Deleted member" name; templates
  never receive `null`. Decide and record whether `resolveMany` queries
  `tl_member` via `MemberModel::findMultipleByIds()` or DBAL; either is
  allowed by `AGENTS.md`, pick the one that avoids N+1 and hydrates the
  fewest columns.
- `src/Contact/ContactProviderInterface.php` exactly as in section 4.1:
  static `getAlias()`, `search(Viewer, string, int): list<Contact>`,
  `canContact(Viewer, int): bool`, tagged via `#[AutoconfigureTag]`. Put
  the asymmetry note and the "no self-exclusion / normalisation here"
  note into the interface docblock.
- `src/Contact/ContactProviderRegistry.php` (section 4.3): receives all
  tagged providers indexed by alias through `#[AutowireIterator]` with
  `defaultIndexMethod: 'getAlias'`, returns the provider named by
  `contact_provider`. The concept asks for a container-build-time failure
  on an unknown alias; implement it as a compiler pass or in
  `loadExtension` if that is feasible without a service-graph hack,
  otherwise fail on first use with a clear exception and record the
  deviation in `DECISIONS.md`.
- `src/Contact/ContactService.php` (section 4.1): the only entry point
  for controllers. Builds the `Viewer`, trims and length-checks the query
  against `list.search_min_length`, caps the limit at `list.search_limit`,
  removes the viewer from results, deduplicates by `memberId`, answers
  `canContact` for the viewer's own ID with `false` without consulting the
  provider.
- `src/Contact/Provider/MemberGroupsContactProvider.php` and
  `SharedGroupsContactProvider.php` (section 4.4): filter `disable`,
  `login`, `start`/`stop`; prefix search on `firstname`, `lastname`,
  `username`; group membership from the serialised `tl_member.groups`.
  The concept allows `LIKE` on the serialised value or PHP post-filtering;
  choose, keep it inside one gateway or query class so it can be swapped,
  and record the choice. Provider options come from
  `providers.<alias>` in the bundle config and are injected via the
  constructor; the bundle class or `services.yaml` binds them.
- The adapter that implements phase 1's `ContactPermissionInterface` by
  delegating to `ContactService`, and the `services.yaml` alias switched
  from `DenyContactPermission` to it. Delete `DenyContactPermission` if
  nothing else uses it; keep the interface.
- Phase 1 follow-up (concept section 15): in `ConversationService::openWith`
  move the rate limiter consumption behind the existing-conversation
  lookup so that opening an existing conversation costs no token.
  Adjust the existing unit test accordingly.
- Translations for the placeholder contact name and any new labels,
  Symfony PHP resources with full Contao keys as learned in phase 1.
- Tests: unit tests for `ContactService` (normalisation, self-exclusion,
  dedupe, limit, min length, own-ID denial), `ContactFactory` (name
  fallback, avatar null paths, batch resolution with stubbed
  `FilesModel`/`Studio`), `ContactResolver` (placeholder, batch), the
  registry (alias lookup, unknown alias), the permission adapter, and both
  providers with mocked storage. Integration tests for both providers
  against the real test database: extend the phase 1 integration setup
  with a minimal `tl_member` table (the columns the providers read) and
  cover active/inactive members, `start`/`stop`, group filtering with
  serialised arrays, prefix matching and asymmetric `canContact`.
  Add a `ConversationService` integration case proving a permitted contact
  now creates a conversation end to end through the adapter.

## Rules that override everything else

- Verify every Contao class, method, attribute signature and constant in
  `vendor/contao/core-bundle/` before using it. Do not invent APIs. Record
  every central API choice in `.docs/build/DECISIONS.md` with the
  `vendor/` path as evidence; append a "Phase 2" section rather than
  rewriting phase 1 entries.
- Never claim a check ran. Every check in your report shows the exact
  command and its real, shortened output. A check you could not run is
  reported as "not verified".
- Code, comments, commits and docs in English; German only in translation
  files.
- Attributes for all registrations, no trivial wrappers, `final` by
  default, `src/Domain/` for value objects (`Contact`), `src/Contact/` for
  the contact layer as laid out in concept section 2.
- Do not modify the host DDEV project's code. Use it to run commands and
  to verify that the container compiles with the real provider wired in
  (`debug:container` on `ContactService` and the permission adapter) and
  that `contao:migrate --dry-run` reports no schema change from this phase.
- Run PHPUnit (both suites, with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan and Rector dry run; fix what they report without lowering the
  PHPStan level.
- Commit in small, meaningful steps. Do not start phase 3.

## Report

Finish with `.docs/build/reports/phase-2-contacts.md`: files by concept
section, decisions and non-verifiable items, exact commands and output for
PHPUnit, ECS, PHPStan, Rector, `debug:container` and the migration dry
run, open questions for phase 3, and anything in the concept that turned
out wrong or incomplete.
