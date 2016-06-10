<?php
require_once("/var/lib/asterisk/agi-bin/phpagi.php");

class AastraPush  {
  var $asm;

  function AastraPush($server,$user,$port="")  {

    $this->asm = new AGI_AsteriskManager();
    $this->asm->connect($this->asmServer, $this->asmUser, $this->asmPassword);

    $ip = trim($this->get_ip($user));    
    $mac = trim(preg_replace("/[^A-Z0-9]/","",$this->macAddress($ip)));

    $dnd_key = `grep dnd.php /tftpboot/$mac.cfg|gawk '/key/{print $1}'`;
    $dnd_key = trim($dnd_key);
    $cfwd_key = `grep cfwd.php /tftpboot/$mac.cfg|gawk '/key/{print $1}'`;
    $cfwd_key = trim($cfwd_key);
    $away_key = `grep away.php /tftpboot/$mac.cfg|gawk '/key/{print $1}'`;
    $away_key = trim($away_key);
   
    $server_uri = "http://$server";
    if($port)
      $server_uri = "$server_uri:$port";

    if($dnd_key)  {
      $uri = $this->escape($server_uri."/aastra/asterisk/dnd.php?action=check&key=$dnd_key&user=$user"); 
      $this->push_xml($server,$ip,$uri); 
    } 
    if($away_key)  {
      $uri = $this->escape($server_uri."/aastra/asterisk/away.php?check=true&key=$away_key&user=$user"); 
      $this->push_xml($server,$ip,$uri); 
    } 
    if($cfwd_key)  {
      $uri = $this->escape($server_uri."/aastra/asterisk/cfwd.php?action\=check&key\=$cfwd_key&user\=$user");
//      push_xml($server,$ip,$uri);
    } 
    $this->asm->disconnect();
  }


  function push_xml($server,$phone_ip,$uri)   {  
    $xml = "xml=";
    $xml .= "<AastraIPPhoneExecute>\n"; 
    $xml .= "<ExecuteItem URI=\"".$uri."\"/>\n"; 
    $xml .= "</AastraIPPhoneExecute>\n"; 

 
    $post = "POST / HTTP/1.1\r\n";  
    $post .= "Host: $phone_ip\r\n";  
    $post .= "Referer: $server\r\n";  
    $post .= "Connection: Keep-Alive\r\n";  
    $post .= "Content-Type: text/xml\r\n";  
    $post .= "Content-Length: ".strlen($xml)."\r\n\r\n";  

    $log = "Pushed\n\n";
    $log .= $xml;
    $log .= "\n\n";
    $log .= "Post:\n";
    $log .= $post;
    $log .= "To: $phone_ip";
 
    $fp = @fsockopen ( $phone_ip, 80, $errno, $errstr, 5);  
    if($fp)  {  
      fputs($fp, $post.$xml);  
      flush(); 
      fclose($fp); 
    }  
  }   

  function get_ip($user)  {

    $res = $this->asm->Command('sip show peer '.$user);

    $line= split("\n", $res['data']);
    $index=0;
    $count=0;
    foreach ($line as $myline)  {
      if(strstr($myline,"Addr->IP"))  {
        $linevalue= preg_split("/ /", $myline,-1,PREG_SPLIT_NO_EMPTY);
        $ip = $linevalue[2];
      }
    } 
    return $ip;
  }

  function macAddress($ip) {
    $mac = `/sbin/arp |grep $ip|gawk '/$ip/{print $3}'`;
    return $mac;  
  }

  function escape($string)  {
    return(str_replace(
     array('<', '>', '&'),
     array('&lt;', '&gt;', '&amp;'),
     $string));
  }
}

  if($argv[1] && $argv[2])  {
    // AastraPush(push_server_ip,extension,push_port)
    echo "\nPushing XML objects to $argv[1]:$argv[3] extension: $argv[2]\n\n";
    if($argv[3])
      new AastraPush($argv[1],$argv[2],$argv[3]);
    else
      new AastraPush($argv[1],$argv[2]);
  } 
  else if(!$argv[1] && !$argv[2])
    echo "\nUSAGE: push.php server_ip extension [port]\n\n";

?>
