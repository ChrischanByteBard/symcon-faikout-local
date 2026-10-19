<?php
declare(strict_types=1);

/**
 * Independent local Faikout MQTT integration for Symcon 9.
 * Prototyp: real hardware verification required.
 */
class FaikoutLocal extends IPSModule
{
    private const MQTT_PARENT = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const MQTT_OUT = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    private const FIELDS = [
        'power' => ['Power', 'Eingeschaltet', 0, '~Switch', true],
        'mode' => ['Mode', 'Betriebsart', 3, 'FAIKOUT.Mode', true],
        'temp' => ['Temperature', 'Solltemperatur', 2, 'FAIKOUT.TargetTemperature', true],
        'home' => ['HomeTemperature', 'Raumtemperatur Daikin', 2, '~Temperature', false],
        'inlet' => ['InletTemperature', 'Ansaugtemperatur', 2, '~Temperature', false],
        'outside' => ['OutdoorTemperature', 'Außentemperatur', 2, '~Temperature', false],
        'liquid' => ['LiquidTemperature', 'Kältemitteltemperatur', 2, '~Temperature', false],
        'env' => ['ExternalTemperature', 'Externe Referenztemperatur', 2, '~Temperature', false],
        'heat' => ['HeatingActive', 'Heizbetrieb aktiv', 0, '~Switch', false],
        'online' => ['AirconOnline', 'Innengerät antwortet', 0, '~Switch', false],
        'control' => ['ExternalControl', 'Externe Regelung aktiv', 0, '~Switch', false],
        'fan' => ['Fan', 'Lüfterstufe', 3, 'FAIKOUT.Fan', true],
        'fanrpm' => ['FanRPM', 'Lüfterdrehzahl (U/min)', 1, '', false],
        'fanfreq' => ['FanFrequency', 'Lüfterfrequenz (Rohwert)', 2, '', false],
        'comp' => ['CompressorRaw', 'Kompressor (Rohwert)', 2, '', false],
        'Wh' => ['EnergyWh', 'Energiezähler Wh (Rohwert)', 2, '', false],
        'swingv' => ['SwingVertical', 'Lamellen vertikal', 0, '~Switch', true],
        'swingh' => ['SwingHorizontal', 'Lamellen horizontal', 0, '~Switch', true],
        'powerful' => ['Powerful', 'Powerful', 0, '~Switch', true],
        'econo' => ['Econo', 'Econo', 0, '~Switch', true],
        'streamer' => ['Streamer', 'Streamer', 0, '~Switch', true],
        'autop' => ['AutoPower', 'Faikout Auto Ein/Aus', 0, '~Switch', false],
        'autot' => ['AutoTarget', 'Faikout Auto Zieltemperatur', 2, '~Temperature', false],
        'autor' => ['AutoMargin', 'Faikout Toleranz', 2, '', false],
    ];

    private const SETTINGS = [
        'reporting' => ['ReportingSeconds', 'Statusintervall Sekunden', 1, '', false],
        'livestatus' => ['LiveStatus', 'Live-Status', 0, '~Switch', false],
        'haenable' => ['HomeAssistantDiscovery', 'HA-Discovery', 0, '~Switch', false],
        'tempadjust' => ['TempAdjust', 'Temperaturanpassung aktiv', 0, '~Switch', false],
        'autop' => ['SettingAutoPower', 'Automatisches Ein/Aus', 0, '~Switch', false],
        'autoptemp' => ['AutoPowerOffset', 'Auto-Ein/Aus-Offset K', 2, '', false],
        'tcontrol' => ['ControlTimeout', 'Temperatur-Timeout Sekunden', 1, '', false],
        'autotopic' => ['AutoTopic', 'MQTT Temperaturtopic', 3, '', false],
        'autopayload' => ['AutoPayload', 'JSON Temperaturfeld', 3, '', false],
    ];

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::MQTT_PARENT);
        $this->RegisterPropertyString('Hostname', '');
        $this->RegisterPropertyInteger('SensorVariableID', 0);
        $this->RegisterPropertyInteger('PublishIntervalSeconds', 120);
        $this->RegisterPropertyInteger('SensorMaxAgeSeconds', 1800);
        $this->RegisterPropertyBoolean('ImmediateUpdates', true);
        $this->RegisterPropertyFloat('ImmediateDeltaKelvin', 0.2);
        $this->RegisterPropertyInteger('ImmediateMinIntervalSeconds', 30);
        $this->RegisterAttributeInteger('SubscribedSensorVariableID', 0);
        $this->RegisterAttributeFloat('LastPublishedSensorValue', -999.0);
        $this->RegisterAttributeInteger('LastPublishedSensorAt', 0);
        $this->RegisterPropertyBoolean('DebugLogging', false);
        // Safe-by-default: never start remote temperature control until explicitly enabled.
        $this->RegisterAttributeBoolean('ExternalReferenceEnabled', false);
        $this->RegisterAttributeBoolean('ExternalReferencePending', false);
        $this->RegisterTimer('ExternalTemperatureTimer', 0, 'FAIKOUT_SendSensorValue($_IPS[\'TARGET\']);');
        $this->RegisterVariableBoolean('Connected', 'Faikout MQTT verbunden', '~Switch', 0);
        $this->RegisterVariableBoolean('ExternalReferenceEnabled', 'Externe Temperaturregelung freigegeben (Symcon)', '~Switch', 2);
        $this->EnableAction('ExternalReferenceEnabled');
        $this->RegisterVariableBoolean('ExternalReferenceReady', 'Faikout-Einstellungen bestätigt', '~Switch', 3);
        $this->RegisterVariableInteger('LastStatus', 'Letzter Status', '~UnixTimestamp', 1);
        $this->RegisterVariableString('RawStatus', 'Letzter Status JSON', '', 95);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 96);
    }

    private function ValidHostname(string $hostname): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/D', $hostname) === 1;
    }

    /** Debug messages are emitted only when explicitly enabled in the instance. */
    private function Trace(string $event, string $message): void
    {
        if (!$this->ReadPropertyBoolean('DebugLogging')) {
            return;
        }
        // Limit verbose MQTT payloads so an open Debug window stays responsive.
        $maxBytes = 2048;
        if (strlen($message) > $maxBytes) {
            $message = substr($message, 0, $maxBytes) . ' ... [truncated]';
        }
        $this->SendDebug($event, $message, 0);
    }

    /**
     * Create global module-owned profiles only once. The MQTT status still uses
     * original Faikout strings, preserving existing Symcon variable IDs.
     */
    private function EnsureControlProfiles(): void
    {
        $modes = [
            'A' => 'Automatik',
            'H' => 'Heizen',
            'C' => 'Kühlen',
            'D' => 'Entfeuchten',
            'F' => 'Lüften'
        ];
        $fans = [
            'A' => 'Automatik',
            'Q' => 'Leise / Nacht',
            '1' => 'Stufe 1',
            '2' => 'Stufe 2',
            '3' => 'Stufe 3',
            '4' => 'Stufe 4',
            '5' => 'Stufe 5'
        ];
        foreach (['FAIKOUT.Mode' => $modes, 'FAIKOUT.Fan' => $fans] as $name => $options) {
            if (IPS_VariableProfileExists($name)) {
                continue;
            }
            IPS_CreateVariableProfile($name, VARIABLETYPE_STRING);
            foreach ($options as $value => $caption) {
                IPS_SetVariableProfileAssociation($name, (string)$value, $caption, '', -1);
            }
        }
        if (!IPS_VariableProfileExists('FAIKOUT.TargetTemperature')) {
            IPS_CreateVariableProfile('FAIKOUT.TargetTemperature', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('FAIKOUT.TargetTemperature', '', ' °C');
            IPS_SetVariableProfileDigits('FAIKOUT.TargetTemperature', 1);
            IPS_SetVariableProfileValues('FAIKOUT.TargetTemperature', 10, 32, 0.5);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnsureControlProfiles();
        $hostname = $this->ReadPropertyString('Hostname');
        // Update message subscription whenever the sensor selection changes.
        $previousSensorId = $this->ReadAttributeInteger('SubscribedSensorVariableID');
        $sensorId = $this->ReadPropertyInteger('SensorVariableID');
        if ($previousSensorId > 0 && $previousSensorId !== $sensorId) {
            $this->UnregisterMessage($previousSensorId, VM_UPDATE);
        }
        if ($sensorId > 0 && IPS_VariableExists($sensorId)) {
            $this->RegisterMessage($sensorId, VM_UPDATE);
        }
        if ($previousSensorId !== $sensorId) {
            $this->WriteAttributeFloat('LastPublishedSensorValue', -999.0);
            $this->WriteAttributeInteger('LastPublishedSensorAt', 0);
        }
        $this->WriteAttributeInteger('SubscribedSensorVariableID', $sensorId);
        // Device response is required after each module reload.
        $this->WriteAttributeBoolean('ExternalReferencePending', false);
        $this->SetValue('ExternalReferenceReady', false);
        $this->SetTimerInterval('ExternalTemperatureTimer', 0);
        if (!$this->ValidHostname($hostname)) {
            $this->SetReceiveDataFilter('(?!)');
            $this->SetTimerInterval('ExternalTemperatureTimer', 0);
            $this->SetValue('ExternalReferenceEnabled', $this->ReadAttributeBoolean('ExternalReferenceEnabled'));
            return;
        }
        $this->SetReceiveDataFilter('.*' . preg_quote($hostname, '/') . '.*');
        $this->SetValue('ExternalReferenceEnabled', $this->ReadAttributeBoolean('ExternalReferenceEnabled'));
        // Timer is armed only after Faikout confirms remote settings.
    }

    /**
     * Receive only updates from the selected room sensor. Periodic publishing
     * remains independent from this event-driven fast path.
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE ||
            $SenderID !== $this->ReadPropertyInteger('SensorVariableID') ||
            !$this->ReadPropertyBoolean('ImmediateUpdates') ||
            !$this->ReadAttributeBoolean('ExternalReferenceEnabled') ||
            !$this->GetValue('ExternalReferenceReady')) {
            return;
        }
        $id = $this->ReadPropertyInteger('SensorVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return;
        }
        $value = GetValue($id);
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return;
        }
        // The delta is compared to the last value actually sent via MQTT.
        $previous = $this->ReadAttributeFloat('LastPublishedSensorValue');
        $threshold = max(0.1, $this->ReadPropertyFloat('ImmediateDeltaKelvin'));
        if ($previous !== -999.0 && abs((float)$value - $previous) + 0.000001 < $threshold) {
            return;
        }
        $lastSent = $this->ReadAttributeInteger('LastPublishedSensorAt');
        $minSeconds = max(10, $this->ReadPropertyInteger('ImmediateMinIntervalSeconds'));
        if ($lastSent > 0 && time() - $lastSent < $minSeconds) {
            return;
        }
        $this->Trace('Temperature event', 'Relevant room-temperature update: ' . (string)$value);
        $this->SendSensorValue();
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Verbindung', 'bold' => true],
                ['type' => 'ValidationTextBox', 'name' => 'Hostname', 'caption' => 'MQTT-Gerätename (Faikout-Hostname)'],
                ['type' => 'Label', 'caption' => 'Entspricht dem Topic state/<hostname>. Groß-/Kleinschreibung beachten.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Externe Raumtemperatur', 'expanded' => true, 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'SensorVariableID', 'caption' => 'Raumtemperatur (Symcon-Variable, °C)'],
                    ['type' => 'Label', 'caption' => 'Beliebige numerische Temperaturvariable in °C, z. B. Homematic, KNX, Zigbee, Shelly oder Modbus.'],
                    ['type' => 'NumberSpinner', 'name' => 'PublishIntervalSeconds', 'caption' => 'Sendeintervall in Sekunden (mindestens 30)'],
                    ['type' => 'NumberSpinner', 'name' => 'SensorMaxAgeSeconds', 'caption' => 'Maximales Messwertalter in Sekunden'],
                    ['type' => 'CheckBox', 'name' => 'ImmediateUpdates', 'caption' => 'Bei relevanten Temperaturänderungen zusätzlich sofort senden'],
                    ['type' => 'NumberSpinner', 'name' => 'ImmediateDeltaKelvin', 'caption' => 'Mindeständerung zur zuletzt gesendeten Temperatur (K, mindestens 0,1)'],
                    ['type' => 'NumberSpinner', 'name' => 'ImmediateMinIntervalSeconds', 'caption' => 'Mindestabstand zwischen Sofortmeldungen in Sekunden (mindestens 10)'],
                    ['type' => 'Label', 'caption' => 'Empfohlen: 0,2 K und 30 Sekunden. Zyklische Übertragung bleibt unabhängig aktiv (Standard 120 Sekunden).'],
                    ['type' => 'Label', 'caption' => 'Die Temperatur wird nur bei aktivierter Regelung zyklisch gesendet. Veraltete oder unplausible Werte werden verworfen.'],
                    ['type' => 'Label', 'caption' => 'Freigabe über die Schaltvariable "Externe Temperaturregelung freigegeben (Symcon)" im Objektbaum.'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Diagnose', 'expanded' => false, 'items' => [
                    ['type' => 'CheckBox', 'name' => 'DebugLogging', 'caption' => 'MQTT-Nachrichten im Instanz-Debug protokollieren'],
                    ['type' => 'Label', 'caption' => 'Nur bei Fehlersuche einschalten: erzeugt zusätzliche Debug-Ausgaben.'],
                ]],
            ],
            'actions' => [
                ['type' => 'Label', 'caption' => 'Eine Freigabe sendet autop=false, autoptemp=0, tempadjust=true an Faikout und aktiviert die Temperaturübertragung.'],
                ['type' => 'Label', 'caption' => 'Eine Sperre stoppt die Temperaturübertragung und sendet tempadjust=false (sowie autop=false, autoptemp=0).'],
                ['type' => 'Label', 'caption' => 'Die Freigabevariable beschreibt den Symcon-Zustand, nicht die bestätigte Faikout-Einstellung. Bitte bei Bedarf Einstellungen abfragen.'],
                ['type' => 'Button', 'caption' => 'Externe Temperaturregelung aktivieren', 'onClick' => 'FAIKOUT_SetExternalReferenceEnabled($id, true);'],
                ['type' => 'Button', 'caption' => 'Externe Temperaturregelung deaktivieren', 'onClick' => 'FAIKOUT_SetExternalReferenceEnabled($id, false);'],
                ['type' => 'Button', 'caption' => 'Status abfragen', 'onClick' => 'FAIKOUT_RequestStatus($id);'],
                ['type' => 'Button', 'caption' => 'Einstellungen abfragen', 'onClick' => 'FAIKOUT_RequestSettings($id);'],
                ['type' => 'Button', 'caption' => 'Raumtemperatur jetzt senden', 'onClick' => 'FAIKOUT_SendSensorValue($id);'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ReceiveData($JSONString)
    {
        $packet = json_decode($JSONString, true);
        if (!is_array($packet) || !is_string($packet['Topic'] ?? null)) {
            return;
        }
        $hostname = $this->ReadPropertyString('Hostname');
        $parts = explode('/', $packet['Topic']);
        if (!$this->ValidHostname($hostname) || count($parts) < 2 ||
            $parts[1] !== $hostname ||
            !in_array($parts[0], ['state', 'setting', 'info', 'error'], true)) {
            return;
        }
        $raw = $packet['Payload'] ?? null;
        if (!is_string($raw)) {
            return;
        }
        $class = $parts[0];
        $this->Trace('MQTT RX Topic', $packet['Topic']);
        $this->Trace('MQTT RX Payload', $raw);
        if ($class === 'state' && count($parts) === 2 && trim($raw) === 'false') {
            $this->SetValue('Connected', false);
            $this->DisarmReference();
            $this->Trace('Connection', 'Faikout MQTT Will: offline');
            return;
        }
        $data = json_decode($raw, true);
        if ($class === 'state' && is_array($data)) {
            $this->SetValue('Connected', true);
            $this->SetValue('LastStatus', time());
            $this->SetValue('RawStatus', $raw);
            $this->UpdateFields($data, self::FIELDS);
            if ($this->ReadAttributeBoolean('ExternalReferenceEnabled') &&
                !$this->GetValue('ExternalReferenceReady') &&
                !$this->ReadAttributeBoolean('ExternalReferencePending')) {
                $this->ArmReference();
            }
        } elseif ($class === 'setting') {
            if (is_array($data)) {
                $this->UpdateFields($data, self::SETTINGS);
                $this->InspectRemoteSettings($data);
            } elseif (count($parts) === 3) {
                $this->UpdateFields([$parts[2] => $data ?? $raw], self::SETTINGS);
            }
        } elseif ($class === 'error') {
            $this->SetValue('LastError', $raw);
            $this->Trace('Faikout Error', $raw);
        } elseif ($class === 'info' && is_array($data) && is_scalar($data['version'] ?? null)) {
            $this->MaintainVariable('FirmwareVersion', 'Faikout Firmware', VARIABLETYPE_STRING, '', 70, true);
            $this->SetValue('FirmwareVersion', (string) $data['version']);
        }
    }

    private function UpdateFields(array $data, array $mapping): void
    {
        foreach ($mapping as $key => $field) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            [$ident, $caption, $type, $profile, $write] = $field;
            $value = $data[$key];
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            if ($type === VARIABLETYPE_BOOLEAN && !is_bool($value)) {
                continue;
            }
            if ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    continue;
                }
                $value = $type === VARIABLETYPE_INTEGER ? (int) $value : (float) $value;
            }
            if ($type === VARIABLETYPE_STRING && !is_scalar($value)) {
                continue;
            }
            $this->MaintainVariable($ident, $caption, $type, $profile, 20, true);
            if ($write) {
                $this->EnableAction($ident);
            }
            $this->SetValue($ident, $type === VARIABLETYPE_STRING ? (string) $value : $value);
        }
    }

    private function Publish(string $topic, string $payload): bool
    {
        $json = json_encode([
            'DataID' => self::MQTT_OUT,
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $topic,
            'Payload' => $payload,
        ]);
        if ($json === false) {
            $this->Trace('MQTT TX Error', 'Could not encode MQTT packet for ' . $topic);
            return false;
        }
        $this->Trace('MQTT TX Topic', $topic);
        $this->Trace('MQTT TX Payload', $payload);
        $result = $this->SendDataToParent($json);
        if ($result === false) {
            $this->Trace('MQTT TX Error', 'SendDataToParent failed for ' . $topic);
            return false;
        }
        return true;
    }

    private function SendControl(array $command): bool
    {
        $hostname = $this->ReadPropertyString('Hostname');
        $json = json_encode($command, JSON_PRESERVE_ZERO_FRACTION);
        return $this->ValidHostname($hostname) && $json !== false &&
            $this->Publish('command/' . $hostname . '/control', $json);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'ExternalReferenceEnabled') {
            if (!is_bool($Value)) {
                throw new InvalidArgumentException('Boolean value expected');
            }
            if (!$this->SetExternalReferenceEnabled($Value)) {
                throw new RuntimeException('Unable to publish external temperature control setting');
            }
            return;
        }
        $keys = [
            'Power' => 'power', 'Mode' => 'mode', 'Temperature' => 'temp',
            'Fan' => 'fan', 'SwingVertical' => 'swingv', 'SwingHorizontal' => 'swingh',
            'Powerful' => 'powerful', 'Econo' => 'econo', 'Streamer' => 'streamer',
        ];
        if (!array_key_exists($Ident, $keys)) {
            throw new InvalidArgumentException('Unsupported action: ' . $Ident);
        }
        if (in_array($Ident, ['Power','SwingVertical','SwingHorizontal','Powerful','Econo','Streamer'], true)) {
            if (!is_bool($Value)) {
                throw new InvalidArgumentException('Boolean expected');
            }
        } elseif ($Ident === 'Mode') {
            if (!in_array($Value, ['H', 'C', 'A', 'D', 'F'], true)) {
                throw new InvalidArgumentException('Invalid mode');
            }
        } elseif ($Ident === 'Fan') {
            if (!in_array((string) $Value, ['A', 'Q', '1', '2', '3', '4', '5'], true)) {
                throw new InvalidArgumentException('Invalid fan');
            }
            $Value = (string) $Value;
        } elseif ($Ident === 'Temperature') {
            if (!is_numeric($Value) || (float) $Value < 10 || (float) $Value > 32) {
                throw new InvalidArgumentException('Invalid temperature');
            }
            $Value = (float) $Value;
        }
        if (!$this->SendControl([$keys[$Ident] => $Value])) {
            throw new RuntimeException('MQTT send failed');
        }
    }

    private function DisarmReference(): void
    {
        $this->SetValue('ExternalReferenceReady', false);
        $this->WriteAttributeBoolean('ExternalReferencePending', false);
        $this->SetTimerInterval('ExternalTemperatureTimer', 0);
    }

    /**
     * Configure safe remote behaviour; do not send env until the response confirms it.
     */
    private function ArmReference(): bool
    {
        $hostname = $this->ReadPropertyString('Hostname');
        if (!$this->ValidHostname($hostname)) {
            return false;
        }
        $settings = json_encode(['autop' => false, 'autoptemp' => 0, 'tempadjust' => true]);
        if ($settings === false) {
            return false;
        }
        $this->WriteAttributeBoolean('ExternalReferencePending', true);
        if (!$this->Publish('setting/' . $hostname, $settings) ||
            !$this->Publish('setting/' . $hostname, '')) {
            $this->WriteAttributeBoolean('ExternalReferencePending', false);
            return false;
        }
        $this->Trace('External Reference', 'Awaiting Faikout settings confirmation');
        return true;
    }

    private function InspectRemoteSettings(array $settings): void
    {
        if (!$this->ReadAttributeBoolean('ExternalReferenceEnabled')) {
            return;
        }
        if (array_key_exists('tempadjust', $settings) && $settings['tempadjust'] === false) {
            $this->DisarmReference();
            return;
        }
        if (!array_key_exists('autop', $settings) ||
            !array_key_exists('autoptemp', $settings) ||
            !array_key_exists('tempadjust', $settings)) {
            return;
        }
        $safe = $settings['autop'] === false &&
            is_numeric($settings['autoptemp']) &&
            (float) $settings['autoptemp'] === 0.0 &&
            $settings['tempadjust'] === true;
        if (!$safe) {
            $this->DisarmReference();
            $this->Trace('External Reference', 'Remote settings unsafe; external reference blocked');
            return;
        }
        if (!$this->ReadAttributeBoolean('ExternalReferencePending') &&
            !$this->GetValue('ExternalReferenceReady')) {
            return;
        }
        if (!$this->GetValue('Connected')) {
            return;
        }
        $this->WriteAttributeBoolean('ExternalReferencePending', false);
        $this->SetValue('ExternalReferenceReady', true);
        $interval = max(30, $this->ReadPropertyInteger('PublishIntervalSeconds'));
        $this->SetTimerInterval(
            'ExternalTemperatureTimer',
            $this->ReadPropertyInteger('SensorVariableID') > 0 ? $interval * 1000 : 0
        );
        $this->Trace('External Reference', 'Faikout settings confirmed; external reference enabled');
        $this->SendSensorValue();
    }

    /**
     * Local desired state; the device's actual settings are read back separately.
     */
    public function SetExternalReferenceEnabled(bool $enabled): bool
    {
        $hostname = $this->ReadPropertyString('Hostname');
        if (!$this->ValidHostname($hostname)) {
            $this->Trace('Control Error', 'Faikout hostname is not configured');
            return false;
        }
        if ($enabled) {
            $id = $this->ReadPropertyInteger('SensorVariableID');
            if ($id <= 0 || !IPS_VariableExists($id)) {
                $this->Trace('Control Error', 'Please select a valid numeric room temperature variable first');
                return false;
            }
        }
        $this->DisarmReference();
        $this->WriteAttributeBoolean('ExternalReferenceEnabled', $enabled);
        $this->SetValue('ExternalReferenceEnabled', $enabled);
        if ($enabled) {
            // Remember the desired state while offline. Re-negotiate upon state update.
            return !$this->GetValue('Connected') || $this->ArmReference();
        }
        $payload = json_encode(['autop' => false, 'autoptemp' => 0, 'tempadjust' => false]);
        return $payload !== false && $this->Publish('setting/' . $hostname, $payload);
    }

    public function SetExternalTemperature(float $temperature): bool
    {
        return is_finite($temperature) && $temperature >= 5 && $temperature <= 40
            && $this->SendControl(['env' => round($temperature, 1)]);
    }

    public function SendSensorValue(): bool
    {
        if (!$this->ReadAttributeBoolean('ExternalReferenceEnabled') ||
            !$this->GetValue('ExternalReferenceReady')) {
            $this->Trace('Temperature skip', 'Reference disabled or Faikout settings not confirmed');
            return false;
        }
        $id = $this->ReadPropertyInteger('SensorVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $this->Trace('Temperature skip', 'No valid temperature variable configured');
            return false;
        }
        $meta = IPS_GetVariable($id);
        $updated = (int) ($meta['VariableUpdated'] ?? 0);
        $maxAge = max(60, $this->ReadPropertyInteger('SensorMaxAgeSeconds'));
        if ($updated <= 0 || $updated > time() || time() - $updated > $maxAge) {
            $this->Trace('Temperature skip', 'Sensor value is stale or timestamp invalid');
            return false;
        }
        $value = GetValue($id);
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 5 || (float) $value > 40) {
            $this->Trace('Temperature skip', 'Sensor value is invalid or outside 5-40 C');
            return false;
        }
        $rounded = round((float) $value, 1);
        if (!$this->SetExternalTemperature($rounded)) {
            return false;
        }
        $this->WriteAttributeFloat('LastPublishedSensorValue', $rounded);
        $this->WriteAttributeInteger('LastPublishedSensorAt', time());
        return true;
    }

    public function RequestStatus(): bool
    {
        $host = $this->ReadPropertyString('Hostname');
        return $this->ValidHostname($host) && $this->Publish('command/' . $host . '/status', '');
    }

    public function RequestSettings(): bool
    {
        $host = $this->ReadPropertyString('Hostname');
        return $this->ValidHostname($host) && $this->Publish('setting/' . $host, '');
    }
}
