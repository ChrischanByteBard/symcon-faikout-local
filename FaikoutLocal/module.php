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
        'mode' => ['Mode', 'Betriebsart (Rohwert)', 3, '', true],
        'temp' => ['Temperature', 'Solltemperatur', 2, '~Temperature', true],
        'home' => ['HomeTemperature', 'Raumtemperatur Daikin', 2, '~Temperature', false],
        'inlet' => ['InletTemperature', 'Ansaugtemperatur', 2, '~Temperature', false],
        'outside' => ['OutdoorTemperature', 'Außentemperatur', 2, '~Temperature', false],
        'liquid' => ['LiquidTemperature', 'Kältemitteltemperatur', 2, '~Temperature', false],
        'env' => ['ExternalTemperature', 'Externe Referenztemperatur', 2, '~Temperature', false],
        'heat' => ['HeatingActive', 'Heizbetrieb aktiv', 0, '~Switch', false],
        'online' => ['AirconOnline', 'Innengerät antwortet', 0, '~Switch', false],
        'control' => ['ExternalControl', 'Externe Regelung aktiv', 0, '~Switch', false],
        'fan' => ['Fan', 'Lüfterstufe (Rohwert)', 3, '', true],
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
        $this->RegisterPropertyBoolean('DebugLogging', false);
        // Safe-by-default: never start remote temperature control until explicitly enabled.
        $this->RegisterAttributeBoolean('ExternalReferenceEnabled', false);
        $this->RegisterTimer('ExternalTemperatureTimer', 0, 'FAIKOUT_SendSensorValue($_IPS[\'TARGET\']);');
        $this->RegisterVariableBoolean('Connected', 'Faikout MQTT verbunden', '~Switch', 0);
        $this->RegisterVariableBoolean('ExternalReferenceEnabled', 'Externe Temperaturregelung freigegeben (Symcon)', '~Switch', 2);
        $this->EnableAction('ExternalReferenceEnabled');
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

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $hostname = $this->ReadPropertyString('Hostname');
        if (!$this->ValidHostname($hostname)) {
            $this->SetReceiveDataFilter('(?!)');
            $this->SetTimerInterval('ExternalTemperatureTimer', 0);
            $this->SetValue('ExternalReferenceEnabled', $this->ReadAttributeBoolean('ExternalReferenceEnabled'));
            return;
        }
        $this->SetReceiveDataFilter('.*' . preg_quote($hostname, '/') . '.*');
        $this->SetValue('ExternalReferenceEnabled', $this->ReadAttributeBoolean('ExternalReferenceEnabled'));
        $seconds = max(30, $this->ReadPropertyInteger('PublishIntervalSeconds'));
        $this->SetTimerInterval(
            'ExternalTemperatureTimer',
            ($this->ReadAttributeBoolean('ExternalReferenceEnabled') && $this->ReadPropertyInteger('SensorVariableID') > 0) ? $seconds * 1000 : 0
        );
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
            $this->Trace('Connection', 'Faikout MQTT Will: offline');
            return;
        }
        $data = json_decode($raw, true);
        if ($class === 'state' && is_array($data)) {
            $this->SetValue('Connected', true);
            $this->SetValue('LastStatus', time());
            $this->SetValue('RawStatus', $raw);
            $this->UpdateFields($data, self::FIELDS);
        } elseif ($class === 'setting') {
            if (is_array($data)) {
                $this->UpdateFields($data, self::SETTINGS);
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

    /**
     * Control both local temperature forwarding and Faikout's temperature adjustment.
     * The local state confirms MQTT publication, not acceptance by the remote device.
     * The Faikout-reported TempAdjust value remains a separate read-only status.
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

        // Prevent auto power switching in either state.
        $payload = json_encode([
            'autop' => false,
            'autoptemp' => 0,
            'tempadjust' => $enabled,
        ]);
        if ($payload === false || !$this->Publish('setting/' . $hostname, $payload)) {
            return false;
        }

        $this->WriteAttributeBoolean('ExternalReferenceEnabled', $enabled);
        $this->SetValue('ExternalReferenceEnabled', $enabled);
        $interval = max(30, $this->ReadPropertyInteger('PublishIntervalSeconds'));
        $this->SetTimerInterval(
            'ExternalTemperatureTimer',
            ($enabled && $this->ReadPropertyInteger('SensorVariableID') > 0) ? $interval * 1000 : 0
        );
        $this->Trace('External Reference', $enabled
            ? 'Enabled locally; settings published, remote confirmation pending'
            : 'Disabled locally; settings published, remote confirmation pending');
        // Deliver a fresh value immediately when enabled and available.
        if ($enabled) {
            $this->SendSensorValue();
        }
        return true;
    }

    public function SetExternalTemperature(float $temperature): bool
    {
        return is_finite($temperature) && $temperature >= 5 && $temperature <= 40
            && $this->SendControl(['env' => round($temperature, 1)]);
    }

    public function SendSensorValue(): bool
    {
        if (!$this->ReadAttributeBoolean('ExternalReferenceEnabled')) {
            $this->Trace('Temperature skip', 'External temperature transmission disabled in Symcon');
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
        return $this->SetExternalTemperature((float) $value);
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
