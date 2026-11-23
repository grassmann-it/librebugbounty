# Designvorbereitung für 2.3.0

Ausgangspunkt ist LibreBugBounty 2.2.0. Diese Unterlagen halten den UX/UI-Audit, die geplante gemeinsame Designsprache und eine interaktive Vorschau fest. Die vollständige Produktumstellung auf Dark/Light/System ist noch nicht implementiert.

- [UX/UI-Audit](ux-ui-audit-2026-11-23.md): belegte Probleme, vorhandene Stärken, begrenzte 2.2.1-Kandidaten und priorisierte 2.3.0-Pakete mit Abnahmekriterien.
- [Designsystem](2.3.0-design-system.md): Farbrollen, Typografie, Abstände, Controls, Zustände, Theming und schrittweise Migration. MeowChan dient als gezielt untersuchte Musterreferenz.
- [Interaktiver Prototyp](prototype.html): Review, Inventar und Einstellungen mit synthetischen Daten. Direkt im Browser öffnen; alle Assets sind enthalten, es werden keine externen Ressourcen benötigt. Die Aktionen bleiben lokal in dieser Vorschau.

Alternativ lässt sich der Prototyp lokal aus diesem Verzeichnis öffnen:

```sh
python3 -m http.server 8030 --bind 127.0.0.1 --directory docs/design
```

Dann `http://127.0.0.1:8030/prototype.html` aufrufen. Der Server endet mit Strg+C.

Beim Vergleich zuerst Dark, Light und System wechseln, anschließend Review in voller und halber Fensterbreite sowie auf Mobil betrachten. Die Zustandsauswahl zeigt verfügbare, ausstehende, fehlende und fehlerhafte Belege. Inventarfilter und Einstellungsvalidierung illustrieren gemeinsame Controls. Die Darstellungsauswahl wird nur für diese Vorschau im Browser gespeichert.

Der Prototyp veranschaulicht die Gestaltung, nicht sämtliche fachlichen Abläufe: Er ersetzt weder die produktive Review-Warteschlange noch Belegprüfung, Speichern, PoC oder Nachverfolgung. Die bestehenden Verträge und Tests bleiben Grundlage der späteren Umsetzung. Das Audit unterscheidet Browserbelege, Quellnachweise und noch zu prüfende Designhypothesen ausdrücklich.
