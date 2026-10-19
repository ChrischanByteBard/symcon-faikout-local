# Faikout Local – Symcon 9.0

**Entwicklungsprototyp v0.6.0. Nicht für produktiven Heizbetrieb freigegeben.**

Eigenständige MQTT-Integration für die lokale Steuerung kompatibler Daikin-Faikout-Geräte über den integrierten Symcon-MQTT-Server. Kein Fork des archivierten Moduls *Faikin 4 Daikin*. Eigene Modul-IDs.

## Ereignisgesteuerte Temperaturübertragung (v0.3.0)

- Standardmäßig ab dieser Version eingeschaltet, solange die **externe Regelung selbst aktiv** und von Faikout bestätigt ist.
- Das Modul abonniert die ausgewählte Temperaturvariable per Symcon `RegisterMessage(..., VM_UPDATE)` und verarbeitet Aktualisierungen über `MessageSink()`.
- Eine zusätzliche Nachricht wird verschickt, wenn sich der Wert gegenüber der letzten **erfolgreich gesendeten** Temperatur um mindestens **0,2 K** geändert hat. Ein Mindestabstand von **30 s** schützt vor Nachrichtenspitzen. Beides ist in der Instanz einstellbar.
- Weiterhin wird alle **120 s** die gültige Temperatur gesendet – auch ohne Änderung (Faikout-Timeout-Absicherung).
- Die Übertragung bleibt gesperrt, wenn die externe Regelung deaktiviert ist oder die sicheren Faikout-Einstellungen nicht bestätigt sind.
- Bei Wechsel der ausgewählten Sensorvariable wird die alte Symcon-Nachrichtenregistrierung entfernt und die neue registriert.
- Diese Änderung betrifft nur die Weitergabe des Messwerts, **nicht** die interne Inverterregelung der Daikin.

## Objektbaum-Reihenfolge und Datenherkunft (v0.6.0)

Die Variablen erhalten jetzt feste Symcon-Objektpositionen. Beim Aktualisieren werden **bereits vorhandene** Modulvariablen anhand ihrer Identifikatoren sortiert (`IPS_SetPosition`); neue, erst mit Faikout-Statusmeldungen angelegte Variablen bekommen ihre Position durch `MaintainVariable`. Keine Unterkategorien und keine Neuanlage existierender Variablen erforderlich. Verknüpfungen, Variablen-IDs, Archivdaten und benutzerdefinierte Darstellungen bleiben erhalten. Die Modulpositionen werden bei späteren Modulupdates erneut gesetzt; eine manuelle Objektposition kann dadurch wieder überschrieben werden. Fremde Unterobjekte werden nicht verändert. Die drei alten Standardnamen für Außentemperatur, Verdichter und Energiezähler werden beim Update sofort auf die neuen Kennzeichnungen umbenannt; selbst gewählte abweichende Namen bleiben bestehen.

| Position | Bereich | Beispiele |
| --- | --- | --- |
| 0–4 | Verbindungsstatus/Freigaben | MQTT-Verbindung, Daikin antwortet, letzter Status, externe Regelung |
| 10–13 | Bedienung Innengerät | Ein/Aus, Modus, Solltemperatur, Lüfter |
| 20–26 | Luftstrom und Komfort | Swing vertikal/horizontal, Komfort, Sensor, Powerful, Econo, Streamer |
| 40–47 | Innenraumtemperaturen/Betrieb | Raum- und Ansaugtemperatur, Referenztemperatur, Fan-RPM |
| 60–62 | Außengerät/Gesamtsystem | Außentemperatur, Verdichter, System-Energiezähler |
| 70–88 | Faikout-Automatik/Einstellungen | AutoPower, Temperatur-Timeout usw. |
| 90–96 | Diagnose | Firmware, letzter JSON-Status, Fehler |

**Quellenhinweise:**
- `outside` stammt aus der vom Außengerät gemeldeten Außentemperatur und wird in jeder Innengerät-Instanz als „Außengerät · Außentemperatur“ gekennzeichnet.
- `comp` gehört zum Außengerät und kann dem S21-Verdichterfrequenzwert (`Rd/Sd`, Hz) entsprechen; solange die genaue Faikout-Ausgabe deiner FTXM-A-Modelle noch nicht geprüft ist, bleibt die Variable als **Rohwert** benannt und erhält keine unbelegte Einheit.
- `Wh` kann beim S21-Protokoll (`FM/GM`) den **gemeinsamen Verbrauch aller Innengeräte an einem Außengerät** darstellen. Deswegen heißt die Variable „System · Energiezähler Wh (Rohwert)“. **Die `Wh`-Werte der drei Innengeräte nicht addieren**, bevor die Herkunft/Skalierung anhand echter FTXM-A-Statusmeldungen verifiziert wurde. Neuere S21-Protokollversionen bieten zusätzlich gerätebezogene Verbrauchszähler (`FU04`), deren separate Nutzung hier noch nicht implementiert ist.

Protokollhintergrund: https://github-wiki-see.page/m/revk/xESP32-Faikout/wiki/S21-Protocol

## Luftstromsteuerung (v0.5.0)

Faikout liefert modell- und protokollabhängige Steuer-/Statusfelder. Dieses Modul legt die folgenden **nativen Symcon-Schalter dynamisch** an, **nur wenn das jeweilige Feld in einer MQTT-Statusmeldung des Innengeräts vorhanden ist**:

| MQTT-Feld | Symcon-Variable | Funktion |
| --- | --- | --- |
| `swingv` | Vertikaler Schwenkbetrieb (auf/ab) | Automatisches Schwenken in der Vertikalen |
| `swingh` | Horizontaler Schwenkbetrieb (links/rechts) | Automatisches Schwenken in der Horizontalen |
| `comfort` | Komfort-Luftstrom (geräteabhängig) | Modellabhängige Komfortfunktion |
| `sensor` | Bewegungssensor-Funktion (experimentell) | Versuch, die vorhandene Sensorfunktion am Innengerät umzuschalten |

Alle Steuerbefehle werden nicht-retained über `command/<hostname>/control` als boolescher JSON-Wert versendet; die angezeigten Werte aktualisieren sich aus `state/<hostname>`. Beispiel `{"swingv":true}`. Vertikal- und Horizontal-Schwenken gleichzeitig entspricht dem 3D-Schwenkbetrieb, soweit das Innengerät ihn unterstützt.

**Grenzen:** Die Felder `swingh` und `swingv` erlauben das **Ein-/Ausschalten der Oszillation**, nicht die Auswahl einer beliebigen festen Lamellenposition oder Winkelvorgabe. Die Funktion `sensor` ist nicht als zuverlässig funktionierende Intelligent-Eye-Steuerung auf jedem Perfera-S21-Modell bestätigt und ist nicht mit einer Positions-/Personenmeldung gleichzusetzen. Das S21-Protokoll liefert nach dem bekannten Stand keine bestätigten Bewegungszonen oder Präsenzerkennungsdaten. `comfort` kann ebenfalls von Modell und Firmware abhängig sein.

Bei echter Hardware anhand der zurückgemeldeten MQTT-Statusdaten und der Reaktion des Geräts überprüfen. Unbekannte Optionen werden niemals auf gut Glück als aktiv gemeldet.

**Quellen:** https://www.faikin.au/pages/faikin-faikout-mqtt-manual; https://github.com/revk/xESP32-Faikout/issues/788; https://github.com/revk/xESP32-Faikout/issues/342

## Korrektur v0.4.2: Verbliebene Legacy-Darstellungen

- **Letzter Status** (Integer-Unixzeitstempel): natives Datum/Uhrzeit mit Datum und Sekunden, bisher `~UnixTimestamp`.
- **Innengerät antwortet** (Boolean): native **nicht bedienbare** Wertanzeige `Antwortet` / `Keine Antwort` mit vollständig ausgefüllten Symcon-9-Optionsfeldern, bisher `~Switch`.
- Vorhandene Variablen-Identifikatoren, IDs, Werte und Archive bleiben erhalten.
- Die dynamisch registrierte Variable `Innengerät antwortet` bekommt ihre neue Darstellung bei der nächsten MQTT-Statusmeldung mit `online`. Die Datum/Uhrzeit-Darstellung wird mit dem Modul-Update registriert.

## Korrektur v0.4.1: Ungültiges Aufzählungsformular

Bei Symcon 9 müssen die Einträge der nativen Aufzählungsdarstellung zusätzlich zu `Value` und `Caption` immer die Felder `IconActive` (false), `IconValue` (leer) und `Color` (-1) enthalten. Diese Felder sind nun für alle Betriebsarten und Lüfterstufen gesetzt. Die Statuswertanzeigen für MQTT-Verbindung und bestätigte Einstellungen erhalten analog die Felder `IconActive`, `IconValue`, `ColorActive` und `ColorValue`. Vorhandene Variablen-IDs, Werte und Aktionen bleiben unverändert.

Die Darstellung wird bei den dynamischen Statusvariablen bei der nächsten Statusmeldung aus `state/<hostname>` neu gesetzt. Zum Testen danach Betriebsart/Lüfterstufe öffnen und die Optionsliste bearbeiten.

## Moderne Symcon-Darstellungen (v0.4.0)

Die Legacy-Profile für Betriebsart, Lüfterstufe und Solltemperatur wurden im Modul durch native Symcon-Darstellungen (Symcon >= 8.0) ersetzt:

- Betriebsart und Lüfterstufe: Aufzählung mit den originalen Faikout-Stringwerten als Optionen.
- Solltemperatur: Schieberegler mit Bereich 10–32 °C und 0,5-°C-Schritten.
- Geräte-Ein/Aus und Freigabe der externen Temperaturregelung: Schalter.
- Daikin-Temperaturmesswerte: Temperatur-Wertanzeigen.

Die Instanz nutzt die neuen Darstellungen über `MaintainVariable()` und `RegisterVariableBoolean()`, **ohne** globale Legacy-Profile neu anzulegen oder individuelle Darstellungen der Benutzer zu überschreiben. Identifikatoren, Variablentypen, Verknüpfungen und Archive bleiben bestehen. Bei bestehenden Messwertvariablen wird die Darstellung bei der nächsten eingehenden Statusmeldung aktualisiert.

Die Mindeständerung im Konfigurationsformular heißt nun kurz **Mindeständerung (K)** und nutzt `NumberSpinner.digits=1`, damit der Standardwert `0,2` korrekt angezeigt wird.

## Bedienfunktionen (v0.4.0)

Die vorhandenen String-Variablen behalten ihre IDs und ihre Faikout-Rohwerte, erhalten aber ein Auswahlprofil in Symcon:

- **Betriebsart:** Automatik (`A`), Heizen (`H`), Kühlen (`C`), Entfeuchten (`D`), Lüften (`F`).
- **Lüfterstufe:** Automatik (`A`), Leise/Nacht (`Q`), Stufe 1–5 (`1`–`5`).
- **Solltemperatur:** Wertebereich 10–32 °C, Schrittweite 0,5 °C, eine Nachkommastelle. Es handelt sich um die Daikin-Geräte-Solltemperatur, **nicht** den extern gewünschten Raumsollwert.

In der Visualisierung oder direkt im Objektbaum auf diese Variablen zugreifen. Symcon `RequestAction` ruft das Modul auf; es sendet die entsprechenden Codes über `command/<hostname>/control`. Der angezeigte tatsächliche Zustand wird erst aus einer Daikin/Faikout-MQTT-Statusrückmeldung aktualisiert (keine optimistische Statusübernahme).

Profile: `FAIKOUT.Mode`, `FAIKOUT.Fan`, `FAIKOUT.TargetTemperature`.

## Funktionen
- Separate Instanz je Daikin-Innengerät; exakte MQTT-Topic-Zuordnung nach Hostname.
- Status-, Diagnose- und Einstellungswerte als Symcon-Variablen.
- Steuerbefehle über `command/<hostname>/control` (ohne Retain).
- **Herstellerunabhängige externe Temperaturquelle:** Eine beliebige numerische Symcon-Variable in °C (z. B. Homematic, KNX, Shelly, Modbus, Zigbee). Die vorherige Eigenschaft `SensorVariableID` bleibt erhalten, bestehende Auswahl muss nicht neu vorgenommen werden.
- Ein-/Ausschalten der externen Temperaturregelung über die Symcon-Schaltvariable **„Externe Temperaturregelung freigegeben (Symcon)”** oder über die Schaltflächen in der Instanzkonfiguration.
- Diagnose über einen optionalen Debug-Schalter.

## Installation und Konfiguration
1. Das Repository über die Symcon-9-Modulverwaltung installieren bzw. aktualisieren.
2. Für jedes Innengerät eine Instanz *Faikout Local* mit exaktem MQTT-Hostnamen anlegen.
3. In **Externe Raumtemperatur** die existierende Symcon-Temperaturvariable auswählen. Der Zahlenwert muss °C darstellen.
4. Sendeintervall (Standard 120 Sekunden) und maximales Messwertalter (Standard 1800 Sekunden) festlegen.
5. Änderungen übernehmen.
6. Nach Inbetriebnahme eines kompatiblen Faikout-Geräts die externe Regelung **ausdrücklich aktivieren** (Objektbaum-Schalter oder Schaltfläche). Standardmäßig bleibt sie **aus**.
7. Über „Einstellungen abfragen” die tatsächlich vom Gerät bestätigte Einstellung `TempAdjust` kontrollieren.

## Verhalten bei Aktivierung
- Aktivieren veröffentlicht auf `setting/<hostname>`: `{"autop":false,"autoptemp":0,"tempadjust":true}`.
- **Sicherheitsfreigabe:** Das Modul fragt die Faikout-Einstellungen zurück. Erst wenn die Antwort `autop=false`, `autoptemp=0` und `tempadjust=true` bestätigt, wird `Faikout-Einstellungen bestätigt` gesetzt und eine externe Temperatur sofort bzw. danach zyklisch als `{"env":21.3}` an `command/<hostname>/control` gesendet.
- Ist Faikout offline, bleibt die gewünschte Freigabe in Symcon gespeichert, aber **kein** `env` wird gesendet. Bei neuer Statusmeldung wird die Konfiguration erneut angefordert.
- Wenn die Rückmeldung ausbleibt oder unvollständig ist, bleibt die Übertragung gesperrt.
- Deaktivieren stoppt die zyklische Übertragung und sendet `{"autop":false,"autoptemp":0,"tempadjust":false}`.
- Die Symcon-Schaltvariable `Externe Temperaturregelung freigegeben (Symcon)` ist der **gewünschte** Zustand. Die separate Variable `Faikout-Einstellungen bestätigt` ist erst nach passender MQTT-Rückmeldung aktiv.
- Die zurückgemeldete Faikout-Einstellung `TempAdjust` wird getrennt nach Empfang einer `setting/<hostname>`-Nachricht angezeigt.
- Bei veralteten, nicht numerischen oder unplausiblen Temperaturen wird nichts gesendet.
- Der Schalter ist bei dieser Version bewusst **standardmäßig aus**, auch nach einem Upgrade von v0.1.1. So wird die automatische Ansteuerung erst nach expliziter Freigabe aktiviert.

## Schnittstellen für Symcon PHP
- `FAIKOUT_RequestStatus(12345);`
- `FAIKOUT_RequestSettings(12345);`
- `FAIKOUT_SetExternalReferenceEnabled(12345, true);`
- `FAIKOUT_SetExternalReferenceEnabled(12345, false);`
- `FAIKOUT_SetExternalTemperature(12345, 21.3);` (manuelle Testnachricht auch bei deaktivierter zyklischer Übertragung)
- `FAIKOUT_SendSensorValue(12345);` (nur wenn freigegeben)

## Tests
- `php -l FaikoutLocal/module.php`
- `php tests/test_module.php`
- GitHub Actions: PHP-Syntax, simulierte MQTT-/Schaltertests, JSON-Manifestprüfung.

## Einschränkungen
- Noch nicht mit realem Faikout oder unter belastetem Symcon 9 getestet.
- Statuswerte `Wh` und `comp` werden als Rohwerte übernommen. Bei Multi-Split nicht einfach summieren.
- Die tatsächliche Geräteakzeptanz der Profilbefehle muss mit echter Faikout-Hardware überprüft werden.
- MQTT-Will wird erkannt, aber ein eigener Offline-Timeout fehlt noch.
- Faikout behält seine internen Schutzfunktionen; die parallele Daikin-ONECTA-Schnittstelle wird nicht verändert.

Faikout-Protokolldokumentation: https://www.faikin.au/pages/faikin-faikout-mqtt-manual
