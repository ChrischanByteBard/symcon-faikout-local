<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VM_UPDATE = 10603;
$GLOBALS['profiles'] = [];
function IPS_VariableProfileExists($name) {return isset($GLOBALS['profiles'][$name]);}
function IPS_CreateVariableProfile($name, $type) {$GLOBALS['profiles'][$name] = ['type' => $type, 'associations' => []];return true;}
function IPS_SetVariableProfileAssociation($name, $value, $caption, $icon, $color) {
    $GLOBALS['profiles'][$name]['associations'][(string)$value] = $caption; return true;
}
function IPS_SetVariableProfileText($name, $prefix, $suffix) {
    $GLOBALS['profiles'][$name]['prefix'] = $prefix; $GLOBALS['profiles'][$name]['suffix'] = $suffix; return true;
}
function IPS_SetVariableProfileDigits($name, $digits) {
    $GLOBALS['profiles'][$name]['digits'] = $digits; return true;
}
function IPS_SetVariableProfileValues($name, $min, $max, $step) {
    $GLOBALS['profiles'][$name]['range'] = [$min, $max, $step]; return true;
}

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
    public function RegisterVariableBoolean($n, $c, $p, $i) { $this->values[$n] = false; }
    public function RegisterVariableInteger($n, $c, $p, $i) { $this->values[$n] = 0; }
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
check($GLOBALS['profiles']['FAIKOUT.Mode']['type'] === VARIABLETYPE_STRING, 'mode profile is string');
check($GLOBALS['profiles']['FAIKOUT.Mode']['associations']['H'] === 'Heizen', 'heating mode selectable');
check($GLOBALS['profiles']['FAIKOUT.Mode']['associations']['C'] === 'Kühlen', 'cool mode selectable');
check($GLOBALS['profiles']['FAIKOUT.Fan']['associations']['A'] === 'Automatik', 'automatic fan selectable');
check($GLOBALS['profiles']['FAIKOUT.Fan']['associations']['Q'] === 'Leise / Nacht', 'quiet fan selectable');
check($GLOBALS['profiles']['FAIKOUT.Fan']['associations']['5'] === 'Stufe 5', 'manual fan selectable');
check($GLOBALS['profiles']['FAIKOUT.TargetTemperature']['range'] === [10, 32, 0.5], 'target temperature range and step');
check($GLOBALS['profiles']['FAIKOUT.TargetTemperature']['digits'] === 1, 'temperature single decimal');
check($GLOBALS['profiles']['FAIKOUT.TargetTemperature']['suffix'] === ' °C', 'temperature unit');
check(isset($a->subscriptions[12345][VM_UPDATE]), 'sensor variable subscribed');
check($a->values['ExternalReferenceEnabled'] === false, 'initial switch off');
check($b->SetExternalReferenceEnabled(true) === false && count($b->sent) === 0, 'cannot activate without sensor');
check(!$a->SendSensorValue(), 'no temperature sent when disabled');

$form = json_decode($a->GetConfigurationForm(), true);
check(is_array($form) && str_contains(json_encode($form), 'Raumtemperatur (Symcon-Variable'), 'generic temperature UI');
check(str_contains(json_encode($form), 'Externe Temperaturregelung aktivieren'), 'activation button in form');

$a->ReceiveData(packet('state/kueche', '{"temp":19}'));
check(!isset($a->values['Temperature']), 'foreign room ignored');
$a->ReceiveData(packet('state/wohnzimmer', '{"online":true,"power":true,"temp":22,"home":21.1,"inlet":23.2,"Wh":1240,"mode":"H"}'));
check($a->values['Temperature'] === 22.0 && $a->values['InletTemperature'] === 23.2, 'status parsed');
check($a->variables['Mode']['profile'] === 'FAIKOUT.Mode', 'mode profile attached to received variable');
check($a->variables['Temperature']['profile'] === 'FAIKOUT.TargetTemperature', 'target temperature profile attached');
$a->ReceiveData(packet('state/wohnzimmer', '{"fan":"Q"}'));
check($a->variables['Fan']['profile'] === 'FAIKOUT.Fan' && $a->values['Fan'] === 'Q', 'fan profile attached and quiet level read');
check($a->values['Connected'] === true && $a->values['AirconOnline'] === true, 'online status');
check(count(array_filter($a->debug, fn($x) => $x[0] === 'MQTT RX Topic')) === 1, 'received topic appears in debug');
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
