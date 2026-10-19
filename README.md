# Faikout Local – IP-Symcon 9.0

**Entwicklungsprototyp v0.1.1. Nicht für produktiven Heizbetrieb freigegeben.**

Eigenständiges Symcon-Modul zur lokalen Kommunikation mit Daikin-Faikout über den vorhandenen Symcon-MQTT-Server. Kein Fork des archivierten, nicht ausdrücklich lizenzierten Originalmoduls „Faikin 4 Daikin“. Neue Modul-IDs, keine automatische Migration des alten Moduls.

## Diagnose in v0.1.1

In der Geräteinstanz den Schalter **MQTT-Diagnoseausgaben im Debug-Fenster aktivieren** einschalten und anschließend **Übernehmen**. Im Instanz-Debug erscheinen dann `MQTT RX Topic`, `MQTT RX Payload`, `MQTT TX Topic`, `MQTT TX Payload`, Verbindungs-/Fehlermeldungen und Hinweise, wenn ein externer Homematic-Messwert nicht gesendet wird. Der Schalter ist standardmäßig aus; für eine einfache Kontrolle zunächst eine simulierte Statusnachricht über MQTT Explorer auf `state/wohnzimmer` senden. Danach Diagnose wieder deaktivieren, um unnötige Meldungen zu vermeiden.

## Funktionen
- Drei Innengeräte möglich: eine Instanz je Gerät und strikte MQTT-Topic-Zuordnung nach Hostname.
- Status, Einstellungswerte und Diagnose über die aktuellen Faikout-MQTT-Topics.
- Rohdaten wie Temperatur, Lüfterdrehzahl, Verdichterwert und Wh als Symcon-Variablen; Archivierung separat aktivieren.
- Steuerbefehle als nicht-retained JSON auf command/HOSTNAME/control.
- Bestehende Homematic-Raumtemperatur als externe Referenz per MQTT: {"env":21.3}.
- Plausibilitätsprüfung der Temperatur und Aktualitätskontrolle des Homematic-Messwertes.
- Getrennte Statusvariablen für Faikout-MQTT-Verbindung und Daikin-Kommunikation.

## Installation nur auf Testsystem
1. GitHub-Repository in Symcon 9 Modulverwaltung hinzufügen und den Branch develop/faikout-v0.1.0 wählen.
2. Instanz „Faikout Local“ je Innengerät hinzufügen und Symcon-MQTT-Server zuordnen.
3. Hostname auf den exakten MQTT-Gerätenamen setzen (z. B. wohnzimmer, kueche, schlafzimmer).
4. Optional bestehende Homematic-Isttemperaturvariable auswählen, Temperaturintervall konfigurieren.
5. Faikout separat für externe Regelung einstellen: tempadjust=true, autop=false, autoptemp=0.
6. In Symcon für relevante Variablen Archivierung aktivieren.

PHP-Aufrufe: FAIKOUT_RequestStatus(12345); FAIKOUT_RequestSettings(12345); FAIKOUT_SetExternalTemperature(12345, 21.3); FAIKOUT_SendSensorValue(12345).

## Tests
- php -l FaikoutLocal/module.php
- php tests/test_module.php

Die Tests verwenden einen simulierten Symcon-MQTT-Server. Die Entwicklung wurde nicht auf einem laufenden Symcon 9.0 oder mit echten Faikout-Geräten getestet.

## Bekannte Einschränkungen
- Mode und Fan sind vorerst Rohwert-String-Variablen; Auswahlprofile fehlen.
- Wh und comp sind Rohwerte; Werte dreier Multi-Split-Innengeräte nicht ungeprüft aufsummieren.
- MQTT-Will wird erkannt, aber noch keine verlässliche Offline-Erkennung bei ausbleibenden Statusmeldungen.
- Rückfall bei fehlender externer Sensortemperatur muss mit aktueller Firmware überprüft werden.
- Tatsächliche Faikout-Firmware- und Gerätekompatibilität noch nicht bestätigt.
- Daikin ONECTA bleibt unabhängig von diesem Modul.

Protokolldokumentation: https://www.faikin.au/pages/faikin-faikout-mqtt-manual
