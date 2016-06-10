<?php
/*********************************************************************
*
* MagicVoicemail.class.php
*
* Class MagicVoicemail - voicemail access for the speech recognition
* Magic Button
*
* Copyright 2008 Ethan Schroeder, Schmooze Communications, LLC
*
*********************************************************************/
class MagicVoicemail  {
  
  var $mailbox;
  var $conf_path = "/etc/asterisk";
  var $voicemail_path = "/var/spool/asterisk/voicemail/default";
  var $user_conf = array();
  var $intro = array();  // introductory message, for example "You have x new and x old messages"
  var $maxmsg;

  // Constructor
  function MagicVoicemail($mailbox)  {
    $this->mailbox = $mailbox;
    $this->get_settings();
  }

  function authenticate($password) {
    if($password == $this->user_config["password"])
      return true;
    else
      return false;
  }

  function get_settings()  {
    $mailbox = $this->mailbox;
    $conf = $this->conf_path."/voicemail.conf";
    $vm_users = file($conf);
    foreach($vm_users as $line)  {
      if(preg_match("/^$mailbox => ([0-9]+),(.*),(.*),(.*),(.*)/",$line,$regs))
        $this->user_conf["password"] = $regs[1];
        $this->user_conf["name"] = $regs[2];
        $this->user_conf["email"] = $regs[3];
        $this->user_conf["pager"] = $regs[4];
        $options = explode("|",$regs[5]);
        foreach($options as $option)  {
          list($key,$val) = split("=",$option);
          $this->user_conf[$key] = $val;
        }
     //   $this->user_conf["options"] = $regs[5];
    }
    $global_conf = $this->conf_path."/vm_general.inc";
    $vm_global = parse_ini_file($global_conf, false);
    if($vm_global['maxmsg'])
      $this->maxmsg = $vm_global['maxmsg'];
    else    
      $this->maxmsg = "100";
  }

  function get_messages($folder) {
    $mailbox = $this->mailbox;
    $path = $this->voicemail_path."/".$mailbox."/".$folder."/";
    $messages = array();
    if($dh =  opendir($path))  { // List directory
      while ($filename = readdir($dh)) { // Go through each file in the directory
        if (preg_match("/msg([0-9]{4})/",$filename,$regs))  {
          if (preg_match("/.txt/",$filename))  {
            $ini_array = parse_ini_file($path . $filename, false);
            $origtime = $ini_array['origtime'];
            $callerid = $ini_array['callerid'];
            $duration = $ini_array['duration'];
            $file = $regs[1];
            preg_match("/(.*)<(.*)>/",$ini_array['callerid'],$cid_r);
            $datetime = date("M j Y, g:i a", (int)$origtime);
          
            $messages[] = array("file" => $file, "datetime" => $datetime,"origtime" => $origtime, "cidname" => $cid_r[1], 
                                "cidnumber" => $cid_r[2],"duration" => $duration); 
          }
        }
      }
    }		
    closedir($dh);

    if(!sizeof($messages))  {
      return;
    }
    else  { // Sort by date, then return
     foreach ($messages as $key => $value) {
        $origtime_t[$key]  = $value['origtime'];
      }
      $sort = SORT_ASC;
      array_multisort($origtime_t, $sort, $messages);
      return $messages;
    }
  } 

  function delete_message($folder, $message) {
    $mailbox = $this->mailbox;
    $base = $this->voicemail_path."/".$mailbox."/".$folder."/";
    $deleted = false;

    if ($handle = @opendir($base)) {
      while (($file = @readdir($handle)) !==false) {
        if (preg_match("/^msg$message/",$file, $regs)){
          if(@unlink("$base/$file")){
            $deleted = true;
          }
        }
      }
    }
    return deleted;
  }

  function move_message($message, $fromfolder, $tofolder) {
    $mailbox = $this->mailbox;
    $src_path = $this->voicemail_path.'/'.$mailbox.'/'.$fromfolder;
    $dst_path = $this->voicemail_path.'/'.$mailbox.'/'.$tofolder;

    umask("0000");
    if(!@file_exists($dst_path))  {
      if(!@mkdir($dst_path,"0720" )) return false;
    }
    for($i = 0; $i <= $this->maxmsg; $i++)  {
      $message_number_avail = sprintf("msg%04d", $i);
      if(!@file_exists($dst_path.'/'.$message_number.'.txt') ) //find an opening
        break;

      // folder full
     if($i >= $this->maxmsg) 
       return false;
    }

    foreach(array("wav","WAV","gsm","txt") as $extension)  {
      $src = $src_path.'/msg'.$message.".".$extension;
      $dst = $dst_path.'/'.$message_number_avail.".".$extension;
      if(@file_exists($src))  {
        if(!@copy($src, $dst)) 
          return false;
        else @unlink($src);
      }
    }
    return true;
  }

  function mark_read($message, $folder)  {
    $mailbox = $this->mailbox;
    return $this->move_message($message,$folder,"Old");
  }
  function index_messages($folder) {
    $mailbox = $this->mailbox;
    $base = $this->voicemail_path."/".$mailbox."/".$folder;
    $files = $this->get_messages($folder);
    $count = 0;
    for($i = 0; $i < sizeof($files); $i++)  {
      $files[$i]['newposition'] = $count;
      $count++;
    }
    foreach(array("wav","WAV","gsm","txt") as $extension)  { 
      $total = 0;
      foreach($files as $file)  {
        $from = "$base/".sprintf("msg%04d",$file[file]).".$extension";
        $to = "$base/".sprintf("msg%04d", $file[newposition]);
        @rename($from,$to); // Rename old file as new file minus extension

        $total++;       
      }
      for($i = 0; $i < $total; $i++)  {
        $from = "$base/".sprintf("msg%04d",$i);
        $to = "$base/".sprintf("msg%04d", $i).".$extension";
        @rename($from,$to); // Rename file with blank extension to final file

      }
    }
  }
}
/*
$vm = new MagicVoicemail("4001");
$new = $vm->get_messages("INBOX");
//$old = $vm->get_messages("Old");
//echo $vm->get_intro();
echo "new:\n";
print_r($new);
//echo "old:\n";
//print_r($old);
//echo sizeof($old);
$vm->get_settings();
print_r($vm->user_conf);
*/
?>
