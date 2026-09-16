# KONZEPT — contao-simple-member-chat

Grobkonzept für eine eigenständige Contao-5.7-Erweiterung: einfache
1:1-Textnachrichten zwischen Frontend-Mitgliedern.

Stand: 2026-09-16. Dies ist ein **Konzept**, kein Implementierungsplan.
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
* Datenbankzugriff über Gateways, Geschäftslogik in Services, Controller
  bleiben dünn. Ob ein Gateway DBAL oder Contao-Models nutzt, entscheidet
  der Anwendungsfall: Listen, Joins und Zähler über die Chat-Tabellen per
  DBAL; Zugriffe auf Core-Tabellen mit vorhandener Model-Logik
  (`MemberModel`, `FilesModel`) per Model.
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
Frame-Routen ────┼─▶ Controller ─▶ Services ─▶ Gateways ─▶ tl_chat_*, tl_member
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
* **Gateways** kapseln den Datenzugriff je Tabelle und hydrieren in
  `Domain`-Objekte. Chat-Tabellen per DBAL (Joins, `COUNT`, `FOR UPDATE`),
  Mitglieder- und Dateizugriffe per `MemberModel`/`FilesModel`, wo deren
  Finder und Logik bereits passen.
* **Domain** enthält unveränderliche Wertobjekte (`Conversation`,
  `Message`, `Contact`, `ConversationListItem`). `Conversation` trägt
  `id` und `uuid`; Templates und URL-Generierung verwenden ausschließlich
  `uuid`.
* **Kontakt-Provider** sind getaggte Services hinter einem Interface, aus
  denen eine Registry per Alias den konfigurierten Provider liefert.

Vorgeschlagene Verzeichnisstruktur:

```
src/
  Asset/EncoreExtension.php
  Configuration/ChatOptions.php
  Contact/ContactProviderInterface.php
  Contact/ContactProviderRegistry.php
  Contact/ContactFactory.php
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
  Twig/ChatRuntime.php              (#[AsTwigFunction] member_chat_unread_badge)
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
| `id` | int, PK | nur intern: Joins, `pid` der Kindtabellen |
| `uuid` | binary(16) | öffentliche Kennung in URLs, Symfony Uid v7 |
| `tstamp` | int | Contao-Standard |
| `memberLow` | int | kleinere der beiden `tl_member.id` |
| `memberHigh` | int | größere der beiden `tl_member.id` |
| `createdAt` | int | |
| `lastMessageAt` | int, Default 0 | Sortierschlüssel der Liste |
| `lastMessageId` | int, Default 0 | für den Auszug in der Liste, ohne Subquery |

Indizes: **`UNIQUE(memberLow, memberHigh)`**, **`UNIQUE(uuid)`**, Index
`lastMessageAt`.

**Öffentliche Kennung ist die UUID, nicht die ID.** Fortlaufende IDs
verraten Bestand und Wachstum und machen Adressen ratbar; der Voter wäre
die einzige Verteidigungslinie. Die UUID erscheint im `auto_item`, in
allen Frame- und Action-Routen und in Push-Deep-Links. Die Integer-ID
verlässt den Server nicht. Nachrichten-IDs bleiben Integer, weil sie nur
innerhalb einer bereits autorisierten Konversation sichtbar sind und für
`?after=` und das Morphing praktisch sind. UUIDv7 ist zeitlich sortiert
und fragmentiert den Index nicht; Contao selbst nutzt für `tl_files`
v1 im selben `binary(16)`-Format. In URLs steht die RFC-Schreibweise
(36 Zeichen), in der Datenbank die Binärform.

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

### 4.2 `Contact` (Domain) und `ContactFactory`

```php
final readonly class Contact
{
    public function __construct(
        public int $memberId,
        public string $displayName,
        public ?string $subtitle = null,
        public ?string $avatarUrl = null,
    ) {}
}
```

Das DTO ist dumm und hat keine statische Factory-Methode. Die Erzeugung aus
Contao-Daten übernimmt ein Service `ContactFactory`, weil sie Logik und
Abhängigkeiten braucht:

```php
final readonly class ContactFactory
{
    public function fromMemberModel(MemberModel $member, ?string $subtitle = null): Contact;
    public function fromMemberRow(array $row, ?string $subtitle = null): Contact;
}
```

* **Anzeigename:** `trim(firstname.' '.lastname)`, Fallback `username`.
  Entspricht `FrontendUser::getDisplayName()`, aber ohne eingeloggten
  User zu benötigen.
* **Avatar:** Contao speichert in Dateifeldern nur eine binäre UUID. Die
  Factory löst sie über `Studio::createFigureBuilder()->fromUuid()->setSize()`
  auf und liefert die URL des skalierten Bildes. Der Core kennt kein
  Avatar-Feld an `tl_member`; deshalb ist der Feldname konfigurierbar
  (`contact.avatar_field`, Default `null` = kein Avatar) und ebenso die
  Bildgröße (`contact.avatar_size`, Contao-Bildgrößen-ID oder
  `[Breite, Höhe, Modus]`). Fehlt die Datei oder ist das Feld leer, bleibt
  `avatarUrl` `null`; das Template zeigt dann Initialen.
* Beide Methoden bleiben: `fromMemberModel()` für Provider, die über
  `MemberModel`-Finder arbeiten, `fromMemberRow()` für Provider mit eigener
  DBAL-Abfrage. Eine Batch-Variante `fromMemberRows(array $rows)` löst
  Avatare gesammelt über `FilesModel::findMultipleByUuids()` auf, damit
  eine Suche mit 20 Treffern nicht 20 Einzelabfragen erzeugt.
* Provider rufen die Factory auf und geben nur den `subtitle` selbst dazu.
  Ein Provider mit eigener Datenquelle für Avatare kann das DTO auch
  direkt bauen.

Entscheidung gegen `MemberModel` als DTO: Provider können Kontext
mitliefern, den das Member nicht hat, die Namens- und Avatar-Logik liegt an
genau einer Stelle, und Provider sowie Voter sind ohne Datenbank testbar.

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
Die Hauptnutzer sind Smartphone-Nutzer; das Layout ist deshalb
**mobile-first mit zwei Ansichten**, die sich aus der URL ergeben und nicht
aus JavaScript-Zustand:

```
Ohne auto_item  (/chat)           Mit auto_item  (/chat/<uuid>)
┌─────────────────────────┐       ┌─────────────────────────┐
│ #chat-search            │       │ ← Zurück   Max Muster   │  Kopf, statisch
│  Suchfeld               │       ├─────────────────────────┤
│  Ergebnisliste          │       │ #chat-messages          │
├─────────────────────────┤       │  Verlauf (Polling)      │
│ #chat-conversations     │       │  scrollt, füllt Höhe    │
│  Liste (Polling, langsam│       │                         │
│                         │       ├─────────────────────────┤
│                         │       │ #chat-compose  sticky   │  nie gepollt
└─────────────────────────┘       └─────────────────────────┘
```

* **Listenansicht** (kein Item): Suche oben, Konversationen darunter. Ein
  Tipp auf eine Konversation ist ein normaler Link auf `/chat/<uuid>`. Mit
  Turbo Drive ist das ein Seitenwechsel ohne Reload, ohne Drive ein
  normaler.
* **Konversationsansicht** (Item vorhanden): Kopf mit Zurück-Link auf
  `/chat` und dem Namen des Gesprächspartners, darunter der Verlauf, unten
  das Formular. Der Kopf ist statisch gerendert, kein Frame.
* **Ab Tablet-Breite** (Breakpoint 768 px in der Bundle-CSS; Custom
  Properties funktionieren in Media Queries nicht, das Projekt überschreibt
  den Wert über eigene Grid-Regeln, siehe 5.7) rendert der CE beide
  Ansichten nebeneinander als CSS-Grid. Dafür liefert der Server bei vorhandenem Item **beide**
  Bereiche; auf dem Smartphone blendet CSS die Liste aus. Das
  Polling-Skript pollt keine unsichtbaren Frames
  (`element.checkVisibility()`), damit die ausgeblendete Liste auf dem
  Smartphone keine Requests erzeugt. Ohne Item rendert der Server auf
  Desktop rechts einen leeren Zustand.
* Ohne eingeloggtes Mitglied rendert das Element einen Hinweis. Kein
  Redirect, das regelt die Seitenschutz-Konfiguration des Projekts.
* Im Backend-Scope zeigt es nur einen Editor-Hinweis.
* Fremde, unbekannte oder syntaktisch ungültige UUIDs liefern 404, nicht
  403, um Existenz nicht preiszugeben. Die UUID wird vor dem Lookup
  geparst; der Voter arbeitet danach intern auf der ID.
* Der Controller aktiviert den Encore-Entry `huh_member_chat` per
  `PageAssetsTrait` und setzt `Cache-Control: private, no-store`.

Mobile Details, die das Bundle mitbringt:

| Thema | Lösung |
| --- | --- |
| Höhe | Konversationsansicht füllt `100dvh` abzüglich Kopf des Projekts (Offset als CSS-Custom-Property `--member-chat-offset-top`, Default 0); der Verlauf scrollt intern, nicht die Seite |
| Virtuelle Tastatur | `#chat-compose` mit `position: sticky; bottom: 0`; Viewport-Meta des Projekts sollte `interactive-widget=resizes-content` setzen, wird im README dokumentiert |
| Eingabe | `<textarea>` mit Auto-Grow bis 5 Zeilen, `enterkeyhint="send"`, `autocomplete="off"`, `autocapitalize="sentences"`; Enter sendet auf Desktop, auf Touch-Geräten macht Enter einen Umbruch und der Senden-Button sendet |
| Scrollen | Nach dem Laden und nach eigener Nachricht ans Ende; bei eingehenden Nachrichten nur, wenn der Nutzer bereits am Ende war, sonst Hinweis „Neue Nachrichten ↓" |
| Touch-Ziele | mindestens 44 × 44 px für Listeneinträge, Zurück, Senden, Suchtreffer |
| Safe Areas | `padding-bottom: env(safe-area-inset-bottom)` am Formular für PWA im Standalone-Modus |
| Standalone-PWA | Zurück-Link ist Pflicht, weil es keine Browser-Navigation gibt |

### 5.2 Frames und Polling

Turbo-Frames pollen nicht von selbst. Das Bundle liefert ein kleines
Skript nach dem Muster aus dem QnA-Bundle: Frames mit `data-chat-poll`
werden entdeckt, per `frame.reload()` neu geladen, Intervalle kommen aus
`data-chat-poll-interval` und `data-chat-poll-max-interval`. Verhalten:

* Exponentielles Backoff bei Fehlern, gedeckelt auf das Maximum.
* Pause bei `document.hidden`, Neustart bei Sichtbarkeit.
* Kein Reload, während der Frame `busy` ist (laufende Anfrage). Eine
  Fokus-Regel ist nicht nötig: Eingabe (`#chat-compose`) und Verlauf
  (`#chat-messages`) sind getrennte Frames, das Tippen wird von
  eintreffenden Nachrichten nie unterbrochen. `#chat-compose` wird
  niemals gepollt, nur durch die Stream-Antwort nach dem Senden ersetzt.
* Kein Reload für Frames, die `checkVisibility()` als unsichtbar meldet
  (auf dem Smartphone ausgeblendete Liste).
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
`symfony/twig-bundle` verifiziert), genau eine Funktion:

```twig
{{ member_chat_unread_badge({class: 'nav__badge'}) }}
```

Sie rendert einen Turbo-Frame mit dem serverseitig berechneten
Anfangswert und Polling im Badge-Intervall; ohne eingeloggtes Mitglied
rendert sie nichts. Bei `0` ist der Frame vorhanden, aber leer, damit das
Polling ihn weiter aktualisiert. Ohne Turbo auf der Seite bleibt der
Anfangswert stehen. Eine separate Funktion für den nackten Zahlenwert
gibt es nicht.

### 5.6 Darstellung und Templates

* Alle Templates unter `contao/templates/` mit `.twig-root`, damit sie im
  Theme unter `@Contao/member_chat/...` überschreibbar sind.
* Nachrichten: Klartext, `nl2br`, URLs beim Rendern automatisch verlinkt
  (`rel="noopener nofollow"`). Keine Markdown-Verarbeitung.
* CSS im Cascade-Layer `member-chat`, Klassen mit Präfix `member-chat__`.
  Das Bundle liefert eine funktionale Grundgestaltung, keine Theme-Optik.
* Zeitangaben als `<time datetime>` mit relativer Formatierung
  („vor 3 Min.") nur clientseitig, um Cache- und Zeitzonenfragen zu vermeiden.

### 5.7 Anpassung durch das Projekt

Anforderung: Ein Projekt muss das Layout ohne Eingriff ins Bundle und ohne
Kopieren ganzer Templates umbauen können. Drei Ebenen, jede für sich
ausreichend, kombinierbar:

**Ebene 1, nur CSS.** Der Cascade-Layer `member-chat` liegt unter allen
ungelayerten Projekt-Styles. Jede Projekt-Regel gewinnt damit ohne
Spezifitätskampf. Zusätzlich stellt das Bundle Custom Properties am
Wurzelelement `.member-chat` bereit, die das Projekt einfach neu setzt:

| Property | Wirkung | Default |
| --- | --- | --- |
| `--member-chat-offset-top` | Abzug von `100dvh` für den Projekt-Header | `0px` |
| `--member-chat-sidebar-width` | Spaltenbreite der Liste auf Desktop | `20rem` |
| `--member-chat-gap`, `--member-chat-radius` | Abstände, Rundungen | |
| `--member-chat-bubble-own-bg`, `--member-chat-bubble-bg`, `--member-chat-bubble-fg` | Farben der Nachrichten | |
| `--member-chat-accent` | Buttons, Badge, Fokusring | |

Der Breakpoint ist bewusst keine Property, weil Media Queries keine
`var()` akzeptieren. Wer ihn ändern will, setzt im Projekt-CSS die eine
Grid-Regel für `.member-chat--with-conversation` neu; die Bundle-CSS
dokumentiert diese Regel als Ansatzpunkt. Alternativ deaktiviert das
Projekt die Bundle-CSS über die Encore-Entry-Einstellungen in Layout oder
Seite und stylt das Markup komplett selbst.

**Ebene 2, Twig-Blöcke statt Kopieren.** Das CE-Template
`content_element/member_chat.html.twig` ist in benannte Blöcke geteilt.
Ein Projekt legt im Theme ein gleichnamiges Template an, erweitert das
Bundle-Template über die Contao-Template-Hierarchie und überschreibt nur
den Block, den es braucht:

```twig
{% extends "@Contao/content_element/member_chat.html.twig" %}

{% block conversation_header %}
    <header class="my-chat-header">…{{ parent() }}</header>
{% endblock %}
```

Vorgesehene Blöcke: `layout` (Anordnung der Bereiche), `search`,
`conversations`, `conversation_header`, `messages`, `compose`,
`empty_state`, `login_hint`. Jeder Frame-Inhalt liegt zusätzlich in einem
eigenen Partial unter `member_chat/` (`conversation_list_item`,
`message`, `compose_form`, `contact_result`, `unread_badge`), das ebenso
per Block überschreibbar ist. Wer etwa nur die Nachrichtenblase ändern
will, überschreibt `member_chat/message.html.twig`.

**Ebene 3, Attribute und Daten.** Alle Wurzelelemente und Frames nutzen
Contaos `attrs()`-Helper mit `mergeWith(...)`, sodass ein Projekt Klassen
oder Data-Attribute anhängen kann, ohne das Element neu zu schreiben. Die
View-Objekte, die die Templates erhalten, sind Teil der öffentlichen API
und werden im README beschrieben, damit Projekt-Templates auf stabile
Felder zugreifen.

Vertrag, den das Bundle dafür einhält:

* Klassennamen mit Präfix `member-chat__` und die Frame-IDs
  (`chat-search`, `chat-conversations`, `chat-messages`, `chat-compose`)
  sind stabil; das Polling-Skript hängt nur an `data-chat-poll` und den
  Frame-IDs, nicht an Klassen.
* Keine Inline-Styles, keine Layout-Entscheidung im JavaScript. Das Skript
  fügt nur Zustandsattribute an (`data-chat-at-bottom`,
  `data-chat-has-new`), auf die CSS reagiert.
* Zwischen Mobil- und Desktop-Ansicht entscheidet ausschließlich CSS. Ein
  Projekt, das etwa auf Desktop ebenfalls die Einspaltenansicht will,
  löscht eine Grid-Regel.

## 6. Routen

Alle Routen liegen unter `/_member_chat/`, Scope `frontend`, Attribute-Routing
über `config/routes.yaml` (wie im QnA-Bundle über den Manager-Plugin
geladen). Alle verlangen ein eingeloggtes Mitglied, sonst 401 als leeres
Fragment. POST-Routen setzen `_token_check: true` und erwarten
`REQUEST_TOKEN` (Contao-CSRF).

| Methode | Pfad | Name | Zweck |
| --- | --- | --- | --- |
| GET | `/_member_chat/conversations` | `contao_member_chat_conversations` | Frame: Liste |
| GET | `/_member_chat/conversations/{uuid}/messages?after={mid}` | `contao_member_chat_messages` | Frame: Verlauf; mit `after` nur Neues als Turbo-Stream `append`; ohne als volles Frame |
| GET | `/_member_chat/conversations/{uuid}/compose` | `contao_member_chat_compose` | Frame: Formular |
| POST | `/_member_chat/conversations/{uuid}/messages` | `contao_member_chat_message_create` | Nachricht senden → Turbo-Stream |
| POST | `/_member_chat/conversations` | `contao_member_chat_conversation_create` | Konversation finden/anlegen (`member` im Body) → Redirect |
| GET | `/_member_chat/contacts?q=…` | `contao_member_chat_contacts` | Frame: Suchergebnis |
| GET | `/_member_chat/unread` | `contao_member_chat_unread` | Frame: Badge |

`{uuid}` trägt die Anforderung
`[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}`; alles
andere matcht die Route nicht und endet als 404. Der Controller wandelt
über `Uuid::fromString()->toBinary()` und lädt per `ConversationGateway::findByUuid()`.
Die Integer-ID taucht in keiner Route und in keinem Template auf.

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
| `ConversationService` | `openWith(viewerId, memberId)`: prüft `canContact`, findet oder legt an (Transaktion, UUIDv7 erzeugen, Unique-Konflikt → bestehende Zeile), dispatcht `ConversationCreatedEvent`; liefert die `Conversation` samt UUID für den Redirect |
| `MessageService` | `send(conversationId, authorId, body)`: Zugriff per Voter, Sanitizing, Längen- und Rate-Limit, Insert, `lastMessageAt/Id` aktualisieren, `MessageSentEvent` |
| `ReadTracker` | `markRead(conversationId, memberId, upToMessageId)`, dispatcht `MessagesReadEvent` nur bei Änderung |
| `MessageTextSanitizer` | Trim, Normalisierung von Zeilenumbrüchen, Entfernen von Steuerzeichen, Längenprüfung. Kein HTML-Stripping nötig, da nie HTML ausgegeben wird (Twig-Escaping) |
| `PollingPolicy` | Intervalle aus `ChatOptions`, wie im QnA-Bundle |
| `MemberDataEraser` | Anonymisierung bei Mitgliedslöschung (Abschnitt 10) |
| `ConversationVoter` | Attribut `MEMBER_CHAT_VIEW` auf dem geladenen `Conversation`-Objekt: Mitglied ist Teilnehmer (`memberLow`/`memberHigh`). Kein Lookup per ID aus dem Request, die Auflösung UUID → Datensatz passiert vorher im Gateway |

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
| Autorisierung | `ConversationVoter` auf jeder Route mit Konversationsbezug; Kontaktstart nur nach `canContact` |
| Ratbare Adressen | Konversationen sind nach außen nur per UUIDv7 referenziert; Integer-IDs verlassen den Server nicht. Defense in Depth zum Voter |
| CSRF | Contao-`RequestTokenListener` über `_token_check: true`; Token aus `ContaoCsrfTokenManager::getDefaultTokenValue()` im Formular, wird im Stream-Response mitgeliefert |
| Injection / XSS | Nur Klartext gespeichert, Twig-Autoescaping, Autolink erzeugt Attribute selbst und escapet den Link-Text |
| Spam / Missbrauch | Rate-Limiter pro Mitglied, Maximallänge, Mindestlänge 1 nach Trim |
| Enumeration | Fremde oder ungültige Konversations-UUIDs liefern 404; Kontaktsuche liefert nur, was der Provider erlaubt; Mindestlänge der Suche 2 Zeichen, `limit` gedeckelt |
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
    contact:
        avatar_field: null             # z. B. 'avatar', ein Dateifeld an tl_member
        avatar_size: [96, 96, 'crop']  # Bildgrößen-ID oder [Breite, Höhe, Modus]
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
2. **Breakpoint und Desktop-Variante.** Reicht die reine Mobilansicht für
   die erste Version, sodass die zweispaltige Desktop-Darstellung als
   spätere Ergänzung kommt? Vorschlag: beide von Anfang an, weil die
   Desktop-Variante nur CSS plus die Sichtbarkeitsprüfung im Polling ist.
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
| Bild-Auflösung für Avatare | `src/Image/Studio/Studio.php::createFigureBuilder()`; `src/Image/Studio/FigureBuilder.php`: `fromUuid(string)`, `fromFilesModel()`, `setSize()`; `contao/models/FilesModel.php::findByUuid()`; **kein** Avatar-Feld in `contao/dca/tl_member.php` |
| UUID-Erzeugung | `vendor/symfony/uid/Uuid.php` (`symfony/uid` ist Abhängigkeit von `contao/core-bundle` 5.7); Muster für `binary(16)`-Spalte mit Unique-Index in `contao/dca/tl_files.php` (`uuid`), Erzeugung in `src/Filesystem/Dbafs/Dbafs.php` (`Uuid::v1()->toBinary()`, `Uuid::fromBinary()`) |
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
