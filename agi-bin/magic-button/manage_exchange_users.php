<?php
  set_time_limit(0);

  function my_readline($msg,$prompt = "[To continue, press Enter]",$acceptable=null,$notacceptable=null)  {
  $fp = popen("clear 2> /dev/null", "w");
  pclose($fp);
    print($msg);
    print("\n\n");
    print($prompt);
    flush();
    print ("\n");
    $badinput = true;
    while($badinput)  {
      exec("read OUTPUT\necho \$OUTPUT", $output);
      $input = trim($output[0]);
      if($acceptable && in_array($input,$acceptable))  {
        return $input;
      }
      else if($acceptable)  {
        echo $notacceptable." (Press Control-C to exit)\n";
        unset($output);
      }
      else  {
        return $input;
      }
    }

  }

  $msg = "This program will add Exchange users to the Magic Button.  Once added, these users ".
         "will be able to say 'import my contacts'.  Passwords are stored using your encrypted ".
         "SSL keypair.  Reinstallation of the Magic Button will require you to run this utility ".
         "again for each user, as your SSL keypair will change.";

  $msg = wordwrap($msg,80);
  $exchange_email = my_readline($msg);
  
  $valid_email = false;
  $email_prompt = "Enter the Exchange email address";
  $prompt = $email_prompt;
  while(!$valid_email)  {
    $exchange_email = my_readline("",$prompt);
    if(!preg_match("/^[[:alnum:]][a-z0-9_.-]*@[a-z0-9.-]+\.[a-z]{2,4}$/",$exchange_email))  {
      $prompt = "Invalid email address. ".$email_prompt;
    }
    else
      $valid_email = true;
  }

  $valid_password = false;
  $password_prompt = "Enter the Exchange password";
  $prompt = $password_prompt;
  while(!$valid_password)  {
    $exchange_password = my_readline("",$prompt);
    if(!$exchange_password)  {
      $prompt = "Invalid password. ".$email_prompt;
    }
    else
      $valid_password = true;
  }

  $valid_extension = false;
  $extension_prompt = "Enter the PBX extension this user is associated with.";
  $prompt = $extension_prompt;
  while(!$valid_extension)  {
    $extension = my_readline("",$prompt);
    if(!preg_match("/^[0-9]+$/",$extension))  {
      $prompt = "Invalid extension. ".$extension_prompt;
    }
    else
      $valid_extension = true;
  }
  require_once "ssl.php";
  $passphrase = @zend_get_id();
  $passphrase = $passphrase[0];

  $pub_key=get_public_key();
  openssl_get_publickey($pub_key);

  openssl_public_encrypt($exchange_password,$encrypted,$pub_key);
  $encrypted = base64_encode($encrypted);

  $file = "/var/lib/asterisk/agi-bin/magic-button/data/exchange_users";
  $ini_array = @parse_ini_file($file,true);
  $ini_array[exchange][$extension] = "$exchange_email:$encrypted";
  require_once "MagicHelpers.class.php";
  $helper = new MagicHelpers;
  $helper->write_ini_file($file, $ini_array);
   
?>
