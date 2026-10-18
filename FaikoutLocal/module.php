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
        $this->RegisterTimer('ExternalTemperatureTimer', 0, 'FAIKOUT_SendSensorValue($_IPS[\'TARGET\']);');
        $this->RegisterVariableBoolean('Connected', 'Faikout MQTT verbunden', '~Switch', 0);
        $this->RegisterVariableInteger('LastStatus', 'Letzter Status', '~UnixTimestamp', 1);
        $this->RegisterVariableString('RawStatus', 'Letzter Status JSON', '', 95);
        $this->RegisterVariableString('LastError', 'Letzter Fehler', '', 96);
    }

    private function ValidHostname(string $hostname): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/D', $hostname) === 1;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $hostname = $this->ReadPropertyString('Hostname');
        if (!$this->ValidHostname($hostname)) {
            $this->SetReceiveDataFilter('(?!)');
            $this->SetTimerInterval('ExternalTemperatureTimer', 0);
            return;
        }
        $this->SetReceiveDataFilter('.*' . preg_quote($hostname, '/') . '.*');
        $seconds = max(30, $this->ReadPropertyInteger('PublishIntervalSeconds'));
        $this->SetTimerInterval(
            'ExternalTemperatureTimer',
            $this->ReadPropertyInteger('SensorVariableID') > 0 ? $seconds * 1000 : 0
        );
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'ValidationTextBox', 'name' => 'Hostname', 'caption' => 'Faikout MQTT Hostname'],
                ['type' => 'SelectVariable', 'name' => 'SensorVariableID', 'caption' => 'Homematic Isttemperatur'],
                ['type' => 'NumberSpinner', 'name' => 'PublishIntervalSeconds', 'caption' => 'Temperaturintervall Sekunden (min. 30)'],
                ['type' => 'NumberSpinner', 'name' => 'SensorMaxAgeSeconds', 'caption' => 'Maximales Messwertalter Sekunden'],
            ],
            'actions' => [
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
        if ($class === 'state' && count($parts) === 2 && trim($raw) === 'false') {
            $this->SetValue('Connected', false);
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
        return $json !== false && $this->SendDataToParent($json) !== false;
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

    public function SetExternalTemperature(float $temperature): bool
    {
        return is_finite($temperature) && $temperature >= 5 && $temperature <= 40
            && $this->SendControl(['env' => round($temperature, 1)]);
    }

    public function SendSensorValue(): bool
    {
        $id = $this->ReadPropertyInteger('SensorVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $meta = IPS_GetVariable($id);
        $updated = (int) ($meta['VariableUpdated'] ?? 0);
        $maxAge = max(60, $this->ReadPropertyInteger('SensorMaxAgeSeconds'));
        if ($updated <= 0 || $updated > time() || time() - $updated > $maxAge) {
            return false;
        }
        $value = GetValue($id);
        return is_numeric($value) && $this->SetExternalTemperature((float) $value);
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
