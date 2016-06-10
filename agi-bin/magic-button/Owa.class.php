<?php
require_once("MagicXML.class.php");

class Owa  {
  var $exchange_server;
  var $exchange_email;
  var $exchange_password;

  var $exchange_username;
  var $protocol;
  var $server;
  var $port;

  var $headers;
  var $socket;

  function Owa($server,$email,$password)  {
    $this->exchange_server = $server;
    $this->exchange_email = $email;
    $this->exchange_password = $password;

    list($this->exchange_username,) = split("@",$this->exchange_email);
    $this->parse_url();
  }

  function __destruct()  {
    @fclose($this->socket);
  }
  function get($action)  {
   
    if($action == "contacts")
      $url ="/Contacts";

    $func = "get_".$action;
    // Try basic authorization (Exchange 2000)   
    $this->auth_basic();
    $base_url = "/exchange/".$this->exchange_username;
    $this->connect();
    $result = $this->$func($base_url);
    if(!is_array($result))  { // Try basic authorization (Exchange 2007)
      fclose($this->socket);
      $this->connect();
      $base_url = "/exchange/".$this->exchange_email;
      $result = $this->$func($base_url);
    }
    if(!is_array($result))  { // Try forms-based authentication (Exchange 2003)
      fclose($this->socket);
      $this->connect();
      $base_url = "/exchange/".$this->exchange_username;
      unset($this->headers);
      $this->auth_forms($base_url);

      $result = $this->$func($base_url);
    }
    return $result;
    
  }

  function auth_basic()  {
    $this->headers["Authorization"] = "Basic ".base64_encode($this->exchange_username.":".$this->exchange_password);
    $this->headers["Depth"] = "1";
    $this->headers["Translate"] = "f";
  }
  // Provides abstraction from basic authentication and forms based authentication
  function auth_forms($url)  {
    $auth_url = "/exchweb/bin/auth/owaauth.dll";
    $url = $this->exchange_server.$url;
    $data = "destination=".urlencode($url)."&username=".$this->exchange_username.
            "&password=".$this->exchange_password."&SubmitCreds=Log+On&trusted=4&flags=0";
    $response = $this->http_request("POST",$auth_url,"application/x-www-form-urlencoded",$data);
    $responses = explode("\r\n",$response);
    foreach($responses as $response)  {
      if(preg_match("/Set-Cookie: (.*)/",$response,$regs))  {
        $cook = explode("; ",$regs[1]);
        $cookies[] = trim($cook[0]);
      }
    }
    if(sizeof($cookies))  {
      $cookies = implode("; ",$cookies);
      $this->headers["Cookie"] = $cookies;
    }
    $response = $this->http_request("GET","/exchange/");

  }
  function connect()  {
    if (!$this->socket = @fsockopen(((strtolower($this->protocol) == "https://")?"ssl://":"").$this->server, $this->port, $errno, $errstr, 30)) {
      echo "$errno: $errstr\n";
      return false;
    }
    else  {
      register_shutdown_function(array(&$this, "__destruct"));
      return true;
    }
  }
  function parse_url() {
    $url = $this->exchange_server;
    preg_match("~([a-z]*://)?([^:^/]*)(:([0-9]{1,5}))?(/.*)?~i", $url, $parts);
    $this->protocol = $parts[1];
    $this->server = $parts[2];
    $port = $parts[4];
    $path = $parts[5];
    if ($port == "") {
      if (strtolower($this->protocol) == "https://") {
        $this->port = "443";
      } else {
        $this->port = "80";
      }
    }
    else
      $this->port=$port;
  }
  function get_contacts($base_url) {
    $url = $this->exchange_server.$base_url."/Contacts";
    $search_url = $base_url."/Contacts";
/*
if($this->exchange_2007)  {
  $url = $this->exchange_server."/exchange/".$this->exchange_email."/Contacts";
  $search_url = "/exchange/".$this->exchange_email."/Contacts";
}
else  {
  $url = $this->exchange_server."/exchange/".$this->exchange_username;
  $search_url = "/exchange/".$this->exchange_username."/Contacts";
}
*/
    $search_url = $base_url."/Contacts";
    $xmldata = '<?xml version="1.0"?>';
    $xmldata .= <<<END
<a:searchrequest xmlns:a="DAV:">
    <a:sql>
        SELECT "a:href"
        ,"urn:schemas:contacts:o"
        ,"urn:schemas:contacts:cn"
        ,"urn:schemas:contacts:fileas"
        ,"urn:schemas:contacts:givenName"
        ,"urn:schemas:contacts:sn"
        ,"urn:schemas:contacts:title"
        ,"urn:schemas:contacts:email1"
        ,"urn:schemas:contacts:telephoneNumber"
        ,"urn:schemas:contacts:homePhone"
        ,"urn:schemas:contacts:mobile"
        FROM "$search_url"
        ORDER BY "urn:schemas:contacts:cn"
    </a:sql>
</a:searchrequest>
END;
    $response = $this->http_request("SEARCH",$url,"text/xml; charset='UTF-8'",$xmldata);
    while (!feof($this->socket) && $line != "\r\n") {
      $line = fgets($this->socket, 128);
      $xml .= $line;
    }
    if(!preg_match("/(<\?)(.*)(>)/",$xml,$regs))
      return false;  // No xml returned, probably not connecting to right Exchange.  We'll retry in the get() function.

    $xml = $regs[1].$regs[2].$regs[3];
    $x = new MagicXML();
    if (!$x->fetch($xml)) {
    }
    
    foreach($x->data->A_MULTISTATUS[0]->A_RESPONSE as $idx=>$contact) {
      preg_match("/<(.*)>/",$contact->A_PROPSTAT[0]->A_PROP[0]->E_EMAIL1[0]->_text,$regs);
      $email = $regs[1];
      $contact_arr[] = array("company" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_O[0]->_text,                          
                             "title" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_TITLE[0]->_text,
                             "first_name" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_GIVENNAME[0]->_text,
                             "last_name" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_SN[0]->_text,
                             "email" => $email,
                             "phone" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_TELEPHONENUMBER[0]->_text,
                             "home_phone" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_HOMEPHONE[0]->_text,
                             "mobile_phone" => $contact->A_PROPSTAT[0]->A_PROP[0]->E_MOBILE[0]->_text);
      unset($regs);
    }
    if(sizeof($contact_arr))
      return $contact_arr;
    else
      return $response;
  }
  function http_request($method,$url,$content_type=null,$data=null)  {
    $request = "$method $url HTTP/1.1\r\n";
    if($content_type)
      $request .= "Content-Type: $content_type\r\n";
    $request .= "Connection: Keep-Alive\r\n";
    //$request .= "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.8.1.14) Gecko/20080404 Firefox/2.0.0.14\r\n";
    $request .= "User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.2; .NET CLR 1.1.4322)\r\n";
    $request .= "Host: ".$this->server.":".$this->port."\r\n";

    if(sizeof($this->headers))  {
      foreach ($this->headers as $key=>$value) {
        $request .= "$key: $value\r\n";
      }
    }
    if($data)
      $request .= "Content-Length: ".strlen($data)."\r\n";
    $request .= "\r\n";
    if($data)
      $request .= $data."\r\n\r\n";
//echo $request;
    fwrite($this->socket, $request);
    while (!feof($this->socket) && $header != "\r\n") {
      $header =  fgets($this->socket, 128);
      $response .= $header;
    }
//echo $response;
    return $response;

  }
}

?>
