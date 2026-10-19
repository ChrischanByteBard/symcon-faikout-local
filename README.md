# Faikout Local – Symcon 9.0

**Entwicklungsprototyp v0.2.0. Nicht für produktiven Heizbetrieb freigegeben.**

Eigenständige MQTT-Integration für die lokale Steuerung kompatibler Daikin-Faikout-Geräte über den integrierten Symcon-MQTT-Server. Kein Fork des archivierten Moduls *Faikin 4 Daikin*. Eigene Modul-IDs.

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
- Eine gültige externe Temperatur wird anschließend sofort und zyklisch als `{"env":21.3}` auf `command/<hostname>/control` veröffentlicht.
- Deaktivieren stoppt die zyklische Übertragung und sendet `{"autop":false,"autoptemp":0,"tempadjust":false}`.
- Die Symcon-Freigabe bestätigt nur die **lokale MQTT-Veröffentlichung**, **nicht** die Verarbeitung durch das Faikout-Gerät.
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
- Bedienprofile für Betriebsart/Lüfter fehlen noch.
- MQTT-Will wird erkannt, aber ein eigener Offline-Timeout fehlt noch.
- Faikout behält seine internen Schutzfunktionen; die parallele Daikin-ONECTA-Schnittstelle wird nicht verändert.

Faikout-Protokolldokumentation: https://www.faikin.au/pages/faikin-faikout-mqtt-manual
