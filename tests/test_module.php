<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN=0;
const VARIABLETYPE_INTEGER=1;
const VARIABLETYPE_FLOAT=2;
const VARIABLETYPE_STRING=3;
$GLOBALS['sensor']=[12345=>['updated'=>time(),'value'=>21.25]];
function IPS_VariableExists($id){return isset($GLOBALS['sensor'][$id]);}
function IPS_GetVariable($id){return ['VariableUpdated'=>$GLOBALS['sensor'][$id]['updated']];}
function GetValue($id){return $GLOBALS['sensor'][$id]['value'];}
class IPSModule {
 public array $properties=[], $values=[], $timers=[], $sent=[];
 public function Create(){} public function ApplyChanges(){} public function ConnectParent($x){}
 public function RegisterPropertyString($n,$v){$this->properties[$n]=$v;}
 public function RegisterPropertyInteger($n,$v){$this->properties[$n]=$v;}
 public function ReadPropertyString($n){return (string)$this->properties[$n];}
 public function ReadPropertyInteger($n){return (int)$this->properties[$n];}
 public function RegisterTimer($n,$v,$s){$this->timers[$n]=$v;}
 public function SetTimerInterval($n,$v){$this->timers[$n]=$v;}
 public function SetReceiveDataFilter($f){}
 public function RegisterVariableBoolean($n,$c,$p,$i){$this->values[$n]=false;}
 public function RegisterVariableInteger($n,$c,$p,$i){$this->values[$n]=0;}
 public function RegisterVariableString($n,$c,$p,$i){$this->values[$n]='';}
 public function MaintainVariable($n,$c,$t,$p,$i,$m){}
 public function EnableAction($n){}
 public function SetValue($n,$v){$this->values[$n]=$v;}
 public function SendDataToParent($j){$this->sent[]=json_decode($j,true);return 'ok';}
}
require dirname(__DIR__).'/FaikoutLocal/module.php';
function check($x,$message){if(!$x)throw new RuntimeException($message);echo "PASS: $message\n";}
function msg($t,$p){return json_encode(['Topic'=>$t,'Payload'=>$p]);}
$a=new FaikoutLocal();$a->Create();$a->properties['Hostname']='wohnzimmer';$a->properties['SensorVariableID']=12345;$a->ApplyChanges();
$b=new FaikoutLocal();$b->Create();$b->properties['Hostname']='kueche';$b->ApplyChanges();
check($a->timers['ExternalTemperatureTimer']===120000,'periodic sensor timer');
check($b->timers['ExternalTemperatureTimer']===0,'unconfigured timer off');
$a->ReceiveData(msg('state/kueche','{"temp":19}'));
check(!isset($a->values['Temperature']),'other device ignored');
$a->ReceiveData(msg('state/wohnzimmer','{"online":true,"power":true,"temp":22,"home":21.1,"inlet":23.2,"Wh":1240,"mode":"H"}'));
check($a->values['Temperature']===22.0 && $a->values['InletTemperature']===23.2,'status decoded');
check($a->values['Connected']===true && $a->values['AirconOnline']===true,'online status');
$a->ReceiveData(msg('state/wohnzimmer','false'));
check($a->values['Connected']===false,'MQTT will');
$a->ReceiveData(msg('state/wohnzimmer','{"home":[18,19,20]}'));
check($a->values['HomeTemperature']===21.1,'aggregates ignored');
$a->ReceiveData(msg('setting/wohnzimmer','{"haenable":true,"tempadjust":true,"tcontrol":600}'));
check($a->values['TempAdjust']===true && $a->values['ControlTimeout']===600,'settings decoded');
$a->RequestAction('Mode','H');
check($a->sent[0]['Topic']==='command/wohnzimmer/control' && $a->sent[0]['Retain']===false,'nonretained control');
check($a->SendSensorValue() && $a->sent[1]['Payload']==='{"env":21.3}','external temperature');
$before=count($a->sent);
$GLOBALS['sensor'][12345]['updated']=time()-3600;
check(!$a->SendSensorValue() && count($a->sent)===$before,'stale sensor blocked');
check(!$a->SetExternalTemperature(99),'implausible temperature blocked');
echo "All tests passed\n";
