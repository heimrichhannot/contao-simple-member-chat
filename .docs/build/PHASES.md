# Umsetzungsphasen

Fachliche Quelle ist `.docs/CONCEPT.md`. Jede Phase hat einen eigenen
Prompt unter `prompts/` und endet mit einem Bericht unter `reports/`.
Eine Phase beginnt erst, wenn die vorige committet ist und ihr Bericht
vorliegt.

| Phase | Inhalt | Konzept-Abschnitte |
| --- | --- | --- |
| 1 Fundament | `composer.json`, Bundle-Klasse, Manager-Plugin, Config-Tree, `ChatOptions`, DCA der drei Chat-Tabellen und `tl_page`-Feld, Domain-Objekte, Gateways, Services, Events, Voter, Rate-Limiter, Mitgliedslöschung. Keine HTTP-Schicht, kein Frontend. Unit- und Gateway-Tests. | 0, 2, 3, 7, 8.1, 10, 11, 12, 12b |
| 2 Kontakte | `Viewer`, `Contact`, `ContactFactory`, `ContactResolver`, `ContactService`, Provider-Interface, Registry, beide Provider, Provider-Config. Tests. | 4 |
| 3a Frontend-Kern | Content-Element mit Mobil- und Desktop-Layout, Frame- und Action-Routen, View-Objekte, Twig-Templates mit Blöcken und Partials, Turbo-Streams beim Senden, Polling-Skript mit Voll- und Inkrementalmodus, Kontaktsuche, Kontaktstart, Encore-Entry, CSS-Basis im Layer, CSRF, Turbo-Drive-Attribute, Rollen und Labels für Barrierefreiheit, Fokus nach dem Senden. | 5.1 bis 5.4, 5.6, 5.6a (Rollen), 5.7 (Blöcke), 6 |
| 3b Frontend-Ausbau | Nachladen älterer Nachrichten und Konversationen, Stumm-Schalter, Zeitformat mit relativer Anzeige und Tagestrennern, Scroll-Verhalten mit Hinweis auf neue Nachrichten, iOS-Tastatur, vollständige Custom Properties, Rest der Barrierefreiheit, Browser-Checkliste. | 5.3a, 5.5 (Zeit), 5.6a, 5.7, Entscheidungen 5, 6, 8, 9 |
| 4 Rand | Backend-Modul, Twig-Badge, `ConversationUrlGenerator`, Messenger-Vorbereitung, README, Browser-Checkliste. | 5.5, 7, 9, 12b |
| 5 Brücke | Eigenes Paket `contao-member-chat-pwa` mit Listener, Messenger-Message und Handler. | 8.2, Entscheidung 3 |
