# Phase 3a — Frontend core of `heimrichhannot/contao-simple-member-chat`

Phases 1 and 2 are complete and committed. You are implementing phase 3a:
the content element, the HTTP layer, the Twig templates and the polling
script. Phase 3b (load more, mute toggle, relative time, iOS keyboard,
remaining accessibility) follows separately; do not start it.

## Read first, in this order

1. `AGENTS.md` and `AGENTS.local.md`.
2. `.docs/CONCEPT.md` — sections 5.1 to 5.4, 5.6, 5.6a, 5.7 and 6 are this
   phase. Section 13 lists final decisions, notably 2, 5 (server part
   only), 10, 11, 14 and 15. Section 15 lists what phases 1 and 2
   discovered and what this phase must pick up.
3. `.docs/build/reports/phase-1-foundation.md`,
   `.docs/build/reports/phase-2-contacts.md`, `.docs/build/DECISIONS.md`.
4. `.docs/build/PHASES.md` — scope boundaries for 3a versus 3b.

Then read the code you build on: `ConversationService`, `MessageService`,
`ReadTracker`, `ContactService`, `ContactResolver`, `ConversationGateway`,
`MessageGateway`, `ChatOptions`, `ChatException`, the bundle class and
`services.yaml`.

Reference implementation for the house pattern: the sibling repository
`/home/dev/Kunden/github/contao-qna-bundle` (read-only). Study its
`QnaFrameController`, `QnaActionController`, `TurboResponseFactory`,
`View/` factories, `assets/js/qna.js`, `Asset/EncoreExtension.php`,
`contao/templates/` and `contao/dca/tl_content.php`. Reuse the patterns,
not the code.

## Scope of this phase

### Package and assets

- Add `heimrichhannot/contao-encore-contracts` to `require`, and
  `heimrichhannot/contao-ux-turbo-encore` to `suggest` with the reason
  from concept 0.2 (Turbo is provided by the project). Install inside DDEV.
- `src/Asset/EncoreExtension.php` with entry `huh_member_chat` pointing at
  `assets/js/member_chat.js` (imports `assets/css/member_chat.css`,
  `setRequiresCss(true)`). The script uses `window.Turbo` and logs a
  console error naming both Turbo entries if it is missing (concept 0.2).

### Content element (concept 5.1)

- `#[AsContentElement(type: 'member_chat', category: 'member_chat')]`
  controller in `src/Controller/ContentElement/`. `tl_content` DCA palette
  for the type without own fields (mirror the QnA reader palette), plus
  translations for type and category.
- Resolves the conversation from `auto_item` (UUID, consume the parameter
  the way the QnA reader does), loads it via `findByUuid`, checks the
  voter; unknown, foreign or malformed values → `PageNotFoundException`.
- Without a logged-in member: render the `login_hint` block. Backend
  scope: editor hint only. Activates the Encore entry via
  `PageAssetsTrait`, sets `Cache-Control: private, no-store`.
- When a conversation is open, it records the current page via
  `ReadTracker::markRead(..., pageId)` together with the read position of
  the initially rendered messages, and renders both areas (list and
  conversation) so CSS can lay them out per viewport. Without a
  conversation it renders list plus empty state.
- Investigate how a content element can add
  `<meta name="turbo-cache-control" content="no-cache">` to the head in
  Contao 5.7 (legacy `$GLOBALS['TL_HEAD']` versus the response context of
  modern layouts). Implement what works for both layout types if
  feasible, otherwise implement the working variant and record the gap
  in `DECISIONS.md`.

### Routes (concept 6)

Attribute routes under `/_member_chat/`, `_scope: frontend` via
`config/routes.yaml`, all requiring a logged-in member (401 as an empty
fragment otherwise). `{uuid}` with the RFC 4122 requirement regex; the
controller resolves via `findByUuid` and the voter; failures are 404.
This phase implements:

| Method | Path | Behaviour |
| --- | --- | --- |
| GET | `/_member_chat/conversations` | full frame; with `since` a Turbo Stream of changed items (see list polling below) |
| GET | `/_member_chat/conversations/{uuid}/messages` | full frame; with `after` a Turbo Stream `append` of newer messages, `204 No Content` when nothing is new. Both variants call `ReadTracker::markRead` with the highest delivered ID and the page ID passed as query parameter by the content element |
| GET | `/_member_chat/conversations/{uuid}/compose` | compose frame with CSRF token |
| POST | `/_member_chat/conversations/{uuid}/messages` | `_token_check: true`; on success a Turbo Stream that appends the message to `chat-messages` and replaces `chat-compose` with an empty form; on `ChatException` the compose frame with the error and the preserved text, HTTP status from the exception |
| POST | `/_member_chat/conversations` | `_token_check: true`; `member` in the body; `ConversationService::openWith`; redirect (303) to the chat page URL with the conversation UUID as item; on `ChatException` the search frame with the error |
| GET | `/_member_chat/contacts?q=` | search results frame via `ContactService::search`; each hit has a POST form to the route above |

`before` on both list routes, `/mute` and `/unread` are phase 3b/4. The
chat page URL for the redirect comes from the page the content element
runs on: pass its ID as a hidden field of the search result form and
generate the URL with `contao.routing.content_url_generator` (verified in
concept 14); the full `ConversationUrlGenerator` with `lastPageId` and
root-page fallback is phase 4, so keep this in a small service that phase 4
can extend.

`TurboResponseFactory` as in the QnA bundle: `private, no-store`,
`Vary: Accept`, Turbo Stream content type `text/vnd.turbo-stream.html`.

### List polling with `changedAt` (concept 5.3a, decision 15)

Extend `ConversationGateway::listForMember` so `since` compares against
`GREATEST(c.lastMessageAt, p.tstamp)` of the viewer's participant row and
expose that value as `changedAt` on `ConversationListItem`. Adjust the
existing integration test and add one proving that a read or mute change
in another session surfaces through `since`. The client tracks the maximum
`changedAt` it has seen and sends it as `since`; the response is a Turbo
Stream that removes the old element of the same UUID and inserts the new
one at the position `lastMessageAt` dictates (the simplest correct
approach: `remove` plus `prepend`, since changed conversations move to the
top; document if you choose otherwise).

### Views and templates (concept 5.6, 5.7)

- View factories and view models in `src/View/` like the QnA bundle; view
  models are the public API templates rely on. Partner and author names via
  `ContactResolver::resolveMany` (one call per rendering, never per row).
  Messages carry `readByPartner: bool` for the empty `message_status` block
  (decision 7) without rendering anything.
- `contao/templates/.twig-root`, `content_element/member_chat.html.twig`
  extending `@Contao/content_element/_base.html.twig` with the blocks from
  concept 5.7: `layout`, `search`, `conversations`, `conversation_header`,
  `messages`, `compose`, `empty_state`, `login_hint`. Frame contents in
  partials under `member_chat/`: `conversation_list.html.twig`,
  `conversation_list_item.html.twig`, `message_list.html.twig`,
  `message.html.twig`, `compose_form.html.twig`, `contact_results.html.twig`,
  `contact_result.html.twig`, plus the `.stream.html.twig` templates for the
  Turbo Stream responses. All root elements and frames use `attrs()` with
  `mergeWith(...)`.
- Frame IDs `chat-search`, `chat-conversations`, `chat-messages`,
  `chat-compose` are stable; `chat-mute` is phase 3b. Message elements have
  `id="chat-message-<id>"`, list items `id="chat-conversation-<uuid>"`.
- Message bodies: Twig autoescaping, `nl2br`, URLs linked with
  `rel="noopener nofollow"` and `target="_blank"` through a Twig filter
  implemented in a runtime (`#[AsTwigFilter]`, Twig 3.28 attributes are
  verified) that escapes text and only wraps `http(s)://` URLs. Unit test it
  against injection attempts. No Markdown.
- Timestamps as `<time datetime="<ISO 8601>">` with the server-rendered
  page format (`datimFormat` of the current page) as content; the relative
  client-side replacement and day separators are phase 3b.
- Navigation links (list items, back link) carry `data-turbo="true"`
  (decision 10).
- Accessibility, template part (concept 5.6a): message list `role="log"`
  `aria-live="polite"`, labelled inputs, buttons not divs, `enterkeyhint`,
  `autocomplete="off"`, touch target sizes in CSS.

### Script and styles (concept 5.1, 5.2, 5.6a)

- `assets/js/member_chat.js` after the QnA polling pattern: frames with
  `data-chat-poll` discovered via `turbo:load` and a `MutationObserver`,
  timers cleared on `turbo:before-cache`, exponential backoff capped at
  `max_interval_multiplier`, paused while `document.hidden`, no reload for
  frames that are `busy` or fail `checkVisibility()`.
- Two poll modes per frame: full reload via `frame.reload()`, incremental
  via `fetch` on the frame `src` with `after` (messages) or `since` (list)
  and `Accept: text/vnd.turbo-stream.html`, applying the answer with
  `Turbo.renderStreamMessage()`; `204` counts as success without render.
  Messages: drain `after` pages until fewer than `page_size` arrive
  (section 15). Messages frame uses incremental mode after its first full
  load; list frame likewise. Record the state on the frame in data
  attributes so 3b can read it.
- Sending: form submits through Turbo; on `turbo:submit-end` with success
  the stream response is applied by Turbo itself. Restore focus into the
  new textarea after the compose frame is replaced (QnA focus pattern).
  Enter sends on non-touch devices, Shift+Enter inserts a newline; on touch
  devices Enter inserts a newline and the button sends. Scroll the message
  list to the bottom after initial load and after own messages.
- Contact search: `GET` form targeting the `chat-search` frame, debounced
  submit ~300 ms after input, only from `search_min_length` characters.
- `assets/css/member_chat.css` in cascade layer `member-chat`, class prefix
  `member-chat__`, mobile-first: single column, conversation view fills
  `100dvh` minus `--member-chat-offset-top`, message list scrolls
  internally, compose sticky at the bottom with safe-area padding, the
  list hidden while a conversation is open; from 768 px a two-column grid
  with `--member-chat-sidebar-width`. Define the custom properties from
  concept 5.7 with defaults; visual polish is not the goal, a usable
  baseline is.

### Tests and verification

- Unit tests: view factories (partner/author resolution in one batch,
  `readByPartner`), autolink filter (escaping, only http/https, no
  `javascript:`), `TurboResponseFactory`, the redirect URL helper, and the
  content element's item resolution (malformed UUID, foreign conversation)
  with stubbed dependencies.
- Integration tests: the gateway `since`/`changedAt` cases described above.
- Host verification in DDEV, read-only regarding host code: create in the
  demo project a page with the `member_chat` element, two test members in
  a group, configure `contao_member_chat.providers.member_groups.groups`
  only if the host already has a project config file you may edit for the
  demo (otherwise document that the default empty group list denies
  contacts and test with `shared_groups` via the same file); run
  `contao:migrate --dry-run` (no chat schema change expected), clear the
  cache, `debug:router` for the six routes, and exercise the frames with
  `curl` using a cookie jar after logging in through the Contao front end
  login form: full list, full messages, compose, contact search, a POST
  message with `REQUEST_TOKEN`, an incremental poll returning 204, and a
  foreign UUID returning 404. Paste the exact commands and shortened
  responses. Browser behaviour is a manual check you may not be able to
  run; say so instead of claiming it.

## Phase 2 follow-up included here

`ContactGateway::displayColumns()` introspects the schema on every query
when `contact.avatar_field` is set. Memoise the column check per process
(the class is `readonly`; use a small non-readonly cache object or make the
gateway non-readonly with a private nullable property) and add a unit test
that the schema manager is consulted once.

## Rules that override everything else

- Verify every Contao, Symfony and Twig API in `vendor/` before use. Do
  not invent APIs. Append a "Phase 3a" section to `DECISIONS.md` with
  `vendor/` evidence for each central choice, including the Turbo meta
  investigation, the `Input`/`auto_item` handling, the CSRF token field,
  `#[AsTwigFilter]` autoconfiguration and `datimFormat` access.
- Never claim a check ran. Every check in your report shows the exact
  command and its real, shortened output; unrun checks are "not verified".
- English everywhere except translation files. Attributes for every
  registration. No trivial wrappers. `final` by default. Templates only
  under `contao/templates/`.
- Do not modify host application code. Demo content (pages, members,
  groups, one config file for the demo) is allowed and must be listed in
  the report.
- Run PHPUnit (both suites with `MEMBER_CHAT_TEST_DATABASE_URL`), ECS,
  PHPStan and Rector dry run; fix findings without lowering the PHPStan
  level. If a rule is wrong for templates or JavaScript, say so and skip it
  with a comment.
- Commit in small, meaningful steps. Do not start phase 3b.

## Report

`.docs/build/reports/phase-3a-frontend-core.md`: files by concept section,
decisions and non-verifiable items, exact commands and output for composer,
PHPUnit, ECS, PHPStan, Rector, `debug:router`, the migration dry run and
the `curl` session, the demo content you created in the host, open
questions for phase 3b, and anything in the concept that turned out wrong
or incomplete.
