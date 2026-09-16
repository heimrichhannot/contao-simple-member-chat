# KONZEPT — contao-simple-member-chat

Grobkonzept für eine eigenständige Contao-5.7-Erweiterung: einfache
1:1-Textnachrichten zwischen Frontend-Mitgliedern.

Stand: 2026-09-15. Dies ist ein **Konzept**, kein Implementierungsplan.
Es legt Bausteine, Schnittstellen und Entscheidungen fest; Reihenfolge,
Aufwand und Detailschritte folgen später.

Die Contao-Angaben wurden gegen die Sourcen von `contao/core-bundle`
**5.7.11** geprüft (Vendor-Verzeichnis des Schwesterprojekts
`contao-qna-bundle`). Abschnitt 13 listet die Belege. Vor der Umsetzung ist
das Paket selbst mit `contao/core-bundle:^5.7` als Dev-Abhängigkeit
auszustatten und jede API erneut im eigenen `vendor/` nachzulesen.

---

## 0. Rahmenbedingungen

### 0.1 Paket

| Punkt | Wert |
| --- | --- |
| Composer-Paket | `heimrichhannot/contao-simple-member-chat` |
| Composer-Type | `contao-bundle` |
| PHP-Namespace | `HeimrichHannot\SimpleMemberChatBundle\` → `src/` |
| Bundle-Klasse | `HeimrichHannotSimpleMemberChatBundle` (extends `AbstractBundle`) |
| Extension-Alias | `contao_member_chat` |
| PHP | `^8.4` |
| Contao | `^5.7` |
| Symfony | Baseline von Contao 5.7 (7.4) |
| Lizenz | LGPL-3.0-or-later |

Sprache im Repository: Englisch für Code, Kommentare, Commits, README.
Deutsch nur in Übersetzungsdateien und in diesem Konzept.

### 0.2 Hauskonventionen

Das Bundle folgt den Konventionen des `contao-qna-bundle` (dort in
`AGENTS.md` festgehalten):

* Listener, Callbacks, Hooks, Content-Elemente ausschließlich per PHP-Attribut
  (`#[AsCallback]`, `#[AsHook]`, `#[AsEventListener]`, `#[AsContentElement]`).
* DCA-SQL als Doctrine-Schema-Arrays, keine SQL-Strings.
* Übersetzungen als Symfony-PHP-Ressourcen (`translations/contao_*.de.php`).
* `src/Model/` nur für Contao-Active-Record-Klassen. Eigene Wertobjekte
  liegen in `src/Domain/` ohne `Model`-Suffix.
* Datenbankzugriff über Gateways (DBAL), Geschäftslogik in Services,
  Controller bleiben dünn.
* Frontend-Assets als Encore-Entry über `heimrichhannot/contao-encore-contracts`.
  **Turbo liefert das Projekt**, nicht das Bundle: eine der beiden Entries aus
  `heimrichhannot/contao-ux-turbo-encore` (mit oder ohne Drive) muss auf der
  Chat-Seite aktiv sein. Das Bundle greift auf `window.Turbo` zu und meldet
  sein Fehlen in der Browser-Konsole.

---

## 1. Fachliches Ziel

Eingeloggte Frontend-Mitglieder können sich gegenseitig Textnachrichten
schreiben.

Funktionsumfang:

1. Liste der eigenen Konversationen mit letztem Nachrichtenauszug und
   Ungelesen-Zähler
2. Chat-Fenster einer Konversation mit Nachrichtenverlauf und Eingabeformular
3. Kontaktsuche zum Starten einer neuen Konversation, gespeist aus einer
   austauschbaren Kontakt-Provider-Abstraktion
4. Live-Aktualisierung per Turbo-Frame-Polling (kein Server-Push)
5. Symfony-Event beim Versand einer Nachricht, damit externe Bundles
   reagieren können (konkret: PWA-Push über `contao-pwa-bundle`)
6. Twig-Funktion für einen seitenweiten Ungelesen-Zähler
7. Backend-Ansicht zur Moderation

### 1.1 Entschiedene Einschränkungen

Diese Punkte wurden bewusst festgelegt und gelten für die erste Version:

| Thema | Entscheidung |
| --- | --- |
| Konversationsform | ausschließlich 1:1, keine Gruppen |
| Löschen / Editieren durch Nutzer | nein |
| Blockierliste | nein, Zugang regelt allein der Kontakt-Provider |
| Einbindung | Content-Element auf einer projektseitig angelegten Seite |
| Konfiguration | Symfony-Bundle-Config, keine Felder am Content-Element |
| Anhänge, Formatierung, Emoji-Picker, Reaktionen | nein, reiner Text |
| Server-Push (WebSocket, Mercure, SSE) | nein |
| Frontend-Module | nein |

Das Datenmodell lässt Gruppenchats grundsätzlich zu (Teilnehmertabelle),
die UI und die Services setzen sie aber nicht um.

---

## 2. Architektur

Schichten, von außen nach innen:

```
Content-Element ─┐
Frame-Routen ────┼─▶ Controller ─▶ Services ─▶ Gateways (DBAL) ─▶ tl_chat_*
Action-Routen ───┘        │             │
Twig-Funktion ────────────┘             └─▶ Events (EventDispatcher)
                                                    │
                                                    └─▶ Listener in fremden
                                                        Bundles (PWA-Push)
```

* **Controller** übersetzen HTTP in Service-Aufrufe und rendern Twig.
  Sie enthalten keine Geschäftslogik.
* **Services** sind die einzige schreibende Schicht. Jeder Schreibvorgang
  läuft in einer DBAL-Transaktion und endet mit einem Event.
* **Gateways** kapseln SQL je Tabelle und hydrieren in `Domain`-Objekte.
* **Domain** enthält unveränderliche Wertobjekte (`Conversation`,
  `Message`, `Contact`, `ConversationListItem`).
* **Kontakt-Provider** sind getaggte Services hinter einem Interface, aus
  denen eine Registry per Alias den konfigurierten Provider liefert.

Vorgeschlagene Verzeichnisstruktur:

```
src/
  Asset/EncoreExtension.php
  Configuration/ChatOptions.php
  Contact/ContactProviderInterface.php
  Contact/ContactProviderRegistry.php
  Contact/Provider/MemberGroupsContactProvider.php
  Contact/Provider/SharedGroupsContactProvider.php
  ContaoManager/Plugin.php
  Controller/ContentElement/MemberChatController.php
  Controller/ChatFrameController.php
  Controller/ChatActionController.php
  Domain/{Contact,Conversation,Message,ConversationListItem}.php
  Event/{MessageSentEvent,ConversationCreatedEvent,MessagesReadEvent}.php
  EventListener/DataContainer/Member/ConfigOnDeleteListener.php
  EventListener/CloseAccountEventListener.php
  EventListener/Hook/CloseAccountListener.php
  Exception/…                      (Domain-Exceptions mit Status + Translation-Key)
  Gateway/{ConversationGateway,ParticipantGateway,MessageGateway}.php
  Security/Voter/ConversationVoter.php
  Service/{ConversationService,MessageService,ReadTracker,PollingPolicy,
           FrontendMemberProvider,MemberDataEraser,MessageTextSanitizer}.php
  Twig/ChatRuntime.php              (#[AsTwigFunction])
  View/{ChatViewFactory,TurboResponseFactory,Model/…}.php
contao/
  config/config.php                 (BE_MOD, TL_MODELS falls nötig)
  dca/{tl_chat_conversation,tl_chat_participant,tl_chat_message,tl_content}.php
  templates/.twig-root
  templates/content_element/member_chat.html.twig
  templates/member_chat/…
assets/js/member_chat.js, assets/css/member_chat.css
config/{routes.yaml,services.yaml}
translations/…
```

---

## 3. Datenmodell

Drei Tabellen, Schema per DCA. Alle Zeitstempel als `int` (Unix-Zeit),
wie in Contao üblich.

### 3.1 `tl_chat_conversation`

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK | |
| `tstamp` | int | Contao-Standard |
| `memberLow` | int | kleinere der beiden `tl_member.id` |
| `memberHigh` | int | größere der beiden `tl_member.id` |
| `createdAt` | int | |
| `lastMessageAt` | int, Default 0 | Sortierschlüssel der Liste |
| `lastMessageId` | int, Default 0 | für den Auszug in der Liste, ohne Subquery |

Indizes: **`UNIQUE(memberLow, memberHigh)`**, Index `lastMessageAt`.

Das geordnete Paar ersetzt einen Hash-`pairKey`: zwei Integer-Spalten sind
lesbar, indexierbar und lassen sich direkt für die Zugriffsprüfung nutzen.
Die Eindeutigkeit wird auf Datenbankebene garantiert. Ein
Duplicate-Insert beim gleichzeitigen Start derselben Konversation von beiden
Seiten wird abgefangen und auf die bestehende Zeile umgeleitet.

### 3.2 `tl_chat_participant`

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK | |
| `tstamp` | int | |
| `pid` | int | → `tl_chat_conversation.id` |
| `member` | int | → `tl_member.id` |
| `joinedAt` | int | |
| `lastReadAt` | int, Default 0 | |
| `lastReadMessageId` | int, Default 0 | Basis des Ungelesen-Zählers |

Indizes: **`UNIQUE(pid, member)`**, Index `member`.

Warum eine eigene Tabelle trotz 1:1: Sie hält den *mitgliedsbezogenen*
Zustand (Lesestand) getrennt vom Konversationszustand, macht die Abfrage
„alle Konversationen von Mitglied X" zu einem einfachen Join und lässt
Gruppenchats später ohne Schemabruch zu. Pro Konversation existieren genau
zwei Zeilen.

### 3.3 `tl_chat_message`

| Feld | Typ | Anmerkung |
| --- | --- | --- |
| `id` | int, PK | zugleich Sequenz für `?after=` |
| `tstamp` | int | |
| `pid` | int | → `tl_chat_conversation.id` |
| `author` | int | → `tl_member.id`, `0` nach Anonymisierung |
| `body` | text | Klartext, keine HTML |
| `createdAt` | int | |

Indizes: `(pid, id)`, Index `author`.

Kein `deletedAt`, kein `editedAt`: Nutzer dürfen weder löschen noch
editieren. Backend-Moderation löscht hart (Abschnitt 9).

### 3.4 Referentielle Integrität

DCA-Deklarationen, damit `DC_Table` die Kaskade selbst erledigt:

| Tabelle | `config` |
| --- | --- |
| `tl_chat_conversation` | `ctable = ['tl_chat_participant', 'tl_chat_message']` |
| `tl_chat_participant` | `ptable = 'tl_chat_conversation'` |
| `tl_chat_message` | `ptable = 'tl_chat_conversation'` |

Nicht abgedeckt ist das Verschwinden eines Mitglieds (Abschnitt 10).

### 3.5 Ungelesen-Zähler

Kein Zähler-Cache. Der Wert ergibt sich aus
`COUNT(m.id) WHERE m.pid = p.pid AND m.id > p.lastReadMessageId AND m.author <> :me`.
Für die Liste wird das als ein Join pro Anfrage gerechnet; bei den
erwarteten Datenmengen (Mitgliederchat, keine Massenkommunikation) ist das
ausreichend. Sollte es sich als Engpass zeigen, ist eine denormalisierte
Spalte `unreadCount` in `tl_chat_participant` eine bewusste Folgeentscheidung
mit Migration.

---

## 4. Kontakt-Provider

Der Provider entscheidet, **wen** ein Mitglied finden und anschreiben darf.
Das Bundle bringt keine Annahme mit, dass alle Mitglieder einander sehen.

### 4.1 Interface

```php
namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

#[AutoconfigureTag('contao_member_chat.contact_provider')]
interface ContactProviderInterface
{
    public static function getAlias(): string;

    /** @return list<Contact> */
    public function search(int $viewerId, string $query, int $limit): array;

    public function find(int $viewerId, int $memberId): ?Contact;

    public function canContact(int $viewerId, int $memberId): bool;
}
```

* `search` liefert Treffer für die Kontaktsuche, bereits gefiltert nach dem,
  was `viewerId` sehen darf. Der Provider bestimmt Suchfelder und Sortierung.
* `find` liefert einen einzelnen Kontakt für die Anzeige (Name des
  Gesprächspartners in Liste und Fenster).
* `canContact` ist die serverseitige Wahrheit beim **Anlegen** einer
  Konversation. Sie wird immer geprüft, unabhängig davon, wie die
  Mitglieds-ID in den Request kam.

Bestehende Konversationen bleiben sichtbar, auch wenn `canContact` später
`false` liefert. Dann wird nur das Starten neuer Konversationen verhindert.
Ob auch das Weiterschreiben in einer bestehenden Konversation verboten sein
soll, ist ein offener Punkt (Abschnitt 12).

Die Übergabe von `int $viewerId` statt `FrontendUser` hält die Provider
frei von Contao-Klassen und testbar. Die ID kommt aus dem
`FrontendMemberProvider` (Security-Token, `instanceof FrontendUser`).

### 4.2 `Contact` (Domain)

```php
final readonly class Contact
{
    public function __construct(
        public int $memberId,
        public string $displayName,
        public ?string $subtitle = null,
        public ?string $avatarUrl = null,
    ) {}

    public static function fromMemberRow(array $row): self; // firstname/lastname, Fallback username
}
```

Entscheidung gegen `MemberModel` als DTO: Provider können Kontext
mitliefern, den das Member nicht hat (Abteilung, Rolle, Avatar aus
Fremdquelle), die Anzeigename-Logik liegt an genau einer Stelle, und
Provider sowie Voter sind ohne Datenbank testbar. Die Factory-Methode
macht den Standardfall zu einer Zeile.

### 4.3 Registry und Konfiguration

`ContactProviderRegistry` erhält alle getaggten Provider per
`#[AutowireIterator('contao_member_chat.contact_provider')]` und liefert den
über `contact_provider` konfigurierten Alias. Unbekannter Alias → Fehler
beim Container-Build, nicht zur Laufzeit.

Provider-spezifische Optionen leben unter `providers.<alias>` in der
Bundle-Config und werden dem Provider als Konstruktor-Argument gereicht.

### 4.4 Mitgelieferte Provider

| Alias | Verhalten | Optionen |
| --- | --- | --- |
| `member_groups` | Alle aktiven Mitglieder aus konfigurierten Gruppen, ohne den Suchenden selbst | `groups: [int]` |
| `shared_groups` | Alle aktiven Mitglieder, die mindestens eine Gruppe mit dem Suchenden teilen | keine |

Beide filtern `disable = ''`, `login = '1'` sowie `start`/`stop`, suchen
in `firstname`, `lastname`, `username` (Prefix-Match, `LIKE 'q%'`) und
begrenzen auf `limit`. Die Gruppenzugehörigkeit steht in `tl_member.groups`
serialisiert; für den Gruppenfilter sind daher entweder ein `LIKE` auf die
serialisierte Form oder ein Nachfiltern in PHP nötig. Das ist bei
Mitgliederzahlen im Vereins- oder Intranet-Maßstab vertretbar und wird im
Gateway gekapselt, damit es später austauschbar bleibt.

Projekte ergänzen eigene Provider (etwa „gleicher Verein", „gleiches
Team") durch eine Klasse, die das Interface implementiert. Autoconfigure
erledigt das Tagging.

---

## 5. Frontend

### 5.1 Content-Element `member_chat`

Ein einziges Content-Element, Kategorie `member_chat`, ohne eigene Felder.
Es rendert die Hülle mit drei Bereichen, jeder ein Turbo-Frame:

```
┌─────────────────────────────┬───────────────────────────────────────┐
│ #chat-search                │ #chat-messages                        │
│  Suchfeld + Ergebnisliste   │  Nachrichtenverlauf (Polling)         │
├─────────────────────────────┤                                       │
│ #chat-conversations         │                                       │
│  Liste (Polling, langsam)   ├───────────────────────────────────────┤
│                             │ #chat-compose  Formular               │
└─────────────────────────────┴───────────────────────────────────────┘
```

* Ohne eingeloggtes Mitglied rendert das Element einen Hinweis (oder
  nichts, konfigurierbar per Template-Override). Kein Redirect, das
  regelt die Seitenschutz-Konfiguration des Projekts.
* Im Backend-Scope zeigt es nur einen Editor-Hinweis.
* Die aktive Konversation steht als `auto_item` in der URL:
  `/chat/42`. Ohne Item ist rechts ein leerer Zustand („Konversation
  wählen oder Kontakt suchen") zu sehen. Die ID wird gegen den Voter
  geprüft; fremde Konversationen liefern 404, nicht 403, um Existenz nicht
  preiszugeben.
* Der Controller aktiviert den Encore-Entry `huh_member_chat` per
  `PageAssetsTrait`, setzt `Cache-Control: private, no-store` und taggt
  keine Cache-Tags, weil die Antwort ohnehin nutzerspezifisch ist.

### 5.2 Frames und Polling

Turbo-Frames pollen nicht von selbst. Das Bundle liefert ein kleines
Skript nach dem Muster aus dem QnA-Bundle: Frames mit `data-chat-poll`
werden entdeckt, per `frame.reload()` neu geladen, Intervalle kommen aus
`data-chat-poll-interval` und `data-chat-poll-max-interval`. Verhalten:

* Exponentielles Backoff bei Fehlern, gedeckelt auf das Maximum.
* Pause bei `document.hidden`, Neustart bei Sichtbarkeit.
* Kein Reload, während der Frame Fokus hat oder `busy` ist (verhindert
  Springen während des Tippens).
* `refresh="morph"` auf dem Nachrichten-Frame, damit die Scroll-Position
  und ein halb getippter Text erhalten bleiben.
* Der Nachrichten-Frame scrollt bei neuen Nachrichten nur dann ans Ende,
  wenn der Nutzer ohnehin am Ende stand.

Intervalle (Default, per Config änderbar):

| Frame | Intervall | Grund |
| --- | --- | --- |
| `#chat-messages` | 4 s | aktive Unterhaltung |
| `#chat-conversations` | 15 s | nur Badges und Reihenfolge |
| Ungelesen-Badge (Twig) | 30 s | seitenweit, muss billig sein |

### 5.3 Senden

Das Formular in `#chat-compose` postet an die Action-Route. Die Antwort ist
ein **Turbo-Stream**, der (a) die neue Nachricht an `#chat-messages`
anhängt und (b) `#chat-compose` mit leerem Formular und frischem Token
ersetzt. Der Absender sieht seine Nachricht sofort, ohne auf den nächsten
Poll zu warten. Der nächste Poll liefert dieselbe Nachricht erneut; das
Morphing verhindert Dubletten, weil Nachrichten per `id="chat-message-<id>"`
adressiert sind.

Fehlerfall (leer, zu lang, Rate-Limit, kein Zugriff): Antwort ist ein
HTML-Fragment für `#chat-compose` mit Fehlermeldung und erhaltenem Text,
Status 422 bzw. 429. Turbo rendert 4xx-Antworten in den Frame.

### 5.4 Kontaktsuche

`#chat-search` enthält ein `GET`-Formular mit `data-turbo-frame="chat-search"`
und einem Debounce-Submit (ca. 300 ms nach der letzten Eingabe, mindestens
zwei Zeichen). Ergebnisliste: je Treffer ein `POST`-Button „Chat starten"
an die Action-Route. Die Antwort ist ein Redirect auf die Chat-Seite mit dem
`auto_item` der gefundenen oder neu angelegten Konversation. Existiert schon
eine Konversation mit dem Kontakt, wird sie geöffnet, nicht dupliziert.

### 5.5 Ungelesen-Zähler außerhalb der Chat-Seite

Twig-Runtime mit `#[AsTwigFunction]` (Twig 3.28, Autokonfiguration durch
`symfony/twig-bundle` verifiziert):

| Funktion | Rückgabe |
| --- | --- |
| `member_chat_unread_count()` | `int`, `0` ohne Login |
| `member_chat_unread_badge(attributes = {})` | gerenderter Turbo-Frame mit Polling, leer ohne Login |

Der Badge-Frame lädt die Count-Route (Abschnitt 6) und pollt im
Badge-Intervall. Er benötigt Turbo auf der Seite; fehlt es, zeigt er den
serverseitig gerenderten Anfangswert ohne Aktualisierung.

### 5.6 Darstellung und Templates

* Alle Templates unter `contao/templates/` mit `.twig-root`, damit sie im
  Theme unter `@Contao/member_chat/...` überschreibbar sind.
* Nachrichten: Klartext, `nl2br`, URLs beim Rendern automatisch verlinkt
  (`rel="noopener nofollow"`). Keine Markdown-Verarbeitung.
* CSS im Cascade-Layer `member-chat`, Klassen mit Präfix `member-chat__`.
  Das Bundle liefert eine funktionale Grundgestaltung, keine Theme-Optik.
* Zeitangaben als `<time datetime>` mit relativer Formatierung
  („vor 3 Min.") nur clientseitig, um Cache- und Zeitzonenfragen zu vermeiden.

---

## 6. Routen

Alle Routen liegen unter `/_member_chat/`, Scope `frontend`, Attribute-Routing
über `config/routes.yaml` (wie im QnA-Bundle über den Manager-Plugin
geladen). Alle verlangen ein eingeloggtes Mitglied, sonst 401 als leeres
Fragment. POST-Routen setzen `_token_check: true` und erwarten
`REQUEST_TOKEN` (Contao-CSRF).

| Methode | Pfad | Name | Zweck |
| --- | --- | --- | --- |
| GET | `/_member_chat/conversations` | `contao_member_chat_conversations` | Frame: Liste |
| GET | `/_member_chat/conversations/{id}/messages?after={mid}` | `contao_member_chat_messages` | Frame: Verlauf; mit `after` nur Neues als Turbo-Stream `append`; ohne als volles Frame |
| GET | `/_member_chat/conversations/{id}/compose` | `contao_member_chat_compose` | Frame: Formular |
| POST | `/_member_chat/conversations/{id}/messages` | `contao_member_chat_message_create` | Nachricht senden → Turbo-Stream |
| POST | `/_member_chat/conversations` | `contao_member_chat_conversation_create` | Konversation finden/anlegen (`member` im Body) → Redirect |
| GET | `/_member_chat/contacts?q=…` | `contao_member_chat_contacts` | Frame: Suchergebnis |
| GET | `/_member_chat/unread` | `contao_member_chat_unread` | Frame: Badge |

Lesestand: Das Laden des Nachrichten-Frames (voll oder inkrementell) setzt
`lastReadMessageId` auf die höchste gelieferte ID. Eine eigene
„gelesen"-Route ist nicht nötig. Nebenwirkung eines GET ist hier bewusst
akzeptiert, da idempotent und nur den eigenen Zustand betreffend.

Antwort-Header aller Frame-Routen: `Cache-Control: private, no-store`,
`Vary: Accept`. Der inkrementelle Nachrichten-Poll antwortet bei „nichts
Neues" mit `204 No Content`; das Polling-Skript behandelt 204 als Erfolg
ohne Rendern. Das spart Bandbreite und Rendering pro leerem Poll.

---

## 7. Services

| Service | Aufgabe |
| --- | --- |
| `FrontendMemberProvider` | Mitglieds-ID aus dem Security-Token, wirft `AuthenticationRequiredException` |
| `ConversationService` | `openWith(viewerId, memberId)`: prüft `canContact`, findet oder legt an (Transaktion, Unique-Konflikt → bestehende Zeile), dispatcht `ConversationCreatedEvent` |
| `MessageService` | `send(conversationId, authorId, body)`: Zugriff per Voter, Sanitizing, Längen- und Rate-Limit, Insert, `lastMessageAt/Id` aktualisieren, `MessageSentEvent` |
| `ReadTracker` | `markRead(conversationId, memberId, upToMessageId)`, dispatcht `MessagesReadEvent` nur bei Änderung |
| `MessageTextSanitizer` | Trim, Normalisierung von Zeilenumbrüchen, Entfernen von Steuerzeichen, Längenprüfung. Kein HTML-Stripping nötig, da nie HTML ausgegeben wird (Twig-Escaping) |
| `PollingPolicy` | Intervalle aus `ChatOptions`, wie im QnA-Bundle |
| `MemberDataEraser` | Anonymisierung bei Mitgliedslöschung (Abschnitt 10) |
| `ConversationVoter` | Attribut `MEMBER_CHAT_VIEW`: Mitglied ist Teilnehmer (`memberLow`/`memberHigh`) |

Rate-Limit: `symfony/rate-limiter` ist in Contao 5.7 vorhanden (wird für den
Suchindex genutzt). Eigener Limiter `contao_member_chat.message` als Sliding
Window, Schlüssel = Mitglieds-ID, Default 30 Nachrichten pro Minute.
Konfigurierbar über die Bundle-Config; das Bundle registriert den Limiter
selbst im `loadExtension`, damit das Host-Projekt nichts anlegen muss.

---

## 8. Events und PWA-Anbindung

### 8.1 Events

Finale, unveränderliche Event-Klassen (Symfony `Event`), Payload als
Domain-Objekte plus vorberechnete Empfängerliste:

| Event | Payload | Wann |
| --- | --- | --- |
| `MessageSentEvent` | `Message`, `Conversation`, `int $authorId`, `list<int> $recipientIds` | nach Commit der Nachricht |
| `ConversationCreatedEvent` | `Conversation`, `int $initiatorId` | nur bei echtem Neu-Anlegen |
| `MessagesReadEvent` | `int $conversationId`, `int $memberId`, `int $upToMessageId` | bei geändertem Lesestand |

Dispatch erfolgt **nach** der Transaktion. Ein fehlschlagender Listener darf
den Versand nicht rückgängig machen; er wird geloggt.

`recipientIds` ist bei 1:1 genau eine ID. Die Liste bleibt trotzdem eine
Liste, damit Listener nicht auf 1:1 festgenagelt sind.

### 8.2 PWA-Push

Die Kopplung an `contao-pwa-bundle` ist **nicht** Teil dieses Bundles. Der
Chat kennt das PWA-Bundle nicht; das PWA-Bundle (oder ein Projekt-Listener)
kennt den Chat. Geprüfte Voraussetzungen im PWA-Bundle:

* `tl_pwa_pushsubscriber` trägt ein Feld `member`, das beim Abonnieren aus
  dem eingeloggten `FrontendUser` gesetzt wird. Zielgerichteter Versand an
  ein Mitglied ist also möglich.
* `PushNotificationSender::send(AbstractNotification, PwaConfigurationsModel, ?array $subscribers)`
  akzeptiert eine explizite Empfängerliste.
* `DefaultNotification` trägt Titel, Body, Icon; ein Ziel-URL-Feld für den
  Klick ist zu prüfen (Notification-Click landet derzeit über
  `notificationClickEvent` am Model).

Empfohlener Aufbau des Listeners (im PWA-Bundle als optionale Integration
oder im Projekt):

1. `#[AsEventListener]` auf `MessageSentEvent`.
2. Listener legt nur eine Messenger-Message `SendChatPushMessage(messageId, recipientIds)`
   auf den Bus. Sie implementiert `LowPriorityMessageInterface` aus dem
   Contao-Core, damit die Managed Edition sie auf den Low-Priority-Transport
   routet. Der Web-Request wartet so nicht auf den Push-Versand. Contao
   arbeitet die Queue per Cron-Worker oder Web-Worker (`kernel.terminate`) ab.
3. Der Handler lädt Nachricht und Subscriber nach `member IN (recipientIds)`,
   baut eine `DefaultNotification` (Titel „Neue Nachricht von …", Body
   gekürzt) und ruft den Sender.
4. Optional: kein Push, wenn der Empfänger die Konversation in den letzten
   n Sekunden gepollt hat (er ist gerade aktiv). Dafür genügt ein Blick auf
   `tl_chat_participant.lastReadAt`.

Das Chat-Bundle liefert dafür lediglich eine `MessageGateway::find(int)`-
Methode und die Event-Klassen als stabile öffentliche API.

---

## 9. Backend

* `$GLOBALS['BE_MOD']['accounts']['member_chat']` mit
  `tables = ['tl_chat_conversation', 'tl_chat_message']`.
* `tl_chat_conversation`: Listenansicht, Label „Mitglied A ↔ Mitglied B,
  letzte Nachricht am …", `notCreatable`, `notEditable`, Operationen
  `children` (Nachrichten) und `delete`.
* `tl_chat_message`: Kind-Liste, Label mit Autor, Zeit, Textauszug,
  `notCreatable`, `notEditable`, Operation `delete`. Löschen ist hart.
  Beim Löschen der letzten Nachricht wird `lastMessageId/At` der
  Konversation per `ondelete`-Callback neu bestimmt.
* `tl_chat_participant` bleibt ohne Backend-Modul.
* Berechtigungen laufen über die normalen Backend-Modulrechte des
  Benutzergruppen-DCA.

---

## 10. Datenschutz und Mitgliedslöschung

Nachrichten sind personenbezogen und bilateral: Löscht Mitglied A sein
Konto, hat Mitglied B weiterhin ein legitimes Interesse an seinem Verlauf.

Entscheidung: **Anonymisieren, nicht löschen.**

* `tl_chat_message.author` wird für alle Nachrichten des Mitglieds auf `0`
  gesetzt. Der Text bleibt.
* Die `tl_chat_participant`-Zeile des Mitglieds wird gelöscht.
* Die Konversation bleibt für den anderen Teilnehmer lesbar, ist aber
  schreibgeschützt (nur noch ein Teilnehmer). Sie erscheint in der Liste
  als „Gelöschtes Mitglied".
* Hat eine Konversation nach dem Vorgang keinen Teilnehmer mehr, wird sie
  samt Nachrichten gelöscht.

Erfasste Wege, identisch zum QnA-Bundle:

1. Backend-Löschung: `#[AsCallback(table: 'tl_member', target: 'config.ondelete')]`
2. Kontoschließung im Frontend, neues Content-Element:
   `CloseAccountEvent`, nur bei `reg_close = 'close_delete'`
3. Kontoschließung über das veraltete Modul: `#[AsHook('closeAccount')]`,
   nur bei Modus `close_delete`

Deaktivierung (`close_deactivate`) löst nichts aus. Nicht erfasste Wege
(direktes SQL, fremde DSGVO-Werkzeuge) werden im README benannt.

Optional, nicht in der ersten Version: Aufbewahrungsfrist per Cron
(`#[AsCronJob]`), die Nachrichten älter als n Tage löscht.

---

## 11. Sicherheit

| Thema | Maßnahme |
| --- | --- |
| Authentifizierung | Jede Route und der CE verlangen `FrontendUser` im Token; sonst 401-Fragment bzw. Hinweis |
| Autorisierung | `ConversationVoter` auf jeder Route mit Konversations-ID; Kontaktstart nur nach `canContact` |
| CSRF | Contao-`RequestTokenListener` über `_token_check: true`; Token aus `ContaoCsrfTokenManager::getDefaultTokenValue()` im Formular, wird im Stream-Response mitgeliefert |
| Injection / XSS | Nur Klartext gespeichert, Twig-Autoescaping, Autolink erzeugt Attribute selbst und escapet den Link-Text |
| Spam / Missbrauch | Rate-Limiter pro Mitglied, Maximallänge, Mindestlänge 1 nach Trim |
| Enumeration | Fremde Konversations-IDs liefern 404; Kontaktsuche liefert nur, was der Provider erlaubt; Mindestlänge der Suche 2 Zeichen, `limit` gedeckelt |
| Caching | Alle Antworten `private, no-store`; der CE setzt dieselben Header, damit der Seiten-Cache nichts Nutzerspezifisches hält |
| Turbo-Cache | Chat-Seite mit `<meta name="turbo-cache-control" content="no-cache">` im CE-Template, sonst zeigt Turbo Drive beim Zurück-Navigieren veralteten Verlauf |

---

## 12. Konfiguration

```yaml
# config/config.yaml des Host-Projekts, alle Werte optional (Defaults gezeigt)
contao_member_chat:
    contact_provider: member_groups
    polling:
        messages_interval: 4000        # ms
        conversations_interval: 15000  # ms
        badge_interval: 30000          # ms
        max_interval_multiplier: 8     # Backoff-Deckel
    message:
        max_length: 2000
        rate_limit: 30                 # Nachrichten pro Minute und Mitglied
    list:
        page_size: 50                  # Nachrichten pro Frame-Ladung
        search_limit: 20
        search_min_length: 2
    providers:
        member_groups:
            groups: []                 # leer = kein Treffer, bewusst restriktiv
```

Die Werte landen als Parameter im Container und werden in ein unveränderliches
`ChatOptions`-Objekt gebündelt (Muster `QnaOptions`). Der Node
`providers` ist `ignoreExtraKeys`, damit Projekt-Provider eigene
Unterknoten mitbringen können; jeder Provider validiert seine Optionen im
Konstruktor.

---

## 13. Offene Punkte

Diese Fragen ändern den Aufwand spürbar und sollten vor dem Plan geklärt
werden:

1. **Weiterschreiben nach Provider-Entzug.** Darf in einer bestehenden
   Konversation weiter geschrieben werden, wenn `canContact` inzwischen
   `false` liefert? Vorschlag: ja, lesen und schreiben bleiben erlaubt,
   nur neue Konversationen sind gesperrt. Einfacher und für Nutzer
   nachvollziehbar.
2. **Avatar.** Soll `Contact.avatarUrl` aus einem `tl_member`-Feld
   (Projekt-Erweiterung) befüllt werden oder bleibt es leer, bis ein
   Provider es setzt? Vorschlag: Bundle setzt nichts, Provider sind frei.
3. **Push-Listener-Ort.** Im PWA-Bundle als optionale Integration (mit
   `conflict`/`suggest` in `composer.json`) oder als drittes, kleines
   Brücken-Paket? Vorschlag: im PWA-Bundle, geschützt durch
   `class_exists(MessageSentEvent::class)`.
4. **Ziel-URL im Push.** Der Klick auf die Push-Nachricht soll die
   Chat-Seite mit der Konversation öffnen. Dafür muss der Listener die
   Chat-Seite kennen. Vorschlag: `page_id` in der Bundle-Config des Chats
   optional, damit der Chat selbst URLs zu Konversationen erzeugen kann
   (`ChatUrlGenerator`). Ohne Angabe liefert er `null`, der Push öffnet
   die Startseite.
5. **Zeitzone und Datumsformat** im Verlauf: Contao-Seitenformat
   (`$objPage->datimFormat`) oder relative Zeit per JS? Vorschlag: beides,
   `<time datetime>` mit Server-Fallback im Format der Seite.

---

## 14. Verifizierte APIs (Contao 5.7.11)

Pfade relativ zu `vendor/contao/core-bundle/`.

| API | Beleg |
| --- | --- |
| `#[AsContentElement]` | `src/DependencyInjection/Attribute/AsContentElement.php`: `(?string $type, string $category = 'miscellaneous', ?string $template, ?string $method, ?string $renderer, array\|bool $nestedFragments = false, int $priority = 0, mixed ...$attributes)` |
| CE-Basisklasse | `src/Controller/ContentElement/AbstractContentElementController.php`: `getResponse(FragmentTemplate, ContentModel, Request): Response`; `isBackendScope()` in `AbstractFragmentController` |
| `#[AsCallback]`, `#[AsHook]`, `#[AsCronJob]` | `src/DependencyInjection/Attribute/` |
| Frontend-CSRF | `src/EventListener/RequestTokenListener.php` prüft bei `_token_check === true` das Feld `REQUEST_TOKEN`; `src/Csrf/ContaoCsrfTokenManager.php::getDefaultTokenValue()` |
| `FrontendUser` | `contao/classes/FrontendUser.php`: `id`, `groups`, `getDisplayName()` liefert `trim("$firstname $lastname")` |
| `CloseAccountEvent` | `src/Event/CloseAccountEvent.php`, dispatcht in `src/Controller/ContentElement/CloseAccountController.php`; Modus aus `reg_close` |
| `closeAccount`-Hook | `contao/modules/ModuleCloseAccount.php`: `($intId, $strMode, $objModule)` |
| DCA-Kaskade | `contao/drivers/DC_Table.php::deleteChildren()` folgt `ctable` |
| `tl_member`-Felder | `contao/dca/tl_member.php`: `firstname`, `lastname`, `username` (unique), `email`, `groups` (multiple, serialisiert), `disable`, `login`, `start`, `stop` |
| Messenger-Prioritäten | `src/Messenger/Message/{Low,Normal,High}PriorityMessageInterface.php`; Web-Worker in `src/Messenger/WebWorker.php`; Cron-Worker-Konfiguration in `src/DependencyInjection/Configuration.php` (`messenger.workers`) |
| Rate-Limiter vorhanden | `src/DependencyInjection/ContaoCoreExtension.php` nutzt `Symfony\Component\RateLimiter\RateLimiterFactory`; Paket `symfony/rate-limiter` im Vendor |
| Twig-Attribute | `vendor/twig/twig/src/Attribute/AsTwigFunction.php` (Twig 3.28); Autokonfiguration in `vendor/symfony/twig-bundle/DependencyInjection/TwigExtension.php` (`registerAttributeForAutoconfiguration`) |
| Turbo im Core | Format `turbo_stream` in `src/ContaoCoreBundle.php`; **keine** Frontend-Einbindung von Turbo im Core, daher `contao-ux-turbo-encore` als Projektvoraussetzung |
| Template-Hierarchie | `contao/templates/.twig-root`, Auflösung als `@Contao/...` über `src/Twig/Loader/TemplateLocator.php` |
| Routen über Manager-Plugin | `contao/manager-plugin`: `RoutingPluginInterface::getRouteCollection()` (Muster im QnA-Bundle) |

Geprüft im `contao-pwa-bundle` (Arbeitsstand im Repository):

| API | Beleg |
| --- | --- |
| Subscriber ↔ Mitglied | `src/Model/PwaPushSubscriberModel.php` (`@property int $member`); gesetzt in `src/Controller/NotificationController.php` aus `FrontendUser` |
| Versand mit Empfängerliste | `src/Sender/PushNotificationSender.php::send(AbstractNotification, PwaConfigurationsModel, ?array $subscribers)` |
| Notification-DTO | `src/Notification/DefaultNotification.php` (title, body, icon) |

**Nicht verifiziert:** Die Transport-Namen `contao_prio_low/normal/high`
und deren Routing stammen aus `contao/manager-bundle`, das im geprüften
Vendor-Verzeichnis nicht vorlag. Vor der Umsetzung im Ziel-Projekt prüfen.
