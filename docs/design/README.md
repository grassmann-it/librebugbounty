# Designvorbereitung für 2.3.0

Ausgangspunkt ist LibreBugBounty 2.2.0. Diese Unterlagen halten den UX/UI-Audit, die geplante gemeinsame Designsprache und eine interaktive Vorschau fest. Die vollständige Produktumstellung auf Dark/Light/System ist noch nicht implementiert.

- [UX/UI-Audit](ux-ui-audit-2026-11-23.md): belegte Probleme, vorhandene Stärken, begrenzte 2.2.1-Kandidaten und priorisierte 2.3.0-Pakete mit Abnahmekriterien.
- [Designsystem](2.3.0-design-system.md): Farbrollen, Typografie, Abstände, Controls, Zustände, Theming und schrittweise Migration. MeowChan dient als gezielt untersuchte Musterreferenz.
- [Interaktiver Prototyp](prototype.html): Review, Inventar und Einstellungen mit synthetischen Daten. Direkt im Browser öffnen; alle Assets sind enthalten, es werden keine externen Ressourcen benötigt. Die Aktionen bleiben lokal in dieser Vorschau.
- [KI-Entwürfe und MCP](2.3.0-ai-email-and-mcp.md): Architektur für wiederverwendbare Schreibprofile, Fallprojektion, direkten API-Zugriff und eine spätere MCP-Anbindung. Der erste Ablauf „E-Mail vorbereiten“ ist separat im [Benutzer- und Konfigurationsleitfaden](../AI_EMAIL.md) beschrieben.
- [Anbieter, Modelle und Empfehlungslinks](2.3.0-ai-providers-and-referrals.md): API-/CLI-Adapter, exakte Modellwahl, Katalogquellen, Fähigkeiten und die vorhandenen LAIN-Links. Der [interaktive Anbieter-Prototyp](ai-providers-prototype.html) zeigt den geplanten Einstieg; zusätzliche Anbieter sind dadurch noch nicht in der Anwendung angebunden.
- [PHP-/Frontend-Stack und visuelle KI-Hinweise](2.3.0-stack-and-visual-triage.md): Tailwind, kleine JS-Ergänzungen und Svelte im Vergleich; Composer-Nutzung, optionale Screenshot-Einschätzung und getrennte Anforderungen an einen Browser-Agenten. Die beschriebenen Stack- und KI-Ausbaustufen sind noch nicht umgesetzt.

Alternativ lässt sich der Prototyp lokal aus diesem Verzeichnis öffnen:

```sh
python3 -m http.server 8030 --bind 127.0.0.1 --directory docs/design
```

Dann `http://127.0.0.1:8030/prototype.html` aufrufen. Der Server endet mit Strg+C.

Beim Vergleich zuerst Dark, Light und System wechseln, anschließend Review in voller und halber Fensterbreite sowie auf Mobil betrachten. Die Zustandsauswahl zeigt verfügbare, ausstehende, fehlende und fehlerhafte Belege. Inventarfilter und Einstellungsvalidierung illustrieren gemeinsame Controls. Die Darstellungsauswahl wird nur für diese Vorschau im Browser gespeichert.

Der Prototyp veranschaulicht die Gestaltung, nicht sämtliche fachlichen Abläufe: Er ersetzt weder die produktive Review-Warteschlange noch Belegprüfung, Speichern, PoC oder Nachverfolgung. Die bestehenden Verträge und Tests bleiben Grundlage der späteren Umsetzung. Das Audit unterscheidet Browserbelege, Quellnachweise und noch zu prüfende Designhypothesen ausdrücklich.

Der erste begrenzte Patch ist umgesetzt: **A01 / 221-01** hält Review-Notice-Aktionen und den Entscheidungs-Countdown außerhalb der ausblendbaren Domain-Spalte sichtbar. Er liegt auf `feat/2.2.1` und ist in `feat/2.3.0` übernommen; eine 2.2.1-Veröffentlichung ist damit noch nicht erfolgt. Die übrigen Auditpunkte bleiben priorisierte Aufgaben.

Prüfnachweise für diesen Patch: 20 reale DE/EN-Layoutkombinationen, sechs als solche gekennzeichnete geometrische Stresstests mit zusätzlichen Notice-Aktionen und die vorhandene Claim-Auswahl-Suite bestanden. `tests/browser/run_review_notice.py` führt die neue Regression mit eigener lokaler Fixture aus und ist in der CI registriert. Der Prototyp wurde separat in Dark/Light bei 1440, 960 und 390 Pixeln sowie auf Theme-, Fokus-, Formular- und Zustandswechsel geprüft.
