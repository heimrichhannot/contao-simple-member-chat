# Contao Simple Member Chat

Private one-to-one plain-text conversations for Contao frontend members, with
contact search, unread counts, muted conversations, history loading and backend
moderation. Updates use Turbo Frame polling. Package:
`heimrichhannot/contao-simple-member-chat` (LGPL-3.0-or-later).

## Requirements and installation

- PHP **8.4+**, Contao **5.7**, Symfony 7.4, Doctrine DBAL 3.10.
- A configured `heimrichhannot/contao-encore-bundle` installation and asset build.
  Enable Encore for legacy page layouts too.
- Project-provided `heimrichhannot/contao-ux-turbo-encore`: activate either
  `huh_ux_turbo_encore` (Drive; recommended for PWA projects) or
  `huh_ux_turbo_encore_no_drive`. The chat does not import a second Turbo.
- For Android keyboard resizing, the page layout needs
  `<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">`.

```sh
composer require heimrichhannot/contao-simple-member-chat
php vendor/bin/contao-console contao:migrate
```

Install via Contao Manager alternatively. Rebuild the project's Encore assets
and clear its cache after installation. The bundle automatically enables
`huh_member_chat` on pages containing its content element. Use your project's
normal webpack command; this repository's `.docs/build/phase-3a-webpack.cjs`
is specific to the local demo, not a portable production configuration.

Create a regular published chat page with the **Member chat** content element,
a frontend login path, and appropriate page/member-group access. Select this
page in **Chat page** (`memberChatPage`) on its root page, including fallback
roots. Configure the provider's member groups; the default empty group list
intentionally exposes no contacts. Frontend modules are not needed.

## Deliberate non-goals

Only 1:1 plain text: no groups, attachments, formatting/Markdown, emoji picker,
reactions, user editing/deletion, block list, read-receipt UI or server push
(WebSocket/Mercure/SSE). Unicode text, including typed emoji, is allowed. Muting
suppresses unread counts and provides a push opt-out; messages still arrive.
There are no content-element configuration fields, retention cron or PWA/Messenger
handlers in this package. The separate PWA bridge is phase 5.

## Configuration

All values below are defaults; put overrides in the host's `config/config.yaml`.

```yaml
contao_member_chat:
    contact_provider: member_groups
    polling:
        messages_interval: 4000        # milliseconds
        conversations_interval: 15000  # milliseconds
        badge_interval: 30000          # milliseconds
        max_interval_multiplier: 8     # maximum exponential backoff multiplier
        activity_throttle: 30          # seconds; 0 disables throttling
    message:
        max_length: 2000               # Unicode characters after normalization
        rate_limit: 30                 # sends + new contact starts / minute / member
        rate_limiter: null             # optional framework.rate_limiter service name
    list:
        page_size: 50                  # messages/conversations per history window
        search_limit: 20               # maximum contacts
        search_min_length: 2           # Unicode characters, after trimming
    contact:
        avatar_field: null             # binary file UUID column on tl_member
        avatar_size: [96, 96, 'crop']   # pixels + mode, or Contao image size ID
    providers:
        member_groups:
            groups: []                # positive tl_member_group IDs
```

Options are available through immutable `Configuration\ChatOptions`. Unknown
provider aliases fail at container compilation. Extra provider configuration
nodes are retained; custom providers must validate and wire their own options.
The built-in `shared_groups` alias needs no options and uses the viewer's active
groups. Both built-ins filter disabled/non-login/time-restricted target accounts.
`member_groups` does not require the viewer to belong to the target groups.
Search matches literal field/word prefixes, not arbitrary substrings.

Activity-only updates to `lastReadAt` **and `lastPageId`** are throttled. Switching
chat pages can therefore leave the old deep-link destination until the throttle
expires (default 30 seconds). A newly delivered read advances immediately;
muting also changes list state immediately. `lastReadAt` is activity evidence,
not a precise presence signal or read receipt. Opening an existing conversation
does not consume the contact-start rate limit. Default limiter storage is
`cache.app`, with a sliding one-minute window.

## Site-wide unread badge

```twig
{{ member_chat_unread_badge({class: 'nav__badge'}) }}
```

Activate **`huh_member_chat_badge` plus one Turbo entry** in every layout/page
using the function without a chat element, and rebuild assets. This entry loads
the shared polling script with **no chat CSS**. A chat page already loads that
script through `huh_member_chat`. The function does not activate assets while
rendering a late page template. Ensure badge-only pages also have
`<meta name="turbo-cache-control" content="no-cache">` so Drive does not restore
an old navigation badge from a snapshot. Chat content-element pages add it
automatically. Authenticated pages must not be forced into a shared page cache.

There is one `chat-unread` frame per page. The function renders nothing without
a logged-in frontend member. At zero it renders an empty, pollable frame; at a
positive count it renders a translated accessible link (`aria-label` includes
the count), or a span if no destination can be resolved. Muted conversations
are excluded. `aria-live="off"` avoids repeated announcements. Theme the badge
with your navigation CSS. Without Turbo/the entry, the server value stays static.
There is deliberately no bare-number Twig function.

`GET /_member_chat/unread` is private/no-store and requires authentication
(401 otherwise). `_locale` preserves the embedding page language. Markup carries
`data-chat-poll="badge"`, `data-chat-mode="full"`, `data-chat-url`, interval and
maximum interval; responses never carry a self-referencing `src`. The poller
loads/reloads full frames and continues polling at zero. It pauses in hidden
tabs or hidden navigation containers and backs off after failures. Function
usage marks the complete response private/no-store, including anonymous renders.

## Contact providers

Implement `Contact\ContactProviderInterface` in an autowired/autoconfigured
service. Its interface attribute registers the provider; no manual service tag
is needed. For example, an application with its own contact directory can use:

```php
namespace App\Chat;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;

final readonly class TeamContacts implements ContactProviderInterface
{
    public function __construct(private TeamDirectory $directory) {}

    public static function getAlias(): string { return 'teams'; }

    /** @return list<Contact> */
    public function search(Viewer $viewer, string $query, int $limit): array
    {
        // Application service returns display DTOs for matching permitted members.
        return $this->directory->search($viewer->memberId, $viewer->groupIds, $query, $limit);
    }

    public function canContact(Viewer $viewer, int $memberId): bool
    {
        return $this->directory->mayContact($viewer->memberId, $memberId);
    }
}
```

`TeamDirectory` is a project dependency to implement. Set
`contact_provider: teams`. `Viewer` holds `memberId: int` and active
`groupIds: list<int>`. `Domain\Contact` holds `memberId`, `displayName`, optional
`subtitle` and optional `avatarUrl`; groups never enter this display DTO.
`ContactFactory::fromMemberModel()`, `fromMemberRow()` and `fromMemberRows()`
provide the common name/avatar mapping; batch conversion resolves file UUIDs
in one query. Name fallback is first/last name, then username.

`ContactService` owns query trimming, minimum length, maximum limits, self
exclusion and deduplication. Providers must filter visibility, honor the supplied
limit, and implement the authoritative `canContact()` check. Permissions may
be asymmetric. **Only starting a new conversation checks provider permission**;
existing conversations stay readable/writable after access to that contact is
revoked. `ContactResolver::resolveMany()` independently resolves known members,
including inactive members; missing members become a translated placeholder
with `memberId = 0`. Result limits are caps, not a guarantee to fill every slot.

## Template and view contract

Templates live under `contao/templates/` in `@Contao`, with `.twig-root`.
Use Contao's template hierarchy to extend, rather than copy, templates:

```twig
{% extends '@Contao/content_element/member_chat.html.twig' %}
{% block conversation_header %}
    <div class="project-chat-header">{{ parent() }}</div>
{% endblock %}
```

Content-element blocks: `layout`, `search`, `conversations`,
`conversation_header`, `messages`, `compose`, `empty_state`, `login_hint`.
Partials in `@Contao/member_chat/` include `conversation_list`,
`conversation_list_item`, `message_list`, `message`, `compose_form`,
`contact_results`, `contact_result`, `mute`, `load_more`, `new_messages`,
`day_separator`, and `unread_badge`. Override partials for changes that must also
apply to standalone frame/stream responses. `message_status` is deliberately
empty; a project may render `readByPartner` there.

Readonly public view objects in `View\Model`:

| Object | Properties |
| --- | --- |
| `ChatView` (`view`) | `conversations: list<ConversationItemView>`, `messages: list<MessageView>`, `partner: ?Contact`, `lastMessageId: int`, `changedAt: int`, `beforeMessageId: ?int`, `beforeConversation: ?string`, `muted: bool`, `conversationFingerprint: string` |
| `ConversationItemView` | `uuid: string`, `partner: Contact`, `url: string`, `excerpt: string`, `lastMessageAt: int`, `changedAt: int`, `unreadCount: int`, `muted: bool` |
| `MessageView` | `id: int`, `author: Contact`, `body: string`, `createdAt: int`, `own: bool`, `readByPartner: bool`, `daySeparator: ?DaySeparatorView` |
| `DaySeparatorView` | `day: string` (`Y-m-d`), `label: string` |

Timestamps are Unix seconds. Context includes `page_id`, `language`,
`datim_format`, `options`, `request_token`, `conversation_uuid`, `back_url`,
`list_url`, `search_url`, `open_url`, and for a selected conversation
`messages_url`, `compose_url`, `mute_url`, `send_url`; also `embedded`, `history`,
`query`, `contacts`, `error`, `text`. Anonymous content elements have `view = null`.
Roots/frames use `attrs().mergeWith(...)`; partial-specific `*_attributes`
variables and `chat_attributes` allow classes/data attributes without replacing
markup. Badge attributes cannot replace its required ID/polling/accessibility
attributes. Never escape stored message text yourself: autoescaping and
`member_chat_autolink|nl2br` handle text and safe http/https links.

Preserve these script contracts when overriding markup:

- IDs: `chat-search`, `chat-conversations`, `chat-messages`, `chat-compose`,
  `chat-mute`, `chat-mute-button`, `chat-more-messages`,
  `chat-more-conversations`, `chat-unread`; `member-chat__*` classes stay stable.
- Polling: `data-chat-poll`, `data-chat-mode`, `data-chat-poll-interval`,
  `data-chat-poll-max-interval`, `data-chat-after`, `data-chat-since`,
  `data-chat-fingerprint`, `data-chat-page-size`. Embedded chat frames alone set
  `src`/`loading`; responses must not point their `src` back at themselves.
- History: `data-chat-auto-load="false"` disables automatic loading but leaves
  buttons usable. Preserve `data-chat-load-more`, `data-chat-url`,
  `data-chat-before`, `data-chat-loaded-before`, `data-chat-message-id`,
  `data-chat-message-day`, `data-chat-day-message`, `data-chat-uuid` and ordering
  `data-chat-last-message-at`. Scroll state uses `data-chat-at-bottom` and
  `data-chat-has-new`; loading/focus/search attributes in supplied forms remain
  necessary. Keep accessible labels, log/live roles and error associations.

Messages use the earliest unseen `after` window in chronological order; full
pages drain until fewer than `page_size` messages arrive. Successful sends
**never advance** `after`, avoiding skipped concurrent incoming messages.
Conversation `since` is inclusive and unbounded against
`GREATEST(lastMessageAt, viewer.tstamp)`. Same-second changes remain visible.
The optional `fingerprint` query argument and `X-Chat-Fingerprint` response
header accompany `X-Chat-Since`; matching/empty polls return 204. The fingerprint
covers standard view data. Extend it if a custom template depends on additional
changing state, or those changes may be suppressed. The initial frame seeds it.

History uses independent `before` cursors and `X-Chat-Before` (empty at
exhaustion), never advancing `after`, `since` or fingerprint. Conversation
history's `(lastMessageAt,id)` tie-breaker is the explicit exception to the
UUID-only navigation rule. `X-Chat-After`/`X-Chat-Count` describe delivered message
polls. Database history sorts by timestamp/ID; client equal-time list ordering
uses UUID, so equal-time ordering can differ. Day boundaries use the server
calendar/timezone; relative message time uses the page language and device
timezone, retaining the full `datetime` and tooltip.

## Styling

Most rules are in the `member-chat` cascade layer. Project styles can override
them; narrow unlayered rules protect the compact header, history minimum,
compose area and pressed mute state against broad theme heading rules. Set
properties on `.member-chat`; maintain accessible contrast after overrides.

| Custom property (prefix `--member-chat-`) | Default |
| --- | --- |
| `offset-top`, `sidebar-width`, `gap`, `radius` | `0px`, `20rem`, `1rem`, `.5rem` |
| `bubble-own-bg`, `bubble-bg`, `bubble-fg` | `#dbeafe`, `#f1f5f9`, `#172033` |
| `accent`, `background`, `border`, `control-fg` | `#174ea6`, `#fff`, `#64748b`, `#fff` |
| `load-more-bg`, `load-more-fg` | accent, control-fg |
| `new-messages-bg`, `new-messages-fg` | accent, control-fg |
| `day-fg` | `#475569` |
| `history-min-height` | `12rem` |
| `header-font-size`, `header-line-height`, `header-gap` | `1rem`, `1.25`, `.5rem` |
| `header-control-font-size`, `header-control-padding` | `.875rem`, `.5rem` |
| `muted-bg`, `muted-fg` | `#334155`, `#fff` |

`--member-chat-viewport-height` is measured by JavaScript on single-column chat
pages and falls back to `100dvh`; `offset-top` is subtracted. This measured
property is the intentional exception to the no-inline-style convention.
CSS determines the layout; the script detects sidebar visibility. The supplied
768px media rule for `.member-chat` changes to
`grid-template-columns: var(--member-chat-sidebar-width) minmax(0, 1fr)` and shows
the sidebar/empty state. Override that rule **and the visibility rules** to move
the breakpoint or use a single column on desktop; media queries cannot use
custom properties. Alternatively disable entry CSS through Encore settings.
Very short viewports can scroll the document to keep minimum history and
controls reachable. No-JavaScript pages retain server rendering and height
fallback. Manual/device checks are in [.docs/BROWSER_CHECKLIST.md](.docs/BROWSER_CHECKLIST.md).

## URLs, events and integrations

`Service\ConversationUrlGenerator` is the single URL builder:

- `forConversation(Conversation $conversation, int $memberId): ?string` checks
  that conversation participant's `lastPageId` first.
- `listPage(int $memberId): ?string` uses the latest positive tracked page by
  `lastReadAt`, then participant ID, across the member's conversations.
- Both fall back to the first published root with a usable `memberChatPage`,
  ordered by root sorting then ID, then return `null`. Deleted, unpublished,
  time-restricted and non-regular destinations are rejected, including an
  unpublished root. Preview mode never enables unpublished deep links.
- `generate(PageModel $page, ?string $uuid = null)` preserves an already known
  content-element page without a query per list row. Explicit empty parameters
  clear an existing auto-item. URL generation does not grant access or verify
  that an editor placed a chat element on the selected page.

Stable integration payloads: `Domain\Conversation` (`id`, `uuid`, `memberLow`,
`memberHigh`, `createdAt`, `lastMessageAt`, `lastMessageId`) and `Domain\Message`
(`id`, `conversationId`, `authorId`, `body`, `createdAt`). UUIDs are public
navigation identifiers; integer IDs are server integration/storage keys.

| Stable event | Payload |
| --- | --- |
| `Event\MessageSentEvent` | `message`, `conversation`, `authorId`, `recipientIds: list<int>` |
| `Event\ConversationCreatedEvent` | `conversation`, `initiatorId` |
| `Event\MessagesReadEvent` | `conversationId`, `memberId`, `upToMessageId` |

Use Symfony `#[AsEventListener]`. Events dispatch after the owning transaction
commits; listener failures are logged and cannot roll back a send. Conversation
creation dispatches only for a new row; reading dispatches only on read-position
advance. There are no extra events for mute/erasure. Services reject nested
transactions.

`Gateway\MessageGateway::find(int): ?Message` (also on its interface) returns
null after deletion. Stable read-only
`Gateway\ParticipantGatewayInterface::state(int $conversationId, int $memberId)`
returns `null` for a removed participant, otherwise
`{lastReadAt: int, lastReadMessageId: int, lastPageId: int, muted: bool}`. This
existing narrow accessor avoids a second wrapper service or exposing SQL.
A push listener uses event recipient IDs, skips missing/muted participants,
and may suppress recent activity by `lastReadAt`. A queued handler must reload
message and participant state before delivery; it may skip an already-read
message using `lastReadMessageId`. No queue payload, handler, PWA dependency or
push delivery implementation is included. That belongs in the separate bridge.

## Moderation and privacy

Backend **Accounts → Member chat** lists member pairs and last-message times.
Its child list shows author, time and a 100-character plain-text excerpt.
Only viewing/deleting is available; module access uses ordinary backend group
permissions. Participants have no module. Member names are batch-resolved per
request/table; no member query per row. For the expected small-site scale, this
batch includes distinct member IDs across that table, not just the visible page.

Deleting a conversation cascades through messages and participants. Deleting a
message repairs the last-message pointer/time, including resetting both to zero
for an empty conversation. Contao invokes `ondelete` before SQL DELETE, so the
repair excludes the pending row. Already open message frames do not receive
delete/tombstone streams; reload to remove moderated text. Contao's undo storage
may retain deleted content; account for core backups/undo in your privacy policy.
Restoration via Undo and concurrent moderation are not verified.

Member deletion anonymizes message authors to `0`, removes their participant
row and leaves the other member's history readable but read-only. The text is
retained and may itself contain personal data; anonymization is not content
redaction. With no participants left, conversation and messages are removed.
The original ordered member-pair IDs remain on a surviving conversation.
Covered paths: backend member deletion; the new close-account content element's
`CloseAccountEvent`; the legacy `closeAccount` hook, both only for `close_delete`.
`close_deactivate` does nothing. Direct SQL, external privacy tools and arbitrary
custom deletion code are not covered; integrate `MemberDataEraser` explicitly.
There is no automatic retention policy.

## Verification limits

Local DDEV verification and exact outputs are recorded in
[the phase 4 report](.docs/build/reports/phase-4-edges.md). The concept records
reviewer-confirmed phase 3c desktop/375px behavior; this phase does not claim a
new browser session. Real iOS/Android keyboards, standalone PWA, screen-reader
announcements, project template/CSS overrides, backend rights/deletion UI,
badge-only navigation behavior, production asset builds, hosted CI and push
delivery remain manual or future acceptance items. Contact/group filtering and
backend batch labeling target small sites; no large-scale benchmark is claimed.
