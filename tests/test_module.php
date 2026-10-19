<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VM_UPDATE = 10603;
const VARIABLE_PRESENTATION_ENUMERATION = 'P_ENUM';
const VARIABLE_PRESENTATION_SLIDER = 'P_SLIDER';
const VARIABLE_PRESENTATION_SWITCH = 'P_SWITCH';
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = 'P_VALUE';
const VARIABLE_PRESENTATION_DATE_TIME = 'P_DATE_TIME';

$GLOBALS['sensor'] = [12345 => ['updated' => time(), 'value' => 21.25]];
function IPS_VariableExists($id) { return isset($GLOBALS['sensor'][$id]); }
function IPS_GetVariable($id) { return ['VariableUpdated' => $GLOBALS['sensor'][$id]['updated']]; }
function GetValue($id) { return $GLOBALS['sensor'][$id]['value']; }

class IPSModule
{
    public array $properties = [], $attributes = [], $values = [], $timers = [], $sent = [], $debug = [], $subscriptions = [], $variables = [];
    public function Create() {}
    public function ApplyChanges() {}
    public function ConnectParent($x) {}
    public function RegisterPropertyString($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyInteger($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyBoolean($n, $v) { $this->properties[$n] = $v; }
    public function RegisterPropertyFloat($n, $v) { $this->properties[$n] = $v; }
    public function ReadPropertyFloat($n) { return (float)$this->properties[$n]; }
    public function ReadPropertyString($n) { return (string)$this->properties[$n]; }
    public function ReadPropertyInteger($n) { return (int)$this->properties[$n]; }
    public function ReadPropertyBoolean($n) { return (bool)$this->properties[$n]; }
    public function RegisterAttributeBoolean($n, $v) { $this->attributes[$n] = $v; }
    public function RegisterAttributeFloat($n, $v) { $this->attributes[$n] = $v; }
    public function ReadAttributeFloat($n) { return (float)$this->attributes[$n]; }
    public function WriteAttributeFloat($n, $v) { $this->attributes[$n] = (float)$v; }
    public function RegisterAttributeInteger($n, $v) { $this->attributes[$n] = $v; }
    public function ReadAttributeInteger($n) { return (int)$this->attributes[$n]; }
    public function WriteAttributeInteger($n, $v) { $this->attributes[$n] = (int)$v; }
    public function ReadAttributeBoolean($n) { return (bool)$this->attributes[$n]; }
    public function WriteAttributeBoolean($n, $v) { $this->attributes[$n] = $v; }
    public function RegisterTimer($n, $v, $s) { $this->timers[$n] = $v; }
    public function SetTimerInterval($n, $v) { $this->timers[$n] = $v; }
    public function SetReceiveDataFilter($f) {}
    public function RegisterMessage($id, $msg) { $this->subscriptions[$id][$msg] = true; }
    public function UnregisterMessage($id, $msg) { unset($this->subscriptions[$id][$msg]); }
    public function RegisterVariableBoolean($n, $c, $p, $i) {
        $this->values[$n] = false;
        $this->variables[$n] = ['caption'=>$c,'type'=>VARIABLETYPE_BOOLEAN,'profile'=>$p];
    }
    public function RegisterVariableInteger($n, $c, $p, $i) {
        $this->values[$n] = 0;
        $this->variables[$n] = ['caption'=>$c,'type'=>VARIABLETYPE_INTEGER,'profile'=>$p];
    }
    public function RegisterVariableString($n, $c, $p, $i) { $this->values[$n] = ''; }
    public function MaintainVariable($n, $c, $t, $p, $i, $m) {$this->variables[$n] = ['caption'=>$c, 'type'=>$t, 'profile'=>$p];}
    public function EnableAction($n) {}
    public function SetValue($n, $v) { $this->values[$n] = $v; }
    public function GetValue($n) { return $this->values[$n] ?? null; }
    public function SendDataToParent($j) { $this->sent[] = json_decode($j, true); return 'ok'; }
    public function SendDebug($e, $m, $l) { $this->debug[] = [$e, $m, $l]; }
}

require dirname(__DIR__) . '/FaikoutLocal/module.php';

function check($x, $msg) { if (!$x) throw new RuntimeException($msg); echo "PASS: $msg\n"; }
function packet($topic, $payload) { return json_encode(['Topic' => $topic, 'Payload' => $payload]); }
function lastPacket($module) { return $module->sent[count($module->sent)-1]; }

$a = new FaikoutLocal();
$a->Create();
$a->properties['Hostname'] = 'wohnzimmer';
$a->properties['SensorVariableID'] = 12345;
$a->properties['DebugLogging'] = true;
$a->ApplyChanges();

$b = new FaikoutLocal();
$b->Create();
$b->properties['Hostname'] = 'kueche';
$b->ApplyChanges();

check($a->timers['ExternalTemperatureTimer'] === 0, 'external reference disabled by default');
check($a->variables['LastStatus']['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_DATE_TIME &&
      $a->variables['LastStatus']['profile']['DATE'] === 1 &&
      $a->variables['LastStatus']['profile']['TIME'] === 2, 'last status uses native date/time with seconds');
check(!function_exists('IPS_CreateVariableProfile'), 'no legacy profile helper required');
check($a->variables['ExternalReferenceEnabled']['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH,
    'local control uses native switch presentation');
check(isset($a->subscriptions[12345][VM_UPDATE]), 'sensor variable subscribed');
check($a->values['ExternalReferenceEnabled'] === false, 'initial switch off');
check($b->SetExternalReferenceEnabled(true) === false && count($b->sent) === 0, 'cannot activate without sensor');
check(!$a->SendSensorValue(), 'no temperature sent when disabled');

$form = json_decode($a->GetConfigurationForm(), true);
check(is_array($form) && str_contains(json_encode($form), 'Raumtemperatur (Symcon-Variable'), 'generic temperature UI');
check(str_contains(json_encode($form), 'Externe Temperaturregelung aktivieren'), 'activation button in form');
$deltaControls = array_filter($form['elements'][3]['items'] ?? [], fn($item)=>($item['name'] ?? '') === 'ImmediateDeltaKelvin');
check(count($deltaControls) === 1, 'delta configuration field present');
$deltaField = array_values($deltaControls)[0];
check($deltaField['caption'] === 'Mindeständerung (K)' && $deltaField['digits'] === 1 &&
      $deltaField['minimum'] === 0.1, 'delta label short and one decimal visible');

$a->ReceiveData(packet('state/kueche', '{"temp":19}'));
check(!isset($a->values['Temperature']), 'foreign room ignored');
$a->ReceiveData(packet('state/wohnzimmer', '{"online":true,"power":true,"temp":22,"home":21.1,"inlet":23.2,"Wh":1240,"mode":"H"}'));
check($a->values['Temperature'] === 22.0 && $a->values['InletTemperature'] === 23.2, 'status parsed');
$modePresentation = $a->variables['Mode']['profile'];
check($modePresentation['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION, 'native enumeration for mode');
$modeOptions = json_decode($modePresentation['OPTIONS'], true);
check(in_array(['Value'=>'H','Caption'=>'Heizen','IconActive'=>false,'IconValue'=>'','Color'=>-1], $modeOptions, true) &&
      in_array(['Value'=>'C','Caption'=>'Kühlen','IconActive'=>false,'IconValue'=>'','Color'=>-1], $modeOptions, true), 'native mode choices preserved');
check(count($modeOptions) === 5 && count(array_filter($modeOptions, function ($option) {
    return !array_key_exists('IconActive', $option) ||
        !array_key_exists('IconValue', $option) || !array_key_exists('Color', $option);
})) === 0, 'all mode options have mandatory enumeration keys');
$tempPresentation = $a->variables['Temperature']['profile'];
check($tempPresentation['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER &&
      $tempPresentation['MIN'] === 10.0 && $tempPresentation['MAX'] === 32.0 &&
      $tempPresentation['STEP_SIZE'] === 0.5 && $tempPresentation['DIGITS'] === 1,
      'native slider has correct range/steps');
check($a->variables['Power']['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH,
      'device power uses native switch');
check($a->variables['HomeTemperature']['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION,
      'room sensor uses native value presentation');
$a->ReceiveData(packet('state/wohnzimmer', '{"fan":"Q"}'));
$fanPresentation = $a->variables['Fan']['profile'];
$fanOptions = json_decode($fanPresentation['OPTIONS'], true);
check($fanPresentation['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION &&
      in_array(['Value'=>'Q','Caption'=>'Leise / Nacht','IconActive'=>false,'IconValue'=>'','Color'=>-1], $fanOptions, true) &&
      $a->values['Fan'] === 'Q', 'native fan choices preserve actual Faikout codes');
check(count($fanOptions) === 7 && count(array_filter($fanOptions, function ($option) {
    return !array_key_exists('IconActive', $option) ||
        !array_key_exists('IconValue', $option) || !array_key_exists('Color', $option);
})) === 0, 'all fan options have mandatory enumeration keys');
foreach (['Connected','ExternalReferenceReady'] as $identifier) {
    $values = json_decode($a->variables[$identifier]['profile']['OPTIONS'], true);
    check(count($values) === 2 && count(array_filter($values, function ($option) {
        return !array_key_exists('IconActive', $option) ||
            !array_key_exists('IconValue', $option) ||
            !array_key_exists('ColorActive', $option) ||
            !array_key_exists('ColorValue', $option);
    })) === 0, 'status presentation ' . $identifier . ' has required keys');
}
check($a->values['Connected'] === true && $a->values['AirconOnline'] === true, 'online status');
$airconStatus = $a->variables['AirconOnline'];
check($airconStatus['type'] === VARIABLETYPE_BOOLEAN &&
      $airconStatus['profile']['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION,
      'aircon response uses native read-only value presentation');
$airconOptions = json_decode($airconStatus['profile']['OPTIONS'], true);
check(count($airconOptions) === 2 &&
      in_array(['Value'=>false,'Caption'=>'Keine Antwort','IconActive'=>false,
                'IconValue'=>'','ColorActive'=>false,'ColorValue'=>-1], $airconOptions, true) &&
      in_array(['Value'=>true,'Caption'=>'Antwortet','IconActive'=>false,
                'IconValue'=>'','ColorActive'=>false,'ColorValue'=>-1], $airconOptions, true),
      'aircon response options are complete and labelled');
check(count(array_filter($a->debug, fn($x) => $x[0] === 'MQTT RX Topic')) === 2, 'received status and fan updates appear in debug');
check(count($b->debug) === 0, 'debug disabled by default');
$a->ReceiveData(packet('state/wohnzimmer', 'false'));
check($a->values['Connected'] === false, 'MQTT last will');
$a->ReceiveData(packet('state/wohnzimmer', '{"home":[18,19,20]}'));
check($a->values['HomeTemperature'] === 21.1, 'unsupported arrays ignored');

$a->ReceiveData(packet('state/wohnzimmer', '{"online":true,"power":true}'));
$a->RequestAction('ExternalReferenceEnabled', true);
check($a->values['ExternalReferenceEnabled'] === true && $a->attributes['ExternalReferenceEnabled'] === true, 'enable switch stored');
check($a->timers['ExternalTemperatureTimer'] === 0, 'timer waits for remote acknowledgement');
check($a->values['ExternalReferenceReady'] === false, 'remote not yet confirmed');
check($a->sent[0]['Topic'] === 'setting/wohnzimmer', 'faikout settings topic');
check(json_decode($a->sent[0]['Payload'], true) === ['autop'=>false,'autoptemp'=>0,'tempadjust'=>true], 'safe temperature adjustment settings');
check($a->sent[0]['Retain'] === false, 'settings sent without retain');
check($a->sent[1]['Topic'] === 'setting/wohnzimmer' && $a->sent[1]['Payload'] === '', 'request remote settings');
check(count($a->sent) === 2, 'no external temperature before acknowledgement');
check(count(array_filter($a->debug, fn($x) => $x[0] === 'MQTT TX Topic')) >= 2, 'transmit debug');

$a->ReceiveData(packet('setting/wohnzimmer', '{"tempadjust":true,"autop":false,"autoptemp":0,"tcontrol":600}'));
check($a->values['TempAdjust'] === true && $a->values['ControlTimeout'] === 600, 'remote setting feedback available');
check($a->values['ExternalReferenceReady'] === true, 'external reference confirmed');
check($a->timers['ExternalTemperatureTimer'] === 120000, 'timer starts after acknowledgement');
check($a->sent[2]['Topic'] === 'command/wohnzimmer/control' && $a->sent[2]['Payload'] === '{"env":21.3}', 'external sensor published after acknowledgement');
// Event updates are throttled and compared to the last value sent, not the last event.
$afterAck = count($a->sent);
$GLOBALS['sensor'][12345] = ['updated' => time(), 'value' => 21.50];
$a->MessageSink(1, 77777, VM_UPDATE, []);
check(count($a->sent) === $afterAck, 'updates from foreign sensor ignored');
$a->MessageSink(1, 12345, VM_UPDATE, []);
check(count($a->sent) === $afterAck, 'meaningful sensor update throttled after recent send');
$a->attributes['LastPublishedSensorAt'] = time() - 31;
$a->MessageSink(1, 12345, VM_UPDATE, []);
check(count($a->sent) === $afterAck + 1 && lastPacket($a)['Payload'] === '{"env":21.5}', 'significant sensor change sends promptly');
$afterChange = count($a->sent);
$a->attributes['LastPublishedSensorAt'] = time() - 31;
$GLOBALS['sensor'][12345] = ['updated' => time(), 'value' => 21.55];
$a->MessageSink(1, 12345, VM_UPDATE, []);
check(count($a->sent) === $afterChange, 'small change below 0.2 K not sent immediately');
$a->SendSensorValue();
check(count($a->sent) === $afterChange + 1 && lastPacket($a)['Payload'] === '{"env":21.6}', 'periodic heartbeat still sends minor changes');
$GLOBALS['sensor'][12345] = ['updated' => time(), 'value' => 21.25];

$a->ReceiveData(packet('state/wohnzimmer', 'false'));
check($a->values['ExternalReferenceReady'] === false &&
      $a->timers['ExternalTemperatureTimer'] === 0, 'disconnect stops transmission immediately');
$beforeReconnect = count($a->sent);
$a->ReceiveData(packet('state/wohnzimmer', '{"online":true}'));
check(count($a->sent) === $beforeReconnect + 2 &&
      $a->values['ExternalReferenceReady'] === false, 'reconnect negotiates settings, without sending env');
$a->ReceiveData(packet('setting/wohnzimmer', '{"autop":false,"autoptemp":0,"tempadjust":true}'));
check($a->values['ExternalReferenceReady'] === true &&
      $a->timers['ExternalTemperatureTimer'] === 120000, 'reconnect acknowledgement resumes timer');
check(lastPacket($a)['Topic'] === 'command/wohnzimmer/control', 'reconnect emits env only after ack');

$GLOBALS['sensor'][12345]['updated'] = time() - 3600;
$before = count($a->sent);
check(!$a->SendSensorValue() && count($a->sent) === $before, 'stale sensor rejected');
check(count(array_filter($a->debug, fn($x) => $x[0] === 'Temperature skip')) >= 1, 'skip reason logged');

$a->RequestAction('ExternalReferenceEnabled', false);
check($a->timers['ExternalTemperatureTimer'] === 0, 'timer stopped when disabled');
check($a->values['ExternalReferenceEnabled'] === false, 'disabled switch reflected');
check(lastPacket($a)['Topic'] === 'setting/wohnzimmer' && json_decode(lastPacket($a)['Payload'], true)['tempadjust'] === false, 'disable also informs Faikout');
check(!$a->SendSensorValue(), 'disabled prevents manual cyclic send');
$a->RequestAction('Mode', 'H');
check(lastPacket($a)['Topic'] === 'command/wohnzimmer/control' &&
      json_decode(lastPacket($a)['Payload'], true) === ['mode' => 'H'], 'mode action publishes documented Faikout code');
$a->RequestAction('Fan', 'Q');
check(json_decode(lastPacket($a)['Payload'], true) === ['fan' => 'Q'], 'quiet fan action publishes correct code');
$a->RequestAction('Fan', '3');
check(json_decode(lastPacket($a)['Payload'], true) === ['fan' => '3'], 'manual fan action uses string code');
$a->RequestAction('Temperature', 21.5);
check(json_decode(lastPacket($a)['Payload'], true) === ['temp' => 21.5], 'temperature action publishes number');
$invalidRejected = false;
try { $a->RequestAction('Mode', 'B'); } catch (InvalidArgumentException $e) { $invalidRejected = true; }
check($invalidRejected, 'invalid mode rejected');
$invalidRejected = false;
try { $a->RequestAction('Fan', '9'); } catch (InvalidArgumentException $e) { $invalidRejected = true; }
check($invalidRejected, 'invalid fan rejected');
check(!$a->SetExternalTemperature(99), 'implausible temperature rejected');
$a->ReceiveData(packet('state/wohnzimmer', 'false'));
check($a->values['ExternalReferenceReady'] === false &&
      $a->timers['ExternalTemperatureTimer'] === 0, 'MQTT disconnect disarms sensor forwarding');
$a->properties['SensorVariableID'] = 23456;
$GLOBALS['sensor'][23456] = ['updated' => time(), 'value' => 20.1];
$a->ApplyChanges();
check(!isset($a->subscriptions[12345][VM_UPDATE]), 'old sensor subscription removed after reconfiguration');
check(isset($a->subscriptions[23456][VM_UPDATE]), 'new sensor subscription registered');
echo "All tests passed\n";
