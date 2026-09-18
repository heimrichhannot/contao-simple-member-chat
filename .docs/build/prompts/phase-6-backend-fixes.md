# Phase 6 — Backend list fixes

Phases 1 to 5 are complete and committed. A test in the real Contao
backend found two defects in the moderation module from phase 4. This
phase fixes exactly those two and nothing else.

## Read first

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md`, section 15, "Erkenntnisse aus dem Backend-Test" —
   it states both defects, the evidence and the root cause. Section 9
   states the intended moderation behaviour.
3. `.docs/build/reports/phase-4-edges.md` and the "Phase 4" section of
   `.docs/build/DECISIONS.md`.
4. The code: `src/EventListener/DataContainer/ChatConversation/ListLabelLabelListener.php`,
   the sibling listener for messages, `src/Backend/MemberLabels.php`,
   `contao/dca/tl_chat_conversation.php`, `contao/dca/tl_chat_message.php`,
   the `contao_tl_chat_*` translation files and `tests/Unit/BackendLabelsTest.php`.

## The two fixes

1. **The conversation list crashes with HTTP 500.** Opening
   `/contao?do=member_chat` throws
   `ValueError: Unknown format specifier` from `vsprintf`, raised inside
   `Contao\CoreBundle\Translation\Translator::trans()` and reached from the
   conversation label listener. Contao applies
   `vsprintf($translated, $parameters)` to every domain whose name starts
   with `contao_` (see `vendor/contao/core-bundle/src/Translation/Translator.php`).
   The key `tl_chat_conversation.summary` uses named placeholders
   (`%first%`, `%second%`, `%time%`), which `vsprintf` misreads as format
   specifiers. The whole module is unusable.
   Fix it so the label renders. Positional `%s` parameters are the Contao
   convention and the neighbouring key `tl_chat_conversation.delete.1`
   already uses them; building the label without the translator or moving
   the string to a non-`contao_` domain are acceptable alternatives if you
   justify the choice. Check every other `trans()` call in the bundle that
   targets a `contao_*` domain for the same mistake and fix those too.
2. **The child list header shows a raw Unix timestamp.** The header of
   `/contao?do=member_chat&table=tl_chat_message&id=<id>` renders
   "Letzte Nachricht 1789653654" instead of a formatted date. Format the
   header fields the way Contao does it for timestamp columns; verify the
   mechanism in the core DCA files rather than guessing.

## Testing requirement

The existing unit test passed while the module was crashing, because it
stubs the translator. Whatever you change must be covered by a test that
exercises the real behaviour:

- Render the conversation label through Contao's actual translator (or an
  equivalent that applies the same `vsprintf` step) so a named-placeholder
  regression fails the test.
- Cover the header formatting with a test or, if that is not reasonably
  testable, add a precise manual step to `.docs/BROWSER_CHECKLIST.md` and
  say so in the report.

Extend `tools/verify-host.php` so it renders both labels and the header
through the booted host container. That script currently proves only
registration, which is why the crash slipped through.

## Rules

- Do not add features and do not touch the frontend. Anything else you
  notice goes into the report, not into the code.
- Verify every API in `vendor/` before use; append a "Phase 6" section to
  `DECISIONS.md` with file paths as evidence.
- Never claim a check ran; unrun checks are "not verified". You cannot log
  into the backend, so state clearly which parts remain to be confirmed in
  the UI and add them to the browser checklist.
- English everywhere except translation files; attributes for every
  registration; no trivial wrappers; `final` by default.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan, Rector, `lint:twig`, the client tests, the webpack build,
  `tools/verify-host.php` and `.docs/build/verify-phase-3a-http.sh`.
- Do not modify host application code. Commit in small, meaningful steps.

## Report

`.docs/build/reports/phase-6-backend-fixes.md`: one section per defect
with the change and the evidence, the other `contao_*` translator calls
you audited, decisions and non-verifiable items, exact commands and
output for every check, and anything new you noticed but did not fix.
