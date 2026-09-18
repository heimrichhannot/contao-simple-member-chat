# Phase 5 — Optional PWA push integration inside the bundle

Phases 1 to 4b are complete and committed. This phase adds push
notifications for new chat messages as an **optional integration inside
this bundle**, with a loose dependency on
`heimrichhannot/contao-pwa-bundle`.

Note the reversed decision: concept section 13, decision 3 originally
called for a separate bridge package. It was revised on 2026-09-18 — the
integration lives here. Do not create another repository.

## Read first

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md` — section 8 (events and push), decision 3 as
   revised, decision 4 (deep links), decision 8 (mute) and section 15 for
   what earlier phases established as the stable API.
3. `.docs/build/DECISIONS.md`, especially the phase 1 transaction and
   event rules and the phase 4 note on recipient state.
4. The code: `MessageSentEvent`, `MessageGateway`, `ParticipantGateway`
   (`state`, `lastPageId`), `ConversationUrlGenerator`, `ContactResolver`,
   `ChatOptions`, the bundle class and `config/services.yaml`.
5. The PWA bundle at `/home/dev/Kunden/github/contao-pwa-bundle`
   (read-only). Verify its real API before using it; what is known so far:
   - `src/Sender/PushNotificationSender.php`:
     `send(AbstractNotification $notification, PwaConfigurationsModel $config, ?array $subscribers = null)`
     and `sendWithLog(...)` with a PSR logger.
   - `src/Model/PwaPushSubscriberModel.php` with `pid` (→
     `tl_pwa_configurations`), `member` (→ `tl_member`), `endpoint`,
     `publicKey`, `authToken`.
   - `src/Notification/{AbstractNotification,DefaultNotification}.php`:
     `toArray()` collects every public `get*()` without parameters, so a
     subclass can add payload fields. Check how a click target reaches the
     payload — `src/DataContainer/PwaPushNotificationContainer.php` writes
     `$payload['data']['clickJumpTo']` for backend notifications.
   - The PWA bundle currently requires PHP 8.2 and Contao 5.3 and only
     suggests `minishlink/web-push`. Do not change that bundle.

## Scope

### Loose dependency

- `composer.json`: add `heimrichhannot/contao-pwa-bundle` under `suggest`
  with a sentence saying it enables push notifications for new messages.
  Do **not** add it to `require`. Add it to `require-dev` only if the
  tests genuinely need the real classes; if you do, make sure the bundle
  still installs and boots without it.
- The integration services must not exist when the PWA bundle is absent.
  Register them in `loadExtension` only when the relevant PWA classes are
  available (`class_exists`), or load a separate services file under that
  condition. A project without the PWA bundle must see no new services,
  no container errors and no behaviour change; prove it with a container
  test for both cases.
- No PWA class may be referenced from code that always loads. Keep the
  integration in its own namespace, for example `src/Integration/Pwa/`.

### Behaviour

- `#[AsEventListener]` on `MessageSentEvent`. The listener does no sending
  itself: it puts a message on the Messenger bus carrying the message id
  and the recipient ids, implementing the core's
  `LowPriorityMessageInterface` so the web request never waits. Verify
  that interface and Contao's transport routing in
  `vendor/contao/core-bundle/src/Messenger/`.
- The handler re-reads the current state — the queue may run much later:
  load the message, skip recipients whose participant row is missing or
  muted, and skip recipients who were active in the conversation within a
  configurable number of seconds (`ParticipantGateway::state()` has
  `lastReadAt`; mind the activity throttle from phase 3b, which delays
  that value by up to `polling.activity_throttle` seconds — document the
  consequence).
- Build the notification from the sender's display name via
  `ContactResolver` and a truncated body; the click target is
  `ConversationUrlGenerator::forConversation($conversation, $recipientId)`
  resolved per recipient, absolute. When no URL can be resolved, still
  send without a deep link.
- Select subscribers for the remaining recipients. Subscribers belong to a
  PWA configuration, so decide how the configuration is chosen and make it
  explicit in the configuration tree: a fixed configuration id, or all
  configurations that have push enabled. Never send to subscribers of a
  member who is not a recipient.
- A failing push must not affect the chat: log and continue, per the
  phase 1 rule that listener failures are logged after commit.

### Configuration

Extend the bundle configuration under a new `push` node, defaults chosen
so that an installation without the PWA bundle is unaffected:

- `enabled`: whether the integration is active at all when available.
- `configuration`: the PWA configuration to send through, or a mode that
  covers all push-enabled configurations.
- `active_recipient_grace`: seconds since `lastReadAt` within which no
  push is sent.
- `body_length`: how much of the message text goes into the payload, with
  a note in the README that push payloads leave the server and end up on
  devices; make it possible to send no message text at all.

Document every option in the README with its default and unit.

### Documentation and tests

- README section on push: what it needs, how to enable it, the privacy
  consideration about message text in payloads, the activity throttle
  consequence, and that the chat works unchanged without the PWA bundle.
- CHANGELOG entry.
- Unit tests for the listener (message enqueued with the right payload,
  nothing enqueued when the integration is disabled), for the handler
  (muted, missing, recently active and eligible recipients; deep link
  resolution; failure logged, not thrown) with the PWA sender mocked.
- A container test proving the services exist only when the PWA classes
  are available.
- Integration test for the recipient filtering against the real database.

## Rules

- Verify every Contao, Symfony and PWA API in `vendor/` or the sibling
  repository before use; append a "Phase 5" section to `DECISIONS.md` with
  file paths as evidence, and record anything the PWA bundle does not
  offer so the concept can be corrected.
- Never claim a check ran; unrun checks are "not verified". Sending a real
  push is not expected — say so rather than implying delivery works.
- English everywhere except translation files; attributes for every
  registration; no trivial wrappers; `final` by default; `src/Model/`
  stays reserved for Contao Active Record classes.
- Preserve every existing cursor rule, data attribute and view contract.
- Do not modify the PWA bundle or host application code.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan, Rector, `lint:twig`, the client tests, the webpack build and
  `.docs/build/verify-phase-3a-http.sh`. Fix findings without lowering the
  PHPStan level.
- Commit in small, meaningful steps.

## Report

`.docs/build/reports/phase-5-push.md`: files by concept section, the
chosen configuration-selection strategy and why, decisions and
non-verifiable items, exact commands and output for every check, what a
real end-to-end push would still need, and anything in the concept that
turned out wrong.
