# LibreBugBounty: UX/UI-Audit nach 2.2.0

Stand: 23. November 2026. Geprüfte Produktbasis: v2.2.0, Commit `b45416e4794ec69e2a71fee32c8010093c8140b6`. Ziel: begrenzte Fehlerkorrekturen für 2.2.1 und ein schrittweiser, prüfbarer Designplan für 2.3.0. Ergänzungen: [Designsystem](2.3.0-design-system.md), [Diskussionsprototyp](prototype.html).

## Entscheidung

**Die App braucht eine gemeinsame Gestaltungsschicht und gezielte Workflowkorrekturen, keine Neuschreibung.** 2.2.0 hat den wichtigsten Arbeitsplatz bereits deutlich verbessert: große Evidenz, einklappbare Angaben/Queue, kompaktes Focus-Menü mit M/I/H/F, gemeinsame Metadaten-/Zoominsel und schnelle, seriell gespeicherte Entscheidungen. Diese Teile bilden die Basis für 2.3.0.

Die größten verbleibenden Probleme sind: notwendige Review-Aktionen werden in bestimmten responsiven Claim-Layouts ausgeblendet; einzelne Fehlerpfade verlieren Entwürfe oder verständliches Feedback; verwandte Screens besitzen unterschiedliche Controls, Informationsdichte und Navigation; Dark ist strukturell fest eingebaut. Ein einzelner Theme-Schalter würde die Inkonsistenzen sichtbar machen, sie aber nicht lösen.

## Vorgehen und Beleggrenzen

- Quellprüfung aller relevanten Bereiche: Intake, klassisches/Focus-Review, Inventory/Arbeitskorb, Details/Belege/Vergleich, Kontakt/Meldeakte/Nachverfolgung, Statistik, Export, Settings, About/Status, lokale Doku, Fehler/Recovery, Dialoge, Navigation, Formulare und Interactive/noVNC-Shell.
- Geprüft wurden PHP-Templates und relevante Controller, 21 Studio-CSS-Dateien, Feature-JavaScript und vorhandene Browser-Tests. Keine einschlägige `AGENTS.md` im Workspace oder den Elternpfaden gefunden.
- Unabhängiger Browseraudit mit isolierter synthetischer Cribrum-Fixture: 66 allgemeine Screenshots plus gezielte Notice-/Dialog-Prüfungen (79 PNGs insgesamt). Desktop, halbe Breite und Mobil; keine externen Browserrequests, keine JavaScript-Fehler im beobachteten Sweep. Geschäftsdaten wurden vor/nach reiner Navigation verglichen; Delay-Einstellung ausschließlich in der Wegwerf-Fixture geändert.
- Lokale Rohbelege liegen unter `var/design-audit-20261123/` (insbesondere `notice-audit.json`, `notice-*.png`, `export-960x900.png`, `inventory-1440x900.png`). Diese Laufartefakte sind kein Bestandteil der Veröffentlichungsdokumente. Private Fälle/URLs, Kundeninformationen und echte Screenshots werden nicht benötigt.
- **B** = im Browser beobachtet; **S** = aus unverändertem Quellcode nachgewiesen; **H** = begründete Designhypothese/Prüfrisiko. Keine pauschale Aussage über WCAG-Konformität. Live-Remote-Browser und Zielaufnahmen wurden nicht gestartet.

Prioritäten: **P1** blockiert oder verfälscht einen fachlich wichtigen Arbeitsweg; **P2** verursacht wiederkehrende Reibung, schlechte Wiederauffindbarkeit oder Inkonsistenz; **P3** ist lokaler Klarheits-/Polishbedarf. Priorität und Releasezuordnung sind getrennt: nicht jeder P1-Befund ist ein sicherer kleiner Patch.

## Gute Grundlagen, die bleiben müssen

| Grundlage | Beleg | Warum erhalten |
| --- | --- | --- |
| Große Evidenz und reduzierter Focus | `public/css/studio-review-focus.css`; `public/js/studio-review.js:108–134` | Ein DOM-Satz wird reversibel verlagert; keine getrennten fachlichen Zustände für Focus und klassisch |
| Fünf vorbereitete Karten, serielle Saves, Schutz vor Doppelversuch | `public/js/studio-review-queue.js:6–8,117–138`; `studio-review.js:408–438` | Hoher Durchsatz ohne paralleles Umordnen von Entscheidungen; unklare Antworten werden nicht blind wiederholt |
| Geschützte Tastaturkontexte | `studio-review.js:607–633`; `studio-shortcuts.js:48–58` | Eingaben, Dialoge, Remote-Browser, Selektion, Composition und gehaltene Tasten lösen keine falsche Bewertung aus |
| Native Formulare, `details`, reale Fallbackseiten | `templates/studio/navigation.php`; `public/js/studio-utilities.js:16–37,59–101` | Grundfunktionen bleiben ohne JavaScript erreichbar; Browsermodalität, Escape und Fokus-Rückgabe sind bereits vorhanden |
| Bildbedienung mit Maus, Touch und Tastatur | `public/js/studio-review-viewer.js:96–150` | Zoom/Pan bleiben unabhängig von Bewertungsaktionen |
| Unterschiedliche Empty-/Pending-/Errorzustände | `review.php:209–215`; `inventory.php:250–269`; `finding.php:134–158` | Erststart, leere Auswahl, fehlendes Bild und laufende Aufnahme sind fachlich verschiedene Zustände |
| Settings-Validierung | `settings.php:45–70`; weitere Feldgruppen entsprechend | Werte bleiben erhalten; Fehlerübersicht, Feldfehler und ARIA-Bezüge existieren als Wiederverwendungsvorlage |
| Statistik mit Tabellenfallback und Linienmustern | `statistics.php:187–214`; `statistics.js:103–131,202–274` | Daten sind auch außerhalb rein visueller Diagramme zugänglich |
| Export-Snapshot und private Inhalte opt-in | `public/js/studio-export.js:7–27`; `export.php:204–235` | Geänderte Auswahl wird sichtbar veraltet; Download nutzt keine heimlich andere Auswahl |

## Releaseübergreifende Defizite

### A01 · P1 · B/S · Review verbirgt notwendige Aktionen

`templates/studio/review.php:353–357` legt Fallkontext, einzige Keep-/Klassifikations-/WAF-Acknowledge-Aktionen und Lade-/Entscheidungsstatus gemeinsam in `.review-dock-case`. `public/css/studio-review.css:479–481` blendet diesen Container bei Claims klassisch bis 1100 px aus; `:511–518` im Focus ab 900 px und bis 760 px. Damit verschwindet mehr als wiederholter Domaintext.

Browserbestätigung mit echtem synthetischem Notice+Claims-Fall: aktivierter Keep-Button und laufender „Bewertung in 5 s“-Status haben 0×0 px bei **960 klassisch, 1440 Focus und 720 Focus**. Positive Kontrollen **1440 klassisch und 800 Focus** zeigen beide. Währenddessen sind Urteilsbuttons wegen des Delays tatsächlich gesperrt. Acknowledge-Buttons teilen denselben versteckten Container; deren Quellenbeleg ist eindeutig, die konkreten Buttons wurden nicht alle separat im Browser ausgeführt.

**2.2.1:** fachliche Aktionen und Warte-/Ladestatus vom optionalen Fallkontext trennen; keine kopierten Formulare. Die gezielte Korrektur liegt auf `feat/2.2.1` (Fix `fe95c256`, CI-Integration `ba13790b`) und ist auch in `feat/2.3.0` enthalten. Dieser Audit beschreibt weiterhin den veröffentlichten 2.2.0-Stand, nicht den späteren Fix. Abnahme: alle zulässigen Keep-/Acknowledge-Wege und Delaygründe in beiden Modi bei 375/720/800/960/1440 px sichtbar, per Maus und Tab/Enter erreichbar; Claimauswahl und Speichersequenz unverändert.

### A02 · P1 · S · Formularfehler verlieren Entwürfe

`ContactRouteController.php:31–33` und `DisclosureRecordController.php:31–38` beantworten fachliche Fehler mit 303-Redirect und allgemeiner Meldung. `contact-route.php:22–34` rendert anschließend gespeicherte Werte; `disclosure-record.php:20–24` setzt Datum/Kanal zurück und Textfelder leer. Das Kontaktformular klappt wieder zu. Der Fehler oben in `finding.php:113–115` liegt zudem abseits des Hashziels `#kontakte`/`#meldung`.

**2.3.0, frühes funktionales Paket:** Settings-Muster für Entwurfserhalt übernehmen: lokale Fehlerübersicht, Feldbezug, betroffenen Abschnitt öffnen, Fokus sinnvoll setzen. Konflikt muss gespeicherten Stand und eigenen Entwurf unterscheidbar machen. Kein privater Entwurf in Querystrings. Abnahme: synthetischer Validierungsfehler und Versionskonflikt erhalten sämtliche Eingaben; Daten bleiben unverändert; korrigierte einmalige Übermittlung und No-JS-Pfad funktionieren. Wegen Server-/Redirect-/Konfliktsemantik kein beiläufiger Stylingpatch.

### A03 · P2 · S/B · Designwerte sind verstreut, Dark ist fest eingebaut

`public/css/studio.css:1–18` stellt zehn Grundfarbvariablen bereit, aber 21 Studio-Stylesheets enthalten zusammen **1.120 Hex-Farbliterale in 850 Schreibweisen**. Lexikalische Zählung, keine Zahl eigenständiger sinnvoller Farbtöne. 637 `font-size`-Deklarationen enthalten besonders häufig 11 px (149), 10 px (134) und 12 px (113); weitere `!important`-Varianten kommen hinzu. Nahe Breakpoints wie 699/700/720/760 px und diverse Controlradien vervielfachen Sonderfälle.

14 Seitentemplates setzen `color-scheme=dark`; Basis-CSS ebenfalls. Diagrammpaletten stehen zusätzlich in `templates/studio/statistics.php:11,58,63–67` und `public/js/statistics.js:16–22`. Der Browser bleibt auf den geprüften Routen auch bei angeforderter System-Light-Preference dunkel. **2.3.0:** semantische Tokens + gemeinsame Komponenten zuerst, dann vollständige Theme-Parität; genaue Strategie im [Designsystem](2.3.0-design-system.md).

### A04 · P2 · S/B · Lesbarkeit und Feldgrenzen gezielt verbessern

Kleine Schrift ist die wiederkehrende Schwäche, nicht pauschal „zu wenig Kontrast“: Standard-Muted `#a4adb8` auf Panel `#1b1e22` ergibt **7,37:1**, primärer Buttontext `#0c182a` auf `#669fed` **6,57:1**. Der approximative Browser-Sample sichtbarer Texte bei 1440 px fand keinen Wert unter 4,5:1 (Minimum ca. 5,01); das ist keine vollständige Kontrastprüfung.

Quellberechnung ohne Alpha zeigt schwache Eingabegrenzen: Intake `#495260` auf `#131619` **2,30:1** (`studio.css:95`), Inventory `#444e5b` auf `#15191e` **2,09:1** (`studio-inventory.css:28`), Statistik `#3e4a5a` auf `#1b222c` **1,78:1** (`studio-statistics.css:31`). Wo diese Grenze nötig ist, um ein leeres Eingabefeld zu erkennen, ist sie ein konkreter 3:1-Prüfpunkt. Dekorative Linien und klar beschriftete Buttons benötigen nicht pauschal einen 3:1-Rahmen. Ein eigener Control-Border-Token ist deshalb sinnvoll. [W3C Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html)

Review senkt Bildmetadaten bis 8 px (`studio-review.css:453`) und Urteilbuttontext auf 9 px (`:462`). Inventory-Meta liegen häufig bei 9–11 px (`studio-inventory.css:59–67,142`), Statistiktabellen auf Mobil bei 9 px (`studio-statistics.css:215`). **2.3.0:** Hierarchie und Umbruch zusammen mit Typografie ändern; kein pauschales Vergrößern innerhalb unverändert starrer Raster.

### A05 · P2 · B/S · Mobilnavigation hat je Screen eine andere Erreichbarkeit

Bei 390 px liegt die Navigation im Review und in Statistik am unteren Viewportrand (ca. y=740), im Bestand hingegen am Dokumentende (ca. y=4027), in Export y=3392 und Settings y=2971. Die konkreten Positionen sind fixtureabhängig; die unterschiedliche Scrollstrategie ist strukturell (`studio.css:253` und seitenspezifische Shell-Overrides). Bei 960 px beansprucht eine zweite Utility-Zeile zusätzlich 48 px (`studio-utilities.css:25–30`).

**2.3.0:** ein Shell-Vertrag für mobile Appnavigation: stets erreichbar, mit reservierter Inhaltsfläche und Safe-Area, ohne Formfelder/Fokus zu überdecken. Ausgewählte Route und Fallkontext bleiben erhalten. Desktop-Footer nicht grundlos durch eine neue Sidebar ersetzen.

### A06 · P2 · S · Unbestätigte Saves sind nach Quittieren schwer wiederzufinden

`studio-review-queue.js:70–73` hält Fehlerursache und Ungewissheit vor, zeigt aber nur eine Gesamtzahl (`:65–68`). × leert `failures` samt persistierter Warnung (`:49–51,25–32`). Das ist **kein nachgewiesener Verlust gespeicherter Bewertungen**. Es entfernt jedoch den lokalen Hinweis auf ungeklärte Vorgänge, bevor deren Ausgang geprüft ist.

**2.3.0:** „Meldung verbergen“ und „Vorgang geklärt“ trennen. Ein ruhiger, wieder aufrufbarer Save-State zeigt pending/bestätigt/abgelehnt/unklar und führt zu offenen Fällen. Kein automatischer Doppel-POST bei unklarem Ausgang. 2.2.1 soll diese Queue-Semantik nicht spontan umbauen.

## Screen-für-Screen-Plan

| Bereich | Schmerzpunkt / Beleg / Evidenzgrad | Ziel für 2.3.0 |
| --- | --- | --- |
| **Intake** | P2/H: Intro, Save-Hint, Markerhinweis und Fußtext wiederholen Prozessgrenzen (`intake.php:36,56,84,137`); viele Blöcke konkurrieren mit History bei geringer Höhe. P2/S: zweite Paste bei aktivem Paste-submit wird während `saving` nach `preventDefault()` still verworfen (`intake.js:845–846`) | Eine knappe dauerhafte Prozesszeile, Vertiefung in Hilfe; Fortschritt kompakt. Bei gesperrter Paste bestehende Statusregion erklären lassen; keine neue Batchfunktion nötig |
| **Review klassisch** | P1/B/S: A01. P2/H: feste Höhen und kumulative Overrides (`studio-review.css:1–3,239–324,432–518`) machen Zustand+Breite schwer vorhersehbar | Gemeinsamer Action-/Statusbereich; Kontext darf verschwinden, zulässige Handlung nicht. Referenzgeometrie pro Zustandskombination sichern |
| **Review Focus** | P1/B/S: A01. P2/S: viele 9–10 px-Metadaten und lange Claimnamen per Ellipsis (`studio-review.css:143`; `studio-review-focus.css:131–135,182–183`) | Große Evidenz und M/I/H/F erhalten. Gesamtes Focus-Dock unten links: Domain links, Claims Mitte, Entscheidungskreuz rechts; Bildinfos/Zoom unten rechts. Voller Claimname ohne Hover erreichbar; Queue/Details kollisionsarm und scrollfähig |
| **Review Interaktiv / noVNC** | P3/S: deaktiviertes „Interaktiv prüfen“ erklärt Gründe nur per `title` (`review.php:221`). Live-Remote-Arbeitsplatz wurde bewusst nicht gestartet | Grund sichtbar oder zugänglich referenziert; Shell, Toolbars, Verbindungs-/Fehlerstatus themenfähig. Remote-Seitenfarben unverändert. Im isolierten Runtime-Smoke erneut prüfen |
| **Inventory** | P2/S/H: Modus + gespeicherte Ansichten + Scope + Suche + vier Hauptfilter + Cribrum + weitere Filter vor Resultaten (`inventory.php:59–185`); Kurzmeldung „Weitere Filter aktiv“ nennt Bedingungen nicht einzeln (`:183`). B: Erste Ergebniszeile im 1440×900-Sample erst bei ca. y=655, mobil noch unter dem ersten Viewport | Kompakter Such-/Viewkopf, sichtbare benannte aktive Bedingungen, erweiterte Auswahl aufklappbar. Bestehende GET-Filter und differenzierte Empty States erhalten |
| **Inventory halbe Breite** | P2/S: unter 1100 px Karten mit Labels statt Tabelle (`studio-inventory.css:98–110`) sind sinnvoll, erhöhen aber die Leselänge; Sekundärinfos werden weiter verkleinert (`:142`) | Primäre Fallidentität/Handlung zuerst; Metadaten sinnvoll umordnen. 800/960 px als eigenständige Arbeitsgröße, kein nachträglicher Mobile-Patch |
| **Arbeitskorb** | P2/S/H: erbt großen Filtervorbau; Aufgabe muss zwischen Klassifikationsinfos gefunden werden. Gute Slots bereits vorhanden (`inventory.php:207–233`) | Heute/Überfällig/Offen und nächster Schritt führen. Fälligkeit, Meldeweg und letzte Aktivität im primären Scanpfad; technische Klassifikation sekundär |
| **Details / Evidenz** | P2/S: sieben Sectionlinks, aber zweite Position „Bildvergleich“ liegt im DOM erst nach Kontakt/Meldeakte (`finding.php:117–124,405–409`); Scrollmargin bis 185 px (`studio-detail.css:213–219`). Hauptbild hat keine Laufzeit-Decodefehlerbehandlung (`finding.php:179–188`; `studio-detail.js:9–16` nur Vergleich) | Navigation folgt tatsächlicher Reihenfolge; wenige Gruppen „Belege“, „Bewertung“, „Nachverfolgung“, „Verlauf“. Ein gemeinsamer Bild-unavailable-Vertrag für Hauptbild/Vergleich |
| **Details / Pending** | P2/S/H: laufende Aufnahme wird erklärt, aber nur manuelles Neuladen aktualisiert den Zustand (`finding.php:134–158`) | Lokaler Status mit letztem Prüfzeitpunkt und explizitem Refresh oder vorsichtigem Polling; kein neuer Targetrequest. Entwürfe und Scrollposition schützen |
| **Kontakt / Meldeakte / Nachverfolgung** | P1/S: A02. P2/S/H: historische Kontaktmarker (`finding.php:343–368`) und detaillierte Meldeaktivität (`disclosure-record.php:14–25`) sind parallel und erklärungsbedürftig | Meldeakte prominent; historische Markierungen als benannter sekundärer Abschnitt. Fachliche Modelle nicht still zusammenführen oder Nachrichten versenden |
| **Statistics** | P2/S/H: Zeitraum, Bestand und Kalenderjahr haben verschiedene Zählumfänge (`statistics.php:171,217,238,268`); viele Karten konkurrieren. P3/S: Taptext verspricht Öffnen, JS selektiert nur (`:269`; `statistics.js:251–258`) | Drei klare Gruppen mit dauerhaftem Zeitraumbezug. Tabellenfallback und Linienmuster behalten; Palette aus einer Quelle. Beschriftung erklärt Tag auswählen → Fälle öffnen |
| **Export** | P1/S: `report_kind`/`review` wirken verborgen (`export.php:43,93,103`). P2/B: Cribrum-Disclosure wirkt ungestylt, Summarytext klebt zusammen: Regeln nur in `studio-inventory.css:169–172`, nicht im geladenen Export-CSS. P2/S/H: Refresh links vor Inhaltsoptionen, Download weit danach (`export.php:167,204–235`; `studio-export.css:91–95`) | Alle Bedingungen sichtbar; Cribrum als gemeinsame Komponente. Schritte Auswahl → Inhalte → Prüfen & Download; Refresh in Nähe des Downloads, Snapshotvertrag unverändert |
| **Settings** | P2/S/H: langer Vorgabenblock mit danebenliegenden technischen Read-only-Daten; Zeitlimit in Millisekunden (`settings.php:76–84`) | Darstellung/Bedienung, operative Defaults, Diagnose klar gruppieren; gemeinsame Settingzeile. Explizites Speichern fachlicher Vorgaben behalten; Theme lokal sofort anwendbar und so bezeichnet |
| **About / Systemstatus** | P2/S: Definitionen über `studio-settings.css`, `studio-about.css`, `studio-utilities.css` verteilt. Dialoge besitzen bereits guten Loading/Error/Fallback-Vertrag | Gemeinsame Overlay-/Text-/Panelkomponenten, Statuszeitpunkt behalten. Kein Funktionsrebuild. Native Dialogprüfung auf 1440/390 px bestand: Escape + Fokusreturn korrekt; Status-Loading/503 mit Fallback ebenfalls geprüft |
| **Dokumentation / Hilfe** | P2/S/H: unter 850 px gesamte Dokunavigation vor Artikel (`studio-documentation.css:51–57`); Vieltext lenkt vor Einstieg ab | Kompakter Abschnittswähler, aktueller Artikel klar; reale Links und lokale Inhalte bleiben |
| **Fehler / Recovery** | P3/S: Statuslink zeigt noch `/settings#health` (`errors.php:32`), JS-Brücke nötig (`studio-utilities.js:104–107`). P2/H: vier erklärende Texte vor Filtern, Bulkaktion ohne sichtbare Menge (`errors.php:33–38,56–81`) | Direkter Statuslink; knapper Diagnosekopf + Erklärung aufklappbar. Bulkaktion nennt Zahl/Scope. Technischer Fehler, fehlendes Bild und Bewertung bleiben getrennt |
| **Navigation / Dialoge / Formulare** | P2/S: verwandte Buttons 35/36/39/40/44 px, Utility/Shortcutdialoge mit getrennten Stilen (`studio-detail.css:74`, `studio-inventory.css:6`, `studio-export.css:39`, `studio-settings.css:43`, `studio-follow-up.css:53–55`) | Benannte Standard-/Compact-/Touchvarianten, gemeinsame Dialoganatomie und Formfehler; bestehende native Semantik erhalten |

## 2.2.1: begrenzter Fehlerkorrektur-Backlog

Die Zuordnung bedeutet „geeigneter kleiner Kandidat“, nicht „alles bereits implementiert“. A01 ist auf den Entwicklungsbranches korrigiert; die übrigen Punkte brauchen jeweils einen engen Diff mit dem angegebenen Nachweis.

| ID / Prio | Änderung | Akzeptanzkriterium | Risiko / Prüfweg |
| --- | --- | --- | --- |
| **221-01 / P1** | A01: Keep/Acknowledge/Delay vom optionalen Kontext lösen | Notice+Claims behält jede zulässige Aktion bei 375/720/800/960/1440 px, beide Modi; Countdown sichtbar; Tab/Enter | Mittel: Dockhöhe/Claimscroll. Bestehende Review-Fixture erweitern; keine neue Fachlogik |
| **221-02 / P1** | Übernommene Exportfilter `report_kind`/`review` sichtbar beschreiben | `report_kind=mixed&review=deferred` zeigt beide; Vorschau, Download und Rückweg behalten identische Menge; DE/EN, JS aus | Niedrig: nur Auswahlbeschreibung; Filtermodell als Quelle verwenden |
| **221-03 / P2** | Gemeinsames Cribrum-Disclosure korrekt im Export stylen | Gleiche Abstände, Fokus und Summaryanatomie wie Inventory; kein zusammengeklebter Text bei 390/960/1440 px | Niedrig: gemeinsame CSS-Regel an passenden gemeinsamen Ort; keine ganze Inventory-CSS-Datei ins Export einziehen |
| **221-04 / P2** | Wiedervorlagelinks aus Meldeakte öffnen Arbeitskorb | `disclosure-record.php:53` Heute/Überfällig → `view=workbasket`; Fälligkeit und nächster Schritt sichtbar, Filter erhalten | Niedrig: URL-Kontext; Fixture mit beiden Reminderfällen |
| **221-05 / P2** | Hauptbeleg behandelt Laufzeit-Lade-/Decodefehler | Abgebrochene/ungültige lokale Bildantwort zeigt verständlichen Ersatz; Belegdatum/ID bleiben; keine automatische Aufnahme | Niedrig bis mittel: gemeinsam mit Vergleich testen; `studio-case-layout.cjs`/Diagnostics erweitern |
| **221-06 / P2** | Paste während laufendem Intake erklärt ausbleibende Erfassung | Gedrosselter Save: zweite URL erzeugt keinen Request, ersetzt keinen Entwurf, Status fordert erneutes Einfügen | Niedrig: bestehende Live-Region; keine neue Queue |
| **221-07 / P3** | Kalenderhinweis beschreibt Auswahl + Folge-Link | Touch/Maus/Keyboard gleicher verständlicher Zweischritt | Niedrig: Copy/i18n, vorhandener Kalenderpfad |
| **221-08 / P3** | Fehlerübersicht verlinkt direkt auf `/status` | Mit/ohne JS richtige Seite; Legacy-Bookmark-Brücke bleibt | Niedrig: Linkprüfung |
| **221-09 / P3** | Viewerhilfe nennt + / −, 0, 1, Pfeile; Interaktiv-disabled hat erreichbare Begründung | Hilfe ohne Hover auffindbar; Bildpfeile lösen kein Urteil aus; Begründung auf Touch/Keyboard | Niedrig: Text/ARIA, bestehende Tastaturtests |

Keine Themeumstellung, breite Typografievergrößerung, neue Navigationsarchitektur, automatische Save-Wiederholung oder Kontaktmodellzusammenführung in 2.2.1.

## 2.3.0: priorisierte Umsetzungspakete

| Reihenfolge / Paket | Ergebnis und Abnahme | Abhängigkeit / Risiko |
| --- | --- | --- |
| **230-01 · P1 · Formulare schützen** | A02 beheben; alle Eingaben bleiben bei Validierung/Konflikt, Fehler lokal sichtbar, No-JS erhalten | Eigenes funktionales PR; keine Kopplung an Farbrefresh nötig |
| **230-02 · P1 · Foundation** | [Tokenvertrag](2.3.0-design-system.md) + Typo/Raum/Radius/Borderrollen. Dark-Baseline bleibt zunächst stabil; Kontrastpaare automatisch prüfen | Voraussetzung für Theming; literalbasierte Ersetzung ohne Rollenprüfung vermeiden |
| **230-03 · P2 · Gemeinsame Controls und Shell** | Buttons, Fields, Notices, Panels, Dialoge in mindestens zwei echten Screens; mobile Navigation einheitlich erreichbar; klare Fokuszustände | Mit Intake/Settings beginnen; kein DOM-/data-hook-Bruch |
| **230-04 · P2 · Arbeitsfluss Bestand → Fall → Export** | sichtbare vollständige Filter; Arbeitskorb führt nach Fälligkeit; Sectionreihenfolge stimmt; Export-Refresh nahe Download | Fachliche Filter-/Snapshotsemantik unverändert; Vorher/Nachher mit gleicher Datenmenge vergleichen |
| **230-05 · P2 · Review-Dichte und Save-State** | lesbare Labels/Claims, vollständige Aktionen, stabile halbe Breite; ungeklärte Saves wiederauffindbar | Stärkstes Layout-/Verhaltensrisiko. Serielle Queue, konfigurierbare Vorladungen, keine automatische Doppelübermittlung behalten |
| **230-06 · P2 · Alle Oberflächen Light-fähig** | Chart-/SVG-/Templatefarben gemeinsam; alle Routen einschließlich Fehler, Hilfe, Utility, Interactive/noVNC-Shell; native Controls passen | Feature-Toggle erst nach vollständiger Palette; Medien dürfen themefest bleiben |
| **230-07 · P2 · Dark/Light/System freigeben** | browserlokale Preference, Systemwechsel, Tab-Sync, kein Flash; Themewechsel bewahrt laufenden Entwurf/Review/Zoom | Geltungsbereich sichtbar. Kein ungefragtes globales Umschalten anderer Geräte |
| **230-08 · P2 · Releaseabnahme und CSS-Aufräumen** | Vergleichsscreens, echte Bedienprüfung, Altregeln erst nach Nutzungssuche entfernt; alle Pflichtchecks grün | Kein großer Cleanup im selben Diff wie Queue-/Formularänderung |

## Testplan und Entscheidungstore

**Baseline:** synthetische Fixtures mit leerer Installation, gefiltert leerem Bestand, langer Domain/Claimnamen, vielen Claims, Notice+Claims, fehlendem/kaputtem Bild, queued/running/error-Aufnahme, offenen/fälligen/gesperrten Workflowfällen. Private Daten sind unnötig.

**Geometrie:** 1440×900 und 1280×800; 960×900 und 800×900 als halbe Breite; 390×844; 844×390; 320 CSS-Pixel Reflow. Zusätzlich 200 % Textvergrößerung und 400 % Browserzoom auf 1280 px als 320-px-Äquivalent. Kein Verlust notwendiger Funktionen; nur Beleg/Diagramm/Tabelle darf begründet lokal zweidimensional bleiben. [W3C Reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html)

**Interaktion:** DE/EN, Keyboard-only, Pointer/Touch, Reduced Motion, Forced Colors, JavaScript aus für native Kernwege. Auf Fokusrahmen und vollständige Labels prüfen, nicht nur auf sichtbare Buttonmittelpunkte. Touchziele produktseitig 44 px, WCAG-AA-Minimum 24×24 px mit Ausnahmen separat bewerten. [W3C Target Size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html)

**Zustandskombinationen:** Notice + Claims + 5 Sekunden Delay; Bildladefehler + ausgeklappte Queue; Pending-Save + Fehler + offene Details im schmalen Focus; unklarer Saveausgang + Seitenwechsel; Dialog lädt/fehlschlägt und gibt Fokus zurück; Formularvalidierung/409 mit längerem Text; Themewechsel während Dirty-Form/Reviewqueue und dynamischem Diagrammupdate. Overlayüberschneidung in solchen Kombinationen ist ein Testauftrag, kein hier pauschal nachgewiesener Defekt.

**Vorhandene Tests erweitern:** `tests/browser/studio-review-focus.cjs`, Review-/Claim-/Savequeue-Suites, `studio-case-layout.cjs`, `studio-diagnostics.cjs`, `cribrum-badges.cjs`, `studio-intake-help.cjs`; Runner `run_review.py`, `run_preferences.py`, `run_shortcuts.py`, `run_intake.py`, `run_missing_image_recovery.py`. Tests sollen konkrete Risiken sichern, nicht bloß neue CSS-Klassennamen spiegeln.

**Freigabetore:** pro vertikalem Paket Quellen-/Kontrastprüfung und reproduzierbare Vergleichsscreens; erforderliche Verhaltenstests nach betroffenen Pfaden. Reine Navigation, Themewechsel und Bildfehlerdarstellung verändern keine Geschäftsdaten und lösen keinen Targettransport aus. Theme-Schalter erst freigeben, wenn sämtliche produktiven Routen einschließlich Dialogzustände vollständig abgedeckt sind.

## MeowChan: konkrete Inspiration ohne Kopie

Die Referenz ist der separate MeowChan-Checkout (`oChan`), gezielt gelesener Stand `d54361c2f33c473cc861e6b1a42c0c0b1072b0e5`. Seine `Desktop/WorkspaceStyles.axaml` trennt Dark/Light-Ressourcen, dekorative und interaktive Ränder, Statusfarben und Flächen sowie benannte Typo-/Raum-/Controlskalen. `SettingsRowFactory.cs` definiert gleiche Settingzeilen; `QuickSettingsPopover.cs` beschreibt Header/Body/Status, gemeinsame Werte und Fokus/Escape; `docs/design.md` konsistente Panelreihenfolge und Faktenraster. Detailbelege stehen im [Designsystem](2.3.0-design-system.md#meowchan-als-referenz).

Für LibreBugBounty ist die übertragbare Idee **Ressourcen- und Verhaltenskonsistenz**. MeowChans brauner Light-Akzent, native DIP-Dimensionen, Mediennavigation, Fonts oder Assets werden nicht übernommen. Die eigene blaue Identität und die fachliche Trennung von Beleg, Bewertung, Claim und Nachverfolgung haben Vorrang.
