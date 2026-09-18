# KONZEPT — contao-simple-member-chat

Grobkonzept für eine eigenständige Contao-5.7-Erweiterung: einfache
1:1-Textnachrichten zwischen Frontend-Mitgliedern.

Stand: 2026-09-17, ergänzt um Abschnitt 15 nach Abschluss von Phase 1. Dies ist ein **Konzept**, kein Implementierungsplan.
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

Maßgeblich ist die `AGENTS.md` dieses Repositories (Stand 2026-09-17),
ergänzt um `AGENTS.local.md` für die DDEV-Integrationsumgebung
`contao0507.contao`. Die für das Konzept relevanten Regeln:

* Listener, Callbacks, Hooks, Content-Elemente ausschließlich per PHP-Attribut
  (`#[AsCallback]`, `#[AsHook]`, `#[AsEventListener]`, `#[AsContentElement]`).
* DCA-SQL als Doctrine-Schema-Arrays, keine SQL-Strings.
* Übersetzungen als Symfony-PHP-Ressourcen (`translations/contao_*.de.php`).
* Content-Elemente, keine Frontend-Module. Twig-Templates ausschließlich
  unter `contao/templates/` mit `.twig-root`, keine `.html5`-Templates.
* `src/Model/` nur für Contao-Active-Record-Klassen. Eigene Wertobjekte
  liegen in `src/Domain/` ohne `Model`-Suffix. `src/Domain/` ist in der
  `AGENTS.md` dieses Repositories nicht vorgegeben; das Konzept übernimmt
  die Struktur aus dem QnA-Bundle als Präzedenz.
* Keine trivialen Wrapper-Methoden, die nur delegieren oder einen Ausdruck
  umbenennen. Methoden nur extrahieren, wenn sie Logik kapseln, Duplikate
  entfernen oder einen Erweiterungspunkt bilden. Für das Konzept heißt
  das: `PollingPolicy` aus dem QnA-Bundle wird nicht übernommen, wenn die
  Intervalle nur aus `ChatOptions` durchgereicht werden; Controller und
  Twig-Runtime lesen sie dann direkt aus `ChatOptions`.
* Werkzeuge im Repository, auf Bundle-Stand gebracht am 2026-09-17:
  ECS mit PSR-12, Symfony inkl. Risky, `strict`, `php84Migration`,
  `declare(strict_types=1)` und `final` als Standard (`ecs.php`); PHPStan
  `level: max` mit Symfony-, PHPUnit- und Strict-Rules-Extensions
  (`phpstan.neon`); Rector mit `UP_TO_PHP_84`, `UP_TO_CONTAO_57`,
  Attribut-Sets und den Prepared Sets für Dead Code, Code Quality,
  Type Declarations, Privatization und Early Return (`rector.php`). Das
  kumulative Set `UP_TO_CONTAO_57` ist mit aktuellem Rector nicht
  ausführbar (verweist auf entfernte Symfony-Konstanten); `rector.php`
  listet die enthaltenen Contao-Sets deshalb einzeln bis `CONTAO_53`.
  `contao/contao-rector` ist nur als `dev-main` verfügbar. Pfade
  `config/`, `contao/`, `src/`, `tests/`; DCA- und `config.php`-Dateien
  sind bei PHPStan und Rector ausgenommen, weil sie prozedurale
  Contao-Ressourcen sind. Die Extensions `phpstan/phpstan-phpunit` und
  `phpstan/phpstan-strict-rules` gehören zu den Dev-Abhängigkeiten.
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
| Blockierliste | nein, Zugang regelt allein der Kontakt-Provider; Stummschalten pro Konversation ja |
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
  Contact/ContactResolver.php
  Contact/ContactService.php
  Contact/Viewer.php
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
  Service/{ConversationService,MessageService,ReadTracker,MuteService,
           FrontendMemberProvider,MemberDataEraser,MessageTextSanitizer,
           ConversationUrlGenerator}.php
  Twig/ChatRuntime.php              (#[AsTwigFunction] member_chat_unread_badge)
  View/{ChatViewFactory,TurboResponseFactory,Model/…}.php
contao/
  config/config.php                 (BE_MOD, TL_MODELS falls nötig)
  dca/{tl_chat_conversation,tl_chat_participant,tl_chat_message,tl_content,tl_page}.php
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
| `lastPageId` | int, Default 0 | zuletzt benutzte Chat-Seite, für Deep-Links |
| `muted` | char(1), Default '' | Konversation stummgeschaltet (Contao-Boolean) |

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

### 3.4 Erweiterung `tl_page`

Root-Seiten erhalten ein Feld `memberChatPage` (`int`, Seitenauswahl per
`pageTree`-Picker, Palette `root` und `rootfallback`). Es benennt die
Chat-Seite dieses Roots und dient als Fallback für Deep-Links, wenn ein
Empfänger den Chat noch nie geöffnet hat. Mehrsprachige Projekte pflegen
so pro Sprach-Root eine eigene Chat-Seite, ohne Bundle-Config.

### 3.5 Referentielle Integrität

DCA-Deklarationen, damit `DC_Table` die Kaskade selbst erledigt:

| Tabelle | `config` |
| --- | --- |
| `tl_chat_conversation` | `ctable = ['tl_chat_participant', 'tl_chat_message']` |
| `tl_chat_participant` | `ptable = 'tl_chat_conversation'` |
| `tl_chat_message` | `ptable = 'tl_chat_conversation'` |

Nicht abgedeckt ist das Verschwinden eines Mitglieds (Abschnitt 10).

### 3.6 Ungelesen-Zähler

Kein Zähler-Cache. Der Wert ergibt sich aus
`COUNT(m.id) WHERE m.pid = p.pid AND m.id > p.lastReadMessageId AND m.author <> :me AND p.muted = ''`.
Stummgeschaltete Konversationen zählen weder im Badge noch in der Liste;
die Liste zeigt für sie ein Stumm-Symbol statt der Zahl.
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

    /**
     * Treffer für den Viewer. Ohne Selbstausschluss, Normalisierung des
     * Suchbegriffs und Deckelung des Limits; das erledigt der ContactService.
     *
     * @return list<Contact>
     */
    public function search(Viewer $viewer, string $query, int $limit): array;

    /**
     * Darf asymmetrisch sein: canContact(A, B) und canContact(B, A) dürfen
     * sich unterscheiden. Wird nur beim Anlegen einer Konversation geprüft.
     */
    public function canContact(Viewer $viewer, int $memberId): bool;
}

final readonly class Viewer
{
    /** @param list<int> $groupIds aktive Gruppen des Mitglieds */
    public function __construct(public int $memberId, public array $groupIds) {}
}
```

* `search` liefert Treffer für die Kontaktsuche, bereits gefiltert nach dem,
  was der Viewer sehen darf. Der Provider bestimmt Suchfelder und
  Sortierung.
* `canContact` ist die serverseitige Wahrheit beim **Anlegen** einer
  Konversation. Sie wird immer geprüft, unabhängig davon, wie die
  Mitglieds-ID in den Request kam.
* Kein `find` im Provider. Die Anzeige eines Gesprächspartners in Liste und
  Fenster ist eine andere Aufgabe als die Suche nach erlaubten Kontakten:
  Seit Entscheidung 1 bleiben Konversationen bestehen, auch wenn der
  Provider den Partner nicht mehr liefert. Die Anzeige übernimmt ein
  `ContactResolver` (4.2a), der unabhängig vom Provider arbeitet.

Bestehende Konversationen bleiben lesbar und beschreibbar, auch wenn
`canContact` später `false` liefert. Dann wird nur das Starten neuer
Konversationen verhindert (Abschnitt 13, Entscheidung 1). Asymmetrie ist
gewollt: Ein Trainer darf Mitglieder anschreiben, die ihn selbst nicht
finden; sie können in der bestehenden Konversation antworten.

**`Viewer` statt `int $viewerId`.** Beide mitgelieferten Provider und die
meisten Projekt-Provider brauchen die Gruppen des Suchenden. Der
`ContactService` baut das Objekt einmal pro Request aus der
Mitgliedszeile; Provider laden nichts nach. `Viewer` bleibt bewusst
getrennt von `Contact`: `Contact` beschreibt ein Mitglied für andere und
wandert in Templates, `Viewer` beschreibt den Fragenden mit
Autorisierungsdaten und bleibt serverseitig. Gruppen fremder Mitglieder
gehören nicht in ein Objekt, das Twig erreicht.

**`ContactService` vor dem Provider.** Querschnittslogik liegt an einer
Stelle statt in jedem Provider: Suchbegriff trimmen, Mindestlänge und
Limit aus der Config anwenden, den Viewer selbst aus den Treffern
entfernen, Dubletten nach `memberId` verwerfen, `canContact` für die
eigene ID immer mit `false` beantworten. Controller sprechen nur mit dem
`ContactService`, nie direkt mit einem Provider.

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

### 4.2a `ContactResolver`

Liefert `Contact`-Objekte für **bekannte** Mitglieder, unabhängig vom
Provider: Gesprächspartner in Liste und Fenster, Autor einer Nachricht,
Empfängername im Push. Methoden `resolve(int $memberId): Contact` und
`resolveMany(list<int> $memberIds): array<int, Contact>` für die Liste ohne
N+1. Gelöschte oder unbekannte Mitglieder ergeben einen Platzhalter-Kontakt
(„Gelöschtes Mitglied", `memberId = 0`), damit Templates nie mit `null`
umgehen müssen. Intern `MemberModel`-Finder plus `ContactFactory`.

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
| `member_groups` | Alle aktiven Mitglieder aus konfigurierten Gruppen | `groups: [int]` |
| `shared_groups` | Alle aktiven Mitglieder, die mindestens eine Gruppe mit dem Suchenden teilen | keine |

Beide filtern `disable = 0`, `login = 1` (in Contao 5.7 Boolean-Spalten)
sowie `start`/`stop`, suchen in `firstname`, `lastname`, `username`
(Prefix-Match mit escaptem `LIKE`) und begrenzen auf `limit`. Die
Gruppenzugehörigkeit steht in `tl_member.groups` serialisiert; das
`ContactGateway` filtert Aktivität und Prefix per SQL und die Gruppen per
PHP-Nachfilter nach `StringUtil::deserialize`, das Limit greift danach.
Das ist bei Mitgliederzahlen im Vereins- oder Intranet-Maßstab vertretbar
und im Gateway gekapselt, damit es später austauschbar bleibt.

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
  Tipp auf eine Konversation ist ein Link auf `/chat/<uuid>` mit
  `data-turbo="true"`. Dasselbe gilt für den Zurück-Link und die
  Redirects nach dem Kontaktstart. Mit der Drive-Entry ändert das
  Attribut nichts; mit der `no_drive`-Entry, die lediglich
  `Turbo.session.drive = false` setzt (verifiziert in
  `contao-ux-turbo-encore/assets/js/turbo_no_drive.js`), aktiviert es Drive
  gezielt nur für die Chat-Navigation. Der Wechsel Liste ↔ Konversation
  läuft so in beiden Konfigurationen ohne vollen Reload. Das README
  empfiehlt PWA-Projekten trotzdem die Drive-Entry, damit auch der Weg von
  außen in den Chat ohne Reload läuft.
* **Konversationsansicht** (Item vorhanden): Kopf mit Zurück-Link auf
  `/chat`, dem Namen des Gesprächspartners und einem Stumm-Schalter,
  darunter der Verlauf, unten das Formular. Der Kopf ist statisch
  gerendert; nur der Schalter liegt in einem kleinen Frame
  `#chat-mute`, das nie gepollt und nur durch die Antwort des
  Umschaltens ersetzt wird.
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
* Bei geöffneter Konversation schreibt der Controller die aktuelle
  Seiten-ID (`getPageModel()`) nach `tl_chat_participant.lastPageId`,
  zusammen mit dem Lesestand. Daraus baut der `ConversationUrlGenerator`
  später Deep-Links (Abschnitt 7).

Mobile Details, die das Bundle mitbringt:

| Thema | Lösung |
| --- | --- |
| Höhe | Konversationsansicht füllt `100dvh` abzüglich Kopf des Projekts (Offset als CSS-Custom-Property `--member-chat-offset-top`, Default 0); der Verlauf scrollt intern, nicht die Seite |
| Virtuelle Tastatur | `#chat-compose` mit `position: sticky; bottom: 0`. Android/Chrome: Viewport-Meta des Projekts setzt `interactive-widget=resizes-content` (README, Projektvoraussetzung). iOS/Safari verkleinert den Layout-Viewport nicht; deshalb setzt das Bundle-Skript bei `visualViewport.resize` die Höhe des Wurzelelements auf `visualViewport.height` minus Offset und scrollt den Verlauf ans Ende, wenn er dort war. Nur aktiv, wenn `window.visualViewport` existiert und die Mobilansicht aktiv ist, damit Desktop mit Bildschirmtastatur unberührt bleibt. Test auf echten iOS-Geräten, auch im Standalone-Modus |
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

* Frame-Registrierung über `turbo:load` und einen `MutationObserver`,
  Aufräumen aller Timer bei `turbo:before-cache`, damit das Skript unter
  Turbo Drive über Seitenwechsel hinweg korrekt arbeitet (Muster aus dem
  QnA-Bundle).
* Exponentielles Backoff bei Fehlern, gedeckelt auf das Maximum.
* Pause bei `document.hidden`, Neustart bei Sichtbarkeit.
* Kein Reload, während der Frame `busy` ist (laufende Anfrage). Eine
  Fokus-Regel ist nicht nötig: Eingabe (`#chat-compose`) und Verlauf
  (`#chat-messages`) sind getrennte Frames, das Tippen wird von
  eintreffenden Nachrichten nie unterbrochen. `#chat-compose` wird
  niemals gepollt, nur durch die Stream-Antwort nach dem Senden ersetzt.
* Kein Reload für Frames, die `checkVisibility()` als unsichtbar meldet
  (auf dem Smartphone ausgeblendete Liste).
* Zwei Poll-Modi pro Frame: Voll-Reload über `frame.reload()` und
  inkrementell über einen `fetch` auf die Frame-Adresse mit `after` bzw.
  `since`, dessen Turbo-Stream-Antwort über `Turbo.renderStreamMessage()`
  angewendet wird. Nach dem Nachladen älterer Einträge (5.3a) bleibt ein
  Frame dauerhaft im inkrementellen Modus.
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

### 5.3a Nachladen älterer Einträge

**Nachrichten.** Der Verlauf startet mit den letzten `page_size`
Nachrichten. Oben steht ein Button „Ältere Nachrichten" mit
`?before=<älteste geladene ID>`. Die Nachrichten-Route antwortet auf
`before` mit einem Turbo-Stream, der die älteren Nachrichten per `prepend`
vor die vorhandenen setzt und den Button mit neuem `before`-Wert ersetzt
oder entfernt, wenn nichts mehr da ist. Ein `IntersectionObserver` auf dem
Button löst das Nachladen automatisch aus, sobald er sichtbar wird; ein
Data-Attribut am Frame schaltet das ab, dann bleibt der Klick. Während
einer laufenden Anfrage ist der Button deaktiviert, damit schnelles
Wischen nicht mehrere Ladevorgänge auslöst. Die Scroll-Position bleibt
über `overflow-anchor` erhalten.

Konsequenz für das Polling: Nach einem Nachladen darf der Frame nicht mehr
voll neu geladen werden, sonst wären die älteren Nachrichten wieder weg.
Das Skript merkt sich am Frame `data-chat-loaded-before` und pollt danach
ausschließlich inkrementell mit `after`. Ein Seitenwechsel setzt zurück.

**Konversationsliste.** Gleiche Mechanik, kein hartes Limit: Die Liste
zeigt `page_size` Konversationen, sortiert nach `lastMessageAt`
absteigend, und darunter einen Button „Weitere Konversationen" mit
`?before=<lastMessageAt der letzten>` plus deren ID als Tiebreaker. Antwort
per Turbo-Stream `append`. Das Polling der Liste lädt weiterhin nur die
erste Seite voll neu; nachgeladene Einträge bleiben stehen, weil der
Listen-Frame nach einem Nachladen wie der Nachrichten-Frame in den
inkrementellen Modus wechselt. Da neue Aktivität eine Konversation nach
oben schiebt, liefert der inkrementelle Poll der Liste alle Einträge,
deren `changedAt` **größer oder gleich** dem zuletzt bekannten Stand ist,
ohne Seitenbegrenzung (Sekundenauflösung der Zeitstempel, ein striktes
`>` könnte Aktivität derselben Sekunde verlieren). `changedAt` ist
`GREATEST(c.lastMessageAt, p.tstamp)` der eigenen Teilnehmerzeile; damit
schlagen auch Lesestand- und Stumm-Änderungen aus einem anderen Tab in
der Liste durch (Entscheidung 15). Das Skript upsertet per UUID: alten
Eintrag derselben UUID entfernen, neuen an der Position einfügen, die
`lastMessageAt` vorgibt.

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
Anfangswert stehen.

Bewusst nicht enthalten: eine Funktion für den nackten Zahlenwert
(`member_chat_unread_count`). Wer die Zahl als Text braucht, etwa im
Menüpunkt oder im Seitentitel, kann sie später ohne Bruch als zweite
Funktion derselben Runtime ergänzen; der Wert liegt dort ohnehin vor.

### 5.6 Darstellung und Templates

* Alle Templates unter `contao/templates/` mit `.twig-root`, damit sie im
  Theme unter `@Contao/member_chat/...` überschreibbar sind.
* Nachrichten: Klartext, `nl2br`, URLs beim Rendern automatisch verlinkt
  (`rel="noopener nofollow"`). Keine Markdown-Verarbeitung.
* CSS im Cascade-Layer `member-chat`, Klassen mit Präfix `member-chat__`.
  Das Bundle liefert eine funktionale Grundgestaltung, keine Theme-Optik.
* Zeitangaben als `<time datetime>`: Server-Inhalt im Seitenformat,
  clientseitig ersetzt durch relative Form in Gerätezeitzone
  (Abschnitt 13, Entscheidung 5). Tagestrenner serverseitig.

### 5.6a Barrierefreiheit

| Stelle | Maßnahme |
| --- | --- |
| Eintreffende Nachrichten | Verlauf als `role="log"` mit `aria-live="polite"`; nur Hinzugefügtes wird angekündigt. Während des Nachladens älterer Nachrichten (`prepend`) setzt das Skript `aria-live="off"` und danach zurück |
| Fokus nach dem Senden | Die Stream-Antwort ersetzt `#chat-compose`; das Skript setzt den Fokus in `turbo:before-stream-render` zurück ins neue Textfeld (Fokus-Merkliste nach dem Muster des QnA-Skripts). Bei Fehlerantwort (422/429) bleibt der Fokus im Textfeld, die Fehlermeldung ist per `aria-describedby` verknüpft |
| Ungelesen-Badge | `aria-label` mit Text („3 ungelesene Nachrichten"), Frame `aria-live="off"`, damit das Polling nicht vorliest |
| Bedienelemente | Buttons statt klickbarer `div`s; sichtbarer Fokusring über `--member-chat-accent`; `<label>` für Textfeld und Suchfeld, visuell versteckt; Stumm-Schalter als `<button aria-pressed>` |
| Tagestrenner | als `<h3>` oder `role="separator"` mit Text, nicht nur visuell |
| Farben | Standardpalette mit Kontrast nach WCAG AA; Projekte, die Properties überschreiben, tragen die Verantwortung selbst (README) |
| Touch und Tastatur | Touch-Ziele mindestens 44 × 44 px (5.1); alle Aktionen per Tastatur erreichbar; „Ältere laden" auch als Button, nicht nur per Scrollen |
| Zeitangaben | `<time datetime>` behält den vollständigen Zeitpunkt maschinenlesbar, die relative Form ist nur der sichtbare Text |

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
| GET | `/_member_chat/conversations?since={ts}&before={ts},{id}` | `contao_member_chat_conversations` | Frame: Liste; `since` liefert geänderte Einträge als Turbo-Stream, `before` ältere per `append`, ohne beides volles Frame |
| GET | `/_member_chat/conversations/{uuid}/messages?after={mid}&before={mid}` | `contao_member_chat_messages` | Frame: Verlauf; `after` nur Neues als Turbo-Stream `append`, `before` Ältere als `prepend`, ohne beides volles Frame |
| GET | `/_member_chat/conversations/{uuid}/compose` | `contao_member_chat_compose` | Frame: Formular |
| POST | `/_member_chat/conversations/{uuid}/messages` | `contao_member_chat_message_create` | Nachricht senden → Turbo-Stream |
| POST | `/_member_chat/conversations` | `contao_member_chat_conversation_create` | Konversation finden/anlegen (`member` im Body) → Redirect |
| POST | `/_member_chat/conversations/{uuid}/mute` | `contao_member_chat_mute` | Stummschalten umschalten (`muted` 0/1 im Body) → Turbo-Stream ersetzt den Schalter |
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
| `ConversationService` | `openWith(int $initiatorId, int $memberId)`: prüft `canContact` über `ContactPermissionInterface` (Phase 2 liefert den Adapter zum `ContactService`, der den `Viewer` intern auflöst), findet oder legt an (Transaktion, UUIDv7 erzeugen, Unique-Konflikt → bestehende Zeile), dispatcht `ConversationCreatedEvent`; liefert die `Conversation` samt UUID für den Redirect |
| `MessageService` | `send(conversationId, authorId, body)`: Zugriff per Voter, Sanitizing, Längen- und Rate-Limit, Insert, `lastMessageAt/Id` aktualisieren, `MessageSentEvent` |
| `ReadTracker` | `markRead(conversationId, memberId, upToMessageId)`, dispatcht `MessagesReadEvent` nur bei Änderung |
| `MuteService` | `setMuted(conversationId, memberId, bool)`; wirkt auf Zähler, Liste und Push. Nachrichten kommen weiterhin an, der Absender erfährt nichts |
| `MessageTextSanitizer` | Trim, Normalisierung von Zeilenumbrüchen, Entfernen von Steuerzeichen, Längenprüfung. Kein HTML-Stripping nötig, da nie HTML ausgegeben wird (Twig-Escaping) |
| `MemberDataEraser` | Anonymisierung bei Mitgliedslöschung (Abschnitt 10) |
| `ConversationUrlGenerator` | `forConversation(Conversation, int $memberId): ?string`, `listPage(int $memberId): ?string`. Reihenfolge: `lastPageId` des Teilnehmers, sonst `memberChatPage` der Root-Seite (bei mehreren Roots die des ersten veröffentlichten Roots mit gesetztem Feld), sonst `null`. URL-Erzeugung über `contao.routing.content_url_generator` mit der UUID als Parameter. Einzige Stelle für Konversations-URLs: Push, Badge, Redirect nach Kontaktstart, Listen-Links |
| `ConversationVoter` | Attribut `MEMBER_CHAT_VIEW` auf dem geladenen `Conversation`-Objekt: Mitglied ist Teilnehmer (`memberLow`/`memberHigh`). Kein Lookup per ID aus dem Request, die Auflösung UUID → Datensatz passiert vorher im Gateway |

Rate-Limit: `symfony/rate-limiter` ist in Contao 5.7 vorhanden. Das Bundle
übernimmt das Muster des Core für den Suchindex-Limiter
(`ContaoCoreExtension`, Abschnitt 14): In `loadExtension` wird eine
`RateLimiterFactory`-Definition registriert, Policy `sliding_window`,
Limit und Intervall aus der Config, Speicher `CacheStorage` mit Referenz
auf `cache.app`. Damit läuft der Limiter prozessübergreifend ohne
Host-Konfiguration. Optional verweist `message.rate_limiter: <name>` auf
einen projektweit unter `framework.rate_limiter` definierten Limiter, dann
wird `limiter.<name>` referenziert und die eigene Definition entfällt.
Schlüssel ist die Mitglieds-ID. Der Limiter greift beim Senden und beim
Kontaktstart, nicht bei Polls: Polls sind Lesezugriffe in einem Takt,
den das Bundle selbst vorgibt, ein Limit dort würde bei zwei offenen Tabs
sofort greifen.

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

### 8.2 Optional PWA push (revised in Phase 5)

The integration lives in this bundle under `src/Integration/Pwa/`, as decided
in section 13, decision 3. PWA is suggested for production and required only
for development tests of its real classes. No bridge package is created.

- Conditional service registration requires the PWA sender, notification and
  subscriber/configuration classes. Without them, no integration code loads.
- An attributed `MessageSentEvent` listener enqueues only message/recipient IDs.
  `SendChatPushMessage` implements Contao's `LowPriorityMessageInterface`,
  routed to `contao_prio_low` by the Managed Edition. This interface is deprecated
  in 5.7; Contao 6 will require migration to the message routing attribute.
- The handler reloads the message/conversation and participant rows. Missing,
  muted and recently active recipients are skipped. The event recipient list
  never expands. Subscriber queries filter both member and configuration ID.
- Configuration defaults: `push.enabled: false`, `configuration: 0` (no target),
  `active_recipient_grace: 60` seconds, `body_length: 0` UTF-8 characters.
  A positive configuration ID selects one push-enabled PWA configuration.
  Excerpts may contain 0–500 characters. Activity throttling can make
  `lastReadAt` lag actual activity; see README for the suppression limits.
- The notification title is the sender's display name. The PWA notification
  getter protocol supports `data.clickJumpTo`; no backend notification model
  is needed. Per-recipient absolute URLs use the existing URL generator and
  publication/fallback rules. Missing destinations do not prevent sending.
- An empty subscriber array MUST NOT reach the PWA sender: it interprets this
  as broadcast. Sender errors are logged and delivery continues for later
  recipients. PWA `sendWithLog()` returning true does not prove device delivery.
- PWA only suggests `minishlink/web-push`; deployments need it, VAPID credentials,
  member-bound subscriptions and a working service worker/queue consumer.

The original external-bridge wording and uncertainty about click targets are
superseded by this verified implementation. See `.docs/build/DECISIONS.md` and
`.docs/build/reports/phase-5-push.md` for source evidence and acceptance limits.

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
        rate_limit: 30                 # Nachrichten bzw. Kontaktstarts pro Minute und Mitglied
        rate_limiter: null             # optional: Name eines Limiters aus framework.rate_limiter
    list:
        page_size: 50                  # Nachrichten bzw. Konversationen pro Ladung und Nachladeschritt
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

## 12b. Teststrategie

| Ebene | Umfang | Werkzeug |
| --- | --- | --- |
| Unit, ohne Datenbank | Kontakt-Provider, `ContactService`, `ContactFactory`, `MessageTextSanitizer`, `ConversationVoter`, `ConversationUrlGenerator`, Ableitung `memberLow`/`memberHigh`; Gateways gemockt | PHPUnit 12 |
| Gateway, echte Datenbank | Unique-Konflikt beim gleichzeitigen Anlegen, Ungelesen-Zähler mit `muted`, `before`/`after`-Fenster für Nachrichten und Liste, Anonymisierung, Kaskade | `contao/test-case` mit Testverbindung, etwa ein Dutzend Fälle |
| Integration | Container-Build, Routen, Migration, Backend-Modul, manueller Durchlauf; Testmitglieder in zwei Gruppen, damit beide Provider prüfbar sind | DDEV-Projekt per Symlink, Zugang in `AGENTS.local.md` wie im QnA-Bundle |
| Browser, manuell | Checkliste im Repository: zwei Browser nebeneinander, Senden und Poll, Tab im Hintergrund, Nachladen nach oben, leerer Text, Rate-Limit, Stummschalten, Fokus nach Senden, iOS-Gerät echt und im Standalone-Modus | Checkliste `.docs/BROWSER_CHECKLIST.md` |
| Browser, automatisiert | Späterer Ausbau, nicht in der ersten Version | Playwright gegen das DDEV-Projekt |

Dev-Abhängigkeiten: `contao/core-bundle:^5.7`, `contao/test-case`,
`phpunit/phpunit:^12`, dazu die im Repository bereits konfigurierten
Werkzeuge `symplify/easy-coding-standard`, `phpstan/phpstan` mit
`phpstan/phpstan-symfony`, `phpstan/phpstan-phpunit` und
`phpstan/phpstan-strict-rules` sowie `rector/rector` mit
`contao/contao-rector`; `phpunit.xml.dist` ist zu ergänzen.

## 12a. Todo

* ~~Das Interface noch einmal besprechen.~~ Erledigt 2026-09-17, siehe
  Entscheidung 14.

## 13. Entscheidungen

Alle im Gespräch geklärten Punkte, chronologisch. Jeder Eintrag nennt
den Abschnitt, in dem die Entscheidung eingearbeitet ist.

1. **Weiterschreiben nach Provider-Entzug.** Entschieden 2026-09-16: Lesen
   und Schreiben bleiben erlaubt, `canContact` wird nur beim Anlegen einer
   Konversation geprüft. Der Provider steuert, wer sich finden kann, nicht
   wer sich schreiben darf. `MessageService::send` bleibt so aufgebaut, dass
   eine spätere Prüfung pro Nachricht ohne Interface-Änderung ergänzt werden
   kann.
2. **Desktop-Variante.** Entschieden 2026-09-16: Mobil- und Desktop-Layout
   von Anfang an, wie in 5.1 beschrieben. Der Server rendert bei geöffneter
   Konversation beide Bereiche, CSS entscheidet über die Anordnung, das
   Polling überspringt unsichtbare Frames.
3. **Push-Listener-Ort.** Entschieden 2026-09-16, **revidiert 2026-09-18**:
   Der Listener liegt direkt im Chat-Bundle als optionale Integration mit
   loser Abhängigkeit. `heimrichhannot/contao-pwa-bundle` steht nur unter
   `suggest`; die Integrationsdienste werden ausschließlich registriert,
   wenn die Klassen des PWA-Bundles vorhanden sind. Kein eigenes
   Brücken-Paket. Ursprüngliche Fassung: eigenes Brücken-Paket
   (Arbeitstitel `heimrichhannot/contao-member-chat-pwa`), das beide Bundles
   als Abhängigkeit hat. Chat- und PWA-Bundle wissen nichts voneinander. Das
   Chat-Bundle stellt dafür als stabile API bereit: die Event-Klassen,
   `MessageGateway::find(int)`, `Conversation` mit `uuid` sowie die
   Stummschalt- und Lesestand-Informationen aus `tl_chat_participant`.
   Aufbau des Listeners wie in 8.2 beschrieben.
4. **Ziel-URL im Push, mehrere Roots und Sprachen.** Entschieden 2026-09-16:
   Der Chat-Controller merkt sich beim Laden die aktuelle Seite in
   `tl_chat_participant.lastPageId`. Fallback ist ein Seitenauswahl-Feld
   `memberChatPage` an der Root-Seite (`tl_page`, Typ `root`), das
   Redakteure pflegen. Ein `ConversationUrlGenerator` im Chat-Bundle ist die
   einzige Stelle, die Konversations-URLs baut (Push, Badge, Redirects); er
   nutzt `contao.routing.content_url_generator`. Keine `page_id` in der
   Bundle-Config.
5. **Zeitformat im Verlauf.** Entschieden 2026-09-16: beides. Der Server
   rendert `<time datetime="…">` mit dem Seitenformat (`datimFormat`) als
   Inhalt, JavaScript ersetzt den Inhalt bei jedem Laden und Frame-Reload
   durch eine relative Angabe in Gerätezeitzone über
   `Intl.RelativeTimeFormat` und `Intl.DateTimeFormat`, Sprache aus dem
   `lang`-Attribut. Tagestrenner („Heute", „Gestern", Wochentag) rendert der
   Server. Keine Bibliothek.
6. **Nachladen älterer Nachrichten und Konversationen.** Entschieden
   2026-09-16: Button mit `IntersectionObserver` für beide Listen, kein
   hartes Limit, Schrittweite `page_size`. Details in 5.3a.
7. **Lesebestätigung.** Entschieden 2026-09-16: nicht anzeigen. Der
   Lesestand bleibt intern (Ungelesen-Zähler, Push-Unterdrückung). Das
   Nachrichten-Partial enthält einen leeren Block `message_status`, und das
   View-Objekt der Nachricht trägt `readByPartner: bool`, damit ein Projekt-
   Template eine Markierung selbst ergänzen kann.
8. **Stummschalten.** Entschieden 2026-09-16: aufnehmen, mit Schalter im
   Kopf der Konversation. Spalte `muted` in `tl_chat_participant`, Route
   `contao_member_chat_mute`, Auswertung in Ungelesen-Zähler, Liste und
   Push-Listener. Nachrichten kommen weiterhin an, der Absender erfährt
   nichts. Kein Blockieren.
9. **iOS-Tastatur.** Entschieden 2026-09-16: `visualViewport`-Handler im
   Bundle-Skript plus Viewport-Meta für Android als Projektvoraussetzung.
   Details in der Tabelle „Mobile Details" in 5.1.
10. **Turbo Drive.** Entschieden 2026-09-16: Chat-Navigationslinks tragen
   `data-turbo="true"`, README empfiehlt PWA-Projekten die Drive-Entry.
   Skript-Lebenszyklus über `turbo:load` und `turbo:before-cache`.
11. **Barrierefreiheit.** Entschieden 2026-09-16: vollständig aufnehmen,
   Details in 5.6a. Fokus-Handling gehört ins Skript, Rollen und Labels in
   die Templates, beides von Anfang an.
12. **Teststrategie.** Entschieden 2026-09-16: Unit, Gateway und Integration
   als Pflicht, Browser-Checkliste manuell, Playwright als späterer Ausbau.
   Details in 12b.
13. **Rate-Limiter-Speicher.** Entschieden 2026-09-17: eigene
   `RateLimiterFactory` mit `CacheStorage` auf `cache.app` nach dem Core-
   Muster, `sliding_window`, optional Verweis auf einen projektweiten
   Limiter. Gilt für Senden und Kontaktstart, nicht für Polls. Details in
   Abschnitt 7.
14. **Kontakt-Provider-Interface.** Entschieden 2026-09-17: `find` aus dem
   Provider gestrichen, Anzeige über `ContactResolver`; `Viewer`-Wertobjekt
   (ID und Gruppen) statt `int $viewerId`, bewusst getrennt von `Contact`;
   `ContactService` übernimmt Selbstausschluss, Normalisierung, Limit und
   Dubletten zentral; Asymmetrie von `canContact` ist erlaubt und
   dokumentiert; `getAlias()` bleibt statisch. Details in 4.1 und 4.2a.
15. **Listen-Poll und Fremdänderungen.** Entschieden 2026-09-17 für
   Phase 3a: `since` vergleicht gegen `GREATEST(c.lastMessageAt, p.tstamp)`
   der eigenen Teilnehmerzeile (`changedAt` im `ConversationListItem`),
   damit Lesestand- und Stumm-Änderungen aus anderen Tabs die Liste
   aktualisieren. Kein periodischer Voll-Reload. Details in 5.3a.

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
| Rate-Limiter | `src/DependencyInjection/ContaoCoreExtension.php` (Suchindex-Limiter): `RateLimiterFactory`-Definition mit `['id', 'policy', 'limit', 'interval']` und `new Definition(CacheStorage::class, [new Reference('cache.app')])`, alternativ Referenz auf `limiter.<name>`; `vendor/symfony/rate-limiter/Storage/{CacheStorage,InMemoryStorage}.php` |
| Bild-Auflösung für Avatare | `src/Image/Studio/Studio.php::createFigureBuilder()`; `src/Image/Studio/FigureBuilder.php`: `fromUuid(string)`, `fromFilesModel()`, `setSize()`; `contao/models/FilesModel.php::findByUuid()`; **kein** Avatar-Feld in `contao/dca/tl_member.php` |
| UUID-Erzeugung | `vendor/symfony/uid/Uuid.php` (`symfony/uid` ist Abhängigkeit von `contao/core-bundle` 5.7); Muster für `binary(16)`-Spalte mit Unique-Index in `contao/dca/tl_files.php` (`uuid`), Erzeugung in `src/Filesystem/Dbafs/Dbafs.php` (`Uuid::v1()->toBinary()`, `Uuid::fromBinary()`) |
| URL-Erzeugung für Seiten | `src/Routing/ContentUrlGenerator.php::generate(object $content, array $parameters = [], int $referenceType)`; Service `contao.routing.content_url_generator` in `config/services.yaml`; `AbstractFragmentController::getPageModel()` liefert die Seite des Content-Elements |
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

**Verified in Phase 5:** The host `contao/manager-bundle` skeleton defines
`contao_prio_low/normal/high` and routes the matching message interfaces to
Doctrine transports. See the Phase 5 source evidence in `DECISIONS.md`.

---

## 15. Erkenntnisse aus Phase 1

Stand nach Abschluss von Phase 1 (2026-09-17, drei Commits, Bericht unter
`.docs/build/reports/phase-1-foundation.md`, API-Belege in
`.docs/build/DECISIONS.md`). Verifiziert wurde gegen `contao/core-bundle`
5.7.13. Punkte, die das Konzept präzisieren oder für spätere Phasen
Entscheidungen verlangen:

| Thema | Stand nach Phase 1 | Folge |
| --- | --- | --- |
| Berechtigungsnaht | `ConversationService::openWith(int, int)` fragt `ContactPermissionInterface::canContact(int, int)`; Default `DenyContactPermission` verweigert jeden Kontaktstart | Phase 2 ersetzt den Alias durch einen Adapter auf den `ContactService`, der den `Viewer` auflöst. `ConversationService` bleibt unverändert |
| Rate-Limiter beim Kontaktstart | Der Limiter wird vor der Prüfung auf eine bestehende Konversation verbraucht | Phase 2 verschiebt den Verbrauch hinter die Existenzprüfung: Das Öffnen einer bestehenden Konversation ist kein Kontaktstart |
| `since` in der Liste | inklusiv, ohne Limit, nur `lastMessageAt`-basiert | Phase 3 upsertet per UUID; für Lesestand- und Stumm-Änderungen aus anderen Tabs braucht die Liste zusätzlich einen periodischen Voll-Reload der ersten Seite oder ein `since` auf `GREATEST(c.lastMessageAt, p.tstamp)`. Entscheidung in Phase 3 |
| Nachrichtenfenster `after` | liefert die früheste ungesehene Seite chronologisch, nicht die neueste | Phase 3 pollt so lange mit dem letzten gelieferten `after`, bis weniger als `page_size` zurückkommen |
| Transaktionen | Jeder Service besitzt die Top-Level-Transaktion; verschachtelte Aufrufe werfen `LogicException` | Controller und Listener dürfen Services nie innerhalb einer eigenen Transaktion aufrufen |
| Events | Nur die drei spezifizierten Events; Mute und Anonymisierung dispatchen nichts. Event-Klassen sind `final` mit `readonly`-Payload, nicht selbst `readonly` (Symfony-`Event` erlaubt das nicht) | Konzepttext „jeder Schreibvorgang endet mit einem Event“ gilt für Anlegen, Senden, Lesen |
| Gelöschte Teilnehmer | Teilnehmerzeile entfernt, Autor auf `0`, Paar in `memberLow`/`memberHigh` bleibt. Senden in eine Konversation mit nur einem Teilnehmer liefert 403 `read_only` | Anzeige des Partners kommt aus der Teilnehmertabelle; `ContactResolver` liefert für `0` den Platzhalter |
| Lesestand | `markRead` schreibt `lastReadAt` und `lastPageId` auch dann, wenn sich `lastReadMessageId` nicht ändert; das Event kommt nur bei Änderung | Push-Unterdrückung über `lastReadAt` funktioniert auch bei leeren Polls |
| Schema | `text` wird zu `LONGTEXT`; Längenprüfung liegt im Sanitizer. `tl_page.memberChatPage` braucht `foreignKey: tl_page.title`, sonst lehnt der `DcaExtractor` die Relation ab | Keine Konzeptänderung |
| Übersetzungen | Symfony-PHP-Ressourcen brauchen den Tabellenpräfix im Schlüssel (`tl_page.memberChatPage.0`), auch in tabellenspezifischen Domains | Muster für alle weiteren Übersetzungen |
| Gateways | `final` hinter `*GatewayInterface` für Mocks | Provider und Resolver in Phase 2 folgen demselben Muster |
| Integrationstests | eigene Datenbank `member_chat_test`, nur die drei Chat-Tabellen werden aufgebaut | Phase 2 muss eine minimale `tl_member` für Provider-Tests ergänzen |
| Hilfsskript | `.docs/build/verify-host.php` prüft Paletten und Übersetzungen im Host | In Phase 4 nach `tools/` verschieben oder durch einen Integrationstest ersetzen |

### Erkenntnisse aus Phase 2

Abgeschlossen 2026-09-17, drei Commits, Bericht unter
`.docs/build/reports/phase-2-contacts.md`.

| Thema | Stand nach Phase 2 | Folge |
| --- | --- | --- |
| Öffentliche Kontakt-API | `ContactService::search(string, ?int)` und `canContact(int)` holen die Identität selbst; `ContactService::viewer()` baut den `Viewer` einmal pro Hauptrequest (`WeakMap` auf dem Request) | Controller übergeben nie eine fremde Mitglieds-ID als Viewer |
| Anzeige | `ContactResolver::resolveMany()` liefert eine Map je angefragter ID, Platzhalter mit `memberId = 0` und Übersetzung `MSC.member_chat.deleted_member`; inaktive Mitglieder werden für bestehende Verläufe weiterhin aufgelöst | Templates erhalten nie `null` |
| Avatare | Einzeln über `fromUuid()->buildIfResourceExists()`, im Batch über eine `FilesModel::findMultipleByUuids()`-Abfrage und `fromFilesModel()`; URL aus `Figure::getImage()->getImageSrc()` | Batch-Variante liefert keinen `subtitle`; Provider mit Untertitel bauen `Contact` selbst oder ergänzen den Wert nachträglich |
| Avatar-Spalte | `ContactGateway::displayColumns()` prüft bei konfiguriertem `avatar_field` per Schema-Introspektion, ob die Spalte existiert, und zwar bei jeder Abfrage | Phase 3a memoisiert das Ergebnis pro Prozess; Introspektion pro Request ist zu teuer |
| Mitgliederfilter | `disable = 0`, `login = 1`, `start`/`stop` mit Integer-Parametern; aktive Viewer-Gruppen gegen `tl_member_group` mit Minutenauflösung | Konzeptabschnitt 4.4 korrigiert |
| Registry | Compiler-Pass prüft unbekannte und doppelte Aliase beim Container-Build, wie im Konzept gefordert | Keine Abweichung |
| Rate-Limiter | Existenzprüfung liegt jetzt vor dem Token-Verbrauch | Erledigt |
| Testdatenbank | Fixtures für `tl_member` und `tl_member_group` in `tests/DatabaseTestCase.php` | Phase 3 nutzt dieselbe Basis für Controller-nahe Tests |
| Framework-Adapter | `Adapter::__call('findMultipleByUuids', …)` explizit statt magischem Aufruf, wegen PHPStan-Strict-Regel zu dynamischen statischen Aufrufen | Kandidat für eine Aufräumrunde, sobald ein sauberer Weg gefunden ist |

### Erkenntnisse aus Phase 3a

Abgeschlossen 2026-09-17, drei Codex-Commits plus ein Review-Fix, Bericht
unter `.docs/build/reports/phase-3a-frontend-core.md`. Zusätzlich zur
HTTP-Verifikation wurde die Demo-Seite im Browser geprüft (Desktop und
375 px, Senden, Polling mit einer zweiten Sitzung, Zurück-Navigation,
Kontaktsuche).

| Thema | Befund | Folge |
| --- | --- | --- |
| Frame-Antworten mit `src` | Turbo lehnt eine Frame-Antwort ab, deren `<turbo-frame>` ein `src` gleich der Anfrage-URL trägt („source URL which references itself"), und leert das Frame. Die Partials setzten `src` auch in der Antwort; der Nachrichtenverlauf war im Browser leer | Behoben im Review-Fix: `src` und `loading` nur beim eingebetteten Rendern (`embedded` im Kontext), Regressionsprüfung im Verifikationsskript. Regel für alle weiteren Frames |
| Sprache der Fragmente | Frame- und Stream-Antworten rendern in der Request-Sprache (Accept-Language), nicht in der Seitensprache; nach dem Senden wechselten Beschriftungen von Englisch auf Deutsch | Phase 3b: Locale aus der übergebenen Seite setzen (`$page->language`), bevor gerendert wird |
| Teilnehmer-`tstamp` bei jedem Poll | Jeder Nachrichten-Poll schreibt `lastReadAt` und `tstamp`, auch ohne neue Nachricht. Da `changedAt` auf `tstamp` basiert, meldet der Listen-Poll die offene Konversation bei jedem Durchlauf als geändert (beobachtet: `since` stieg bei jedem 15-s-Poll) | Phase 3b: `tstamp` nur bei tatsächlicher Änderung von Lesestand oder Stummschaltung; Aktivitätsupdate ohne Änderung drosseln |
| Enter zum Senden | Erkennung über `pointer: coarse` **und** `maxTouchPoints === 0`; Geräte mit Touchscreen und Tastatur (Laptops) senden mit Enter nicht | Phase 3b: nur `pointer: coarse` auswerten |
| Höhe der Konversationsansicht | `100dvh` minus statischem Offset; auf der Demo-Seite beginnt der Chat unter Header und Login-Modul, der Block ragt aus dem Viewport, die Seite scrollt zusätzlich zum Verlauf | Phase 3b: Höhe dynamisch aus `visualViewport.height` minus tatsächlicher Oberkante des Chat-Elements setzen; der statische Offset bleibt als Fallback ohne JavaScript |
| Eingehende Nachrichten | erscheinen per Polling innerhalb des Intervalls, scrollen aber nicht nach; Liste aktualisiert Auszug korrekt | Phase 3b wie geplant: nachscrollen, wenn der Nutzer am Ende war, sonst Hinweis |
| Kontaktsuche | Debounce und Anfrage funktionieren; Prefix-Match gilt für das ganze Feld, „Car" findet „Chat Carol" nicht; leere Treffer zeigen keinen Hinweis | Phase 3b: Wort-Prefix (`LIKE 'q%' OR LIKE '% q%'`), Text für „keine Treffer" |
| Leerer Zustand mobil | „Konversation wählen" erscheint auf dem Smartphone unter der Liste | Phase 3b: nur ab Tablet-Breite zeigen |
| Turbo-Cache-Meta | `HtmlHeadBag` funktioniert in Legacy- und Twig-Layout, keine `TL_HEAD`-Lösung nötig | Konzept 5.1 bestätigt, keine Änderung |
| Demo-Umgebung | Das Browser-Panel der Desktop-App erlaubt nur `localhost` und `127.0.0.1`; die Root-Seite des DDEV-Demos wurde von `contao0507.contao.hhdev` auf leere Domain umgestellt, damit `https://127.0.0.1:<port>` antwortet | Reversibel; für dauerhafte Nutzung `host_https_port` im DDEV-Projekt fixieren |

### Erkenntnisse aus Phase 3b

Abgeschlossen 2026-09-17, vier Codex-Commits, Bericht unter
`.docs/build/reports/phase-3b-frontend-refinements.md`. Anschließend im
Browser geprüft (Desktop und 375 px, angemeldet als Demo-Mitglied Alice,
Gegenseite per HTTP-Skript). Die fünf Fixes aus Phase 3a sind bestätigt.

**Im Browser bestätigt:** Senden mit Enter und Shift+Enter, Fokus zurück im
Eingabefeld, Formular geleert, ans Ende gescrollt; relative Zeitangaben mit
vollständigem `datetime` und Tooltip; Tagestrenner mit `role="separator"`,
je Tag genau einer sichtbar, der doppelte an der Nachladegrenze wird
ausgeblendet; Nachladen älterer Nachrichten per Klick **und** automatisch
per `IntersectionObserver`, die sichtbare Nachricht bleibt pixelgenau
stehen, `aria-live` kehrt nach dem Einfügen auf `polite` zurück; Nachladen
weiterer Konversationen mit erhaltener Sortierung; Hinweis „New messages"
bei hochgescrolltem Verlauf, Klick scrollt ans Ende und blendet ihn aus;
Stumm-Schalter ändert `aria-pressed` und gibt den Fokus zurück, die Liste
zeigt Symbol und `aria-label`, ohne Ungelesen-Zähler; Beschriftungen
bleiben nach dem Senden in der Seitensprache; Wort-Prefix-Suche findet
„Chat Carol" über „Car", ohne Treffer erscheint ein Hinweis, unterhalb der
Mindestlänge nichts; Mobilansicht einspaltig mit versteckter Liste und
ohne leeren Zustand; `since` bleibt über zwei Leerlauf-Polls konstant
(Fix aus Phase 3a bestätigt).

**Offene Befunde für einen Fix-Durchgang:**

| Befund | Beobachtung | Vorschlag |
| --- | --- | --- |
| Höhe wird beim Seiten-Scrollen nicht neu berechnet | `resizeViewport()` hängt an `visualViewport`-Events und `window.resize`. Normales Dokument-Scrollen löst keines davon aus. Auf der Demo-Seite beginnt der Chat bei 377 px, bekommt 435 px Höhe und behält sie auch, wenn er nach dem Scrollen ganz oben steht: darunter bleiben rund 400 px leer | Dokument-Scrollen als Auslöser ergänzen (gedrosselt per `requestAnimationFrame`), oder die Höhe an einen Container binden, der selbst am Viewport klebt |
| Kopfzeile frisst die Mobilhöhe | Im Demo-Theme belegt die Kopfzeile 191 px von 435 px, das Eingabefeld 145 px, für den Verlauf bleiben 99 px | Dem Verlauf eine Mindesthöhe geben und die Kopfzeile im Bundle-CSS kompakter halten (Name einzeilig mit Ellipse), damit fremde Theme-Schriftgrößen das Layout nicht kippen |
| Stumm-Schalter ohne sichtbaren Zustand | Beschriftung bleibt „Mute conversation", es gibt keine CSS-Regel für `[aria-pressed="true"]`. Sehende Nutzer erkennen den Zustand nicht | Beschriftung im gedrückten Zustand auf „Unmute conversation" wechseln und zusätzlich eine sichtbare Zustandsauszeichnung ergänzen |
| Nachladen kann bei Tab-Wechsel hängen bleiben | `loadMore()` wartet innerhalb von `try` auf einen `requestAnimationFrame`. Wird der Tab in diesem Moment versteckt, läuft weder der Rest des `try` noch das `finally`: `aria-live` bleibt `off` und die Frame-Sperre bestehen, bis der Tab wieder sichtbar ist | Das Warten auf den Frame aus dem kritischen Abschnitt nehmen oder gegen `visibilitychange` absichern |
| Leerlauf-Poll rendert den obersten Eintrag neu | `since` ist inklusiv, also liefert jeder Poll die Konversation mit genau diesem Zeitstempel erneut; gemessen zwei Stream-Renderings pro Poll ohne inhaltliche Änderung | Bei „nichts Neues" mit `204` antworten oder den Cursor exklusiv mit ID-Tiebreaker führen |

### Erkenntnisse aus Phase 3c

Abgeschlossen 2026-09-17, drei Codex-Commits. Alle fünf Befunde aus dem
Browser-Review von Phase 3b sind behoben und im Browser nachgemessen
(Desktop und 375 px, angemeldet als Demo-Mitglied Alice, Gegenseite per
HTTP-Skript).

| Befund | Messung vorher | Messung nachher |
| --- | --- | --- |
| Höhe bei Seiten-Scroll | 435 px, unverändert nach dem Scrollen | 435 px, nach dem Scrollen 812 px; der Chat füllt den Viewport |
| Kopfzeile und Verlaufshöhe | Kopf 191 px, Verlauf 99 px | Kopf 44 px, Name einzeilig mit Ellipse, Verlauf 246 px und nach dem Scrollen 623 px; Mindesthöhe `--member-chat-history-min-height` |
| Sichtbarer Stumm-Zustand | Beschriftung unverändert, keine Zustandsauszeichnung | Beschriftung wechselt auf „Unmute conversation", Hintergrund wechselt, zusätzlich innenliegender Rahmen; Fokus kehrt auf den Schalter zurück |
| Nachlade-Sperre bei verstecktem Tab | Warten auf einen Animations-Frame im kritischen Abschnitt | Warten entfernt; Node-Test mit verstecktem Dokument und nie laufenden Animations-Frames belegt gelöste Sperre und `aria-live` zurück auf `polite` |
| Leerlauf-Rendering der Liste | zwei Stream-Renderings pro Poll | null Renderings über zwei Polls, Antworten sind `204` mit rund 300 Byte; Cursor und Fingerabdruck bleiben stehen |

Die Gegenprobe ist bestanden: Eine echte neue Nachricht erzeugt weiterhin
genau zwei Renderings, Liste und Verlauf aktualisieren sich, Cursor und
Fingerabdruck rücken vor.

Neu eingeführt und für Phase 4 zu dokumentieren: der optionale
Abfrageparameter `fingerprint`, der Antwort-Header `X-Chat-Fingerprint`,
das Frame-Attribut `data-chat-fingerprint` und das Feld
`ChatView.conversationFingerprint`. Der Fingerabdruck entsteht aus den
Standard-Ansichtsdaten; Projekt-Templates, die zusätzlichen wechselnden
Zustand anzeigen, müssen ihn erweitern, sonst unterdrückt der Server
deren Aktualisierung.

### Erkenntnisse aus Phase 4

Abgeschlossen 2026-09-17, vier Codex-Commits. Automatisierte Prüfungen
laufen sauber (85 Tests, 526 Assertions, PHPStan, ECS, Rector, Twig,
Webpack, HTTP-Skript, Host-Smoke-Test).

**Im Browser bestätigt:** Die Badge-Route liefert den Zähler, verlinkt über
`lastPageId` auf die Chat-Seite, trägt eine übersetzte `aria-label`,
`aria-live="off"` und `no-store, private`; die Twig-Funktion pinnt die
Sprache über `_locale`, ein direkter Aufruf ohne Parameter folgt der
Request-Sprache. Das Polling des Badge-Frames funktioniert grundsätzlich
(mit 2 s Intervall gemessen). Backend-Modul, schreibgeschützte Listen,
Label- und Lösch-Callbacks sind über den Host-Smoke-Test registriert.

**Nicht geprüft:** die Backend-Oberfläche selbst, weil dafür eine
Backend-Anmeldung nötig ist.

**Zwei Fehler im Polling-Skript, unabhängig von Phase 4 eingeführt, aber
erst jetzt aufgefallen:**

| Fehler | Reproduktion | Wirkung |
| --- | --- | --- |
| `turbo:before-cache` stoppt das Polling dauerhaft | Nach vollem Seitenaufbau 3 Polls in 9 s. Danach `turbo:before-cache` ohne Navigation ausgelöst: 0 Polls in 10 s. Weder `pageshow` noch `visibilitychange` stellen den Betrieb wieder her, nur ein vollständiger Seitenaufbau | Der Handler setzt `active = false` und leert die Frame-Liste; nur `turbo:load` baut beides wieder auf. Turbo feuert das Ereignis auch bei `pagehide`. Nach einer Zurück-Navigation aus dem bfcache oder beim Ausblenden in eingebetteten und mobilen Browsern steht der Chat still: keine neuen Nachrichten, kein Badge, ohne jeden Hinweis für den Nutzer |
| `visibilitychange` startet jedes Intervall neu | Badge mit 30 s Intervall pollte in 38 s kein einziges Mal, während die Sichtbarkeit mehrfach wechselte; derselbe Badge mit 2 s Intervall pollte normal. Liste (15 s) und Verlauf (4 s) liefen weiter | `schedule()` verwirft beim Sichtbarkeitswechsel die Restlaufzeit und beginnt das volle Intervall von vorn. Wer häufig den Tab wechselt, bekommt bei langen Intervallen nie eine Aktualisierung; der Badge ist genau der Fall |

### Erkenntnisse aus Phase 4b

Abgeschlossen 2026-09-17, zwei Codex-Commits. Beide Polling-Fehler aus
Phase 4 sind behoben und im Browser nachgemessen.

| Fehler | Messung vorher | Messung nachher |
| --- | --- | --- |
| `turbo:before-cache` stoppt das Polling | 0 Abfragen in 10 s, keine Erholung durch `pageshow` oder `visibilitychange` | Nach `turbo:before-cache` und `pageshow` ohne Navigation 2 Abfragen in 9 s |
| `visibilitychange` startet das Intervall neu | Badge mit 30 s pollte in 38 s kein einziges Mal | Badge mit 30 s pollte in 36 s trotz fünf Sichtbarkeitswechseln einmal; Frame vollständig geladen |

Die Lösung führt `dueAt` je Frame ein: Der Fälligkeitszeitpunkt überlebt
Sichtbarkeitswechsel, das Intervall wird nicht neu gestartet. Der Abbau
läuft jetzt auch bei `pagehide`, der Aufbau zusätzlich bei `pageshow`,
`turbo:render` und beim Wechsel auf sichtbar; `start()` ist idempotent.
Zwei Regressionstests unter `.docs/build/verify-phase-4b-client.cjs`
schlagen gegen den alten Stand fehl.

**Messhinweis für künftige Prüfungen:** Ist das Browser-Panel der
Desktop-App ausgeblendet, meldet die Seite `document.hidden` und der Chat
pausiert bestimmungsgemäß. Messungen zum Polling sind dann wertlos. Das
Panel muss während der Messung sichtbar bleiben, etwa durch regelmäßige
Screenshots.

---

### Phase 5 findings

Optional PWA integration is implemented and covered by unit, compiled-container
and real-database recipient tests. The full required automated checks pass;
actual device delivery is not verified. `ConversationUrlGenerator` now accepts
an optional reference type in `forConversation()` and `generate()`, preserving
the original absolute-path defaults. Push explicitly requests an absolute URL.
The original external bridge and unverified click-target assumptions are
corrected in section 8.2. See `.docs/build/reports/phase-5-push.md` for commands,
outputs, decisions and end-to-end acceptance prerequisites.

## 16. Stand der Umsetzung

| Phase | Inhalt | Stand |
| --- | --- | --- |
| 1 Fundament | Paket, Konfiguration, Schema, Domain, Gateways, Services, Events, Voter, Mitgliedslöschung | abgeschlossen |
| 2 Kontakte | Viewer, Contact, Factory, Resolver, Service, Provider, Registry | abgeschlossen |
| 3a Frontend-Kern | Content-Element, Routen, Views, Templates, Streams, Polling | abgeschlossen |
| 3b Frontend-Ausbau | Nachladen, Stummschalten, Zeitformat, Scroll-Verhalten, Barrierefreiheit | abgeschlossen |
| 3c Fixes | fünf Befunde aus dem Browser-Review | abgeschlossen |
| 4 Rand | Backend, Badge, URL-Auflösung, stabile API, README | abgeschlossen |
| 4b Polling-Robustheit | zwei Lebenszyklus-Fehler im Skript | abgeschlossen |
| 5 Push | Optional PWA integration inside the bundle, loose dependency | implemented; real device delivery not verified |

Offen außerhalb der Phasen:

* Die Backend-Oberfläche ist nicht im Browser geprüft, dafür ist eine
  Backend-Anmeldung nötig. Registrierung, Listen und Callbacks sind über
  den Host-Smoke-Test und Integrationstests belegt.
* Die Root-Seite des DDEV-Demos wurde von `contao0507.contao.hhdev` auf
  eine leere Domain umgestellt, damit das Browser-Panel der Desktop-App
  sie über `https://127.0.0.1:<port>` erreicht. Für den Dauerbetrieb
  entweder zurücksetzen oder `host_https_port` im DDEV-Projekt fixieren.
* `.docs/BROWSER_CHECKLIST.md` listet, was nur auf echten Geräten prüfbar
  bleibt: iOS- und Android-Tastatur, Standalone-PWA, Screenreader.

