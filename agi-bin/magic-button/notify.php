#!/usr/bin/env php
<?php 
  flush();
  require_once("/var/lib/asterisk/agi-bin/phpagi-asmanager.php");    
  $asm = new AGI_AsteriskManager();
  $asm->connect();

  $notifications = $argv[1];
  $notify = explode(",",$notifications);
  $notify = array_unique($notify);  // This should be implemented by the upstream API, however if it isn't, let's not disturb people twice
  $extension = $argv[2];

  $name_recording = $argv[3];
  $vars["__EXTENSION"] = $extension;
  if($name_recording)  {  
    $vars["NAME_RECORDING"] = $name_recording;
  }

  for($i = 0; $i < sizeof($notify); $i++)  {
    sleep(3);
    $vars["__REALCALLERIDNUM"] = $notify[$i];
    $state = $asm->ExtensionState($notify[$i],"default");
    if($state[Status] != 0)  {
      $vars["NOTIFY_VM"] = "true";
    }

    while(list($key,$val) = each($vars))  {
      $vars_arr[] = "$key=$val";
    }

    $vars = implode("|",$vars_arr);

      error_log("$vars\n",3,"/var/lib/asterisk/agi-bin/log-magic-button.txt");

    $res=$asm->originate("Local/".$notify[$i]."@magic-button-notify","magic-button-notify",$notify[$i],1,"","","","back",$vars);
  }
  $asm->disconnect();

?>
