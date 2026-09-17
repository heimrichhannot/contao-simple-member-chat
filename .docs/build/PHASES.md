# Umsetzungsphasen

Fachliche Quelle ist `.docs/CONCEPT.md`. Jede Phase hat einen eigenen
Prompt unter `prompts/` und endet mit einem Bericht unter `reports/`.
Eine Phase beginnt erst, wenn die vorige committet ist und ihr Bericht
vorliegt.

| Phase | Inhalt | Konzept-Abschnitte |
| --- | --- | --- |
| 1 Fundament | `composer.json`, Bundle-Klasse, Manager-Plugin, Config-Tree, `ChatOptions`, DCA der drei Chat-Tabellen und `tl_page`-Feld, Domain-Objekte, Gateways, Services, Events, Voter, Rate-Limiter, Mitgliedslöschung. Keine HTTP-Schicht, kein Frontend. Unit- und Gateway-Tests. | 0, 2, 3, 7, 8.1, 10, 11, 12, 12b |
| 2 Kontakte | `Viewer`, `Contact`, `ContactFactory`, `ContactResolver`, `ContactService`, Provider-Interface, Registry, beide Provider, Provider-Config. Tests. | 4 |
| 3 Frontend | Content-Element, Frame- und Action-Routen, Twig-Templates mit Blöcken, Turbo-Streams, Polling-Skript, Nachladen, Stummschalten, Zeitformat, Barrierefreiheit, Encore-Entry, CSS-Layer und Custom Properties. | 5, 6 |
| 4 Rand | Backend-Modul, Twig-Badge, `ConversationUrlGenerator`, Messenger-Vorbereitung, README, Browser-Checkliste. | 5.5, 7, 9, 12b |
| 5 Brücke | Eigenes Paket `contao-member-chat-pwa` mit Listener, Messenger-Message und Handler. | 8.2, Entscheidung 3 |
