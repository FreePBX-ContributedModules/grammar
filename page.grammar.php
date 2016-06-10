<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed');}
//Copyright (C) 2009 Ethan Schroeder (ethan.schroeder@schmoozecom.com)
//

$dispnum = 'grammar'; //used for switch on config.php

$action = isset($_REQUEST['action'])?$_REQUEST['action']:'';

isset($_REQUEST['magicbuttonenabled'])?$magicbuttonenabled = $_REQUEST['magicbuttonenabled']:$magicbuttonenabled='0';
isset($_REQUEST['zip_code'])?$zip_code = trim($_REQUEST['zip_code']):$zip_code='';
isset($_REQUEST['tts_engine'])?$tts_engine = $_REQUEST['tts_engine']:$tts_engine='';
isset($_REQUEST['aastra_support'])?$aastra_support = $_REQUEST['aastra_support']:$aastra_support='1';

// do if we are submitting a form
$config = array();
if(isset($_POST['action'])){

	if ($action == 'edtSpeech') {
		speech_config_add($magicbuttonenabled,$zip_code,$tts_engine,$aastra_support);
		needreload();
		redirect_standard();
	}
}
else  {
	$config = speech_getconfig();
	if(is_array($config))  {
		extract($config);
	}
}
if (file_exists('libraries/extensions.class.php')) {
	include_once "libraries/extensions.class.php";
} elseif (file_exists('extensions.class.php')) {
	include_once "extensions.class.php";
}
$ext = new extensions;
if(!method_exists($ext,"replace"))  { // this method (and others) were added in 2.5.1.something
	echo "<b>WARNING: You need to update your modules before continuing with the configuration below and clicking on the red reload bar.  Go to Tools:Module Admin then click 'Check for Updates Online' then click 'Upgrade all' then click the Process button</b><br><br>";

}

if($licenseserverenabled == "1")  {
	$settings = false; // whether or not we need to notify the user that LumenVox needs to be configured

	// LumenVox has different config files for 8.0, 8.5, 8.6, and 9.0 (ugh)
	$file = "/opt/lumenvox/engine/bin/license_client.conf";
	$parameter = "ip_address";
	$command = "service lvdaemon restart";
	$actual_file = null;
	$actual_parameter = null;
	$actual_command = null;
	$settings = false;
	if(file_exists($file) && preg_match('/'.$parameter.'/',file_get_contents($file)))  {
		if(!preg_match("/speech.pbxact.com/",file_get_contents($file)))  {
			$actual_file = $file;
			$actual_parameter = $parameter;
			$actual_command = $command;
			$settings = true;
		}
	} else {
		$file = "/etc/lumenvox/client_property.conf";
		$parameter = "LIC_SERVER_HOSTNAME ";
		$command = "service lvsred restart";
		if(file_exists($file) && preg_match('/'.$parameter.'/',file_get_contents($file)))  {
			if(!preg_match("/speech.pbxact.com/",file_get_contents($file)))  {
				$actual_file = $file;
				$actual_parameter = $parameter;
				$actual_command = $command;
				$settings = true;
			}
		} else {
			$file = "/etc/lumenvox/lumenvox_settings.conf";
			$parameter = "LIC_SERVER_HOSTNAME";
			$command = "service lvsred restart";
			if(file_exists($file) && preg_match('/'.$parameter.'/',file_get_contents($file)))  {
				if(!preg_match("/speech.pbxact.com/",file_get_contents($file)))  {
					$actual_file = $file;
					$actual_parameter = $parameter;
					$actual_command = $command;
					$settings = true;
				}
			}

		}
	}

	if($settings)  {
		echo "<b>WARNING: Action needed to activate centralized speech licensing server</b><br>";
		echo "<ol><li>Edit the file $actual_file</li><li>change $actual_parameter=127.0.0.1 to $actual_parameter=speech.pbxact.com</li>";
		echo "<li>From commmand line run: <i>$actual_command</i></li>";
	}
}

$nt = notifications::create($db);
$warnings = $nt->list_warning(true);
foreach ($warnings as $warning) {
	if ($warning['module'] == 'grammar' && $warning['id'] == 'LIC') {
                echo '<h3><a href="#" class="info">'._("WARNING: ").$warning['extended_text']."</a></h3>";
		break;
        }
}

?>

<div class="rnav">
	<ul>
        	<li><a <?php  echo (!isset($_REQUEST['debug'])? 'class="current"':'') ?>href="config.php?display=<?php echo urlencode($dispnum)?>"><?php echo _("Settings")?></a></li>
        	<hr/>
		<li><a <?php  echo (isset($_REQUEST['debug'])? 'class="current"':'') ?>href="config.php?display=<?php echo urlencode($dispnum)?>&debug=true"><?php echo _("Debug")?></a></li>
	</ul>
</div>

<h2><?php echo _("Speech Recognition Configuration")?></h2>
<form name="grammar" action="config.php" method="post">
<input type="hidden" name="display" value="grammar"/>
<input type="hidden" name="action" value="edtSpeech"/>
<table>
<?php
	if(isset($_REQUEST['debug']))  { // Display various system information that may be useful for debugging
?>
<tr><td colspan="2"><h5><?php echo _("Debug Information")?><hr></h5></td></tr>
<tr><td colspan="2">
<?php
	echo "<p><b>Settings</b></p>";
	echo "<table border='0'>";
	while(list($key,$val) = each($config))  {
		echo "<tr><td>$key</td><td>$val</td></tr>";
	}
	echo "</table>";
	echo "<p><b>Directories/Files</b></p>";
	if(file_exists("/var/lib/asterisk/agi-bin/magic-button.php"))  {
		echo "File /var/lib/asterisk/agi-bin/magic-button.php exists<br>";
	}
	else  {
		echo "File /var/lib/asterisk/agi-bin/magic-button.php does <b>not</b> exist<br>";
	}
	if(is_dir("/var/lib/asterisk/agi-bin/magic-button"))  {
		echo "Directory /var/lib/asterisk/agi-bin/magic-button exists<br>";
	}
	else  {
		echo "Directory /var/lib/asterisk/agi-bin/magic-button does <b>not</b> exist<br>";
	}
	echo "<p><b>LumenVox Information</b></p>";

	// test for ringgroups because that is a file that is copied as a blank template.  It has to exist (even if there are
	// no ring groups setup) for the magic button to work.  If it didn't get copied propery with cp -u in install.php
	// it will cause problems
	if(file_exists("/etc/asterisk/grammars/magicbutton.gram") && file_exists("/etc/asterisk/grammars/ringgroups.gram"))  {
		echo "Grammar files exist<br>";
	}
	else  {

		echo "All necessary grammar files do <b>not</b> exist<br>";
	}
	if(file_exists("/opt/lumenvox/engine/bin/license_client.conf"))  {
	}
	else if(file_exists("/etc/lumenvox/client_properties.conf"))  {
	}
	else if(file_exists("/etc/lumenvox/lumenvox_settings.conf"))  {
	}

	$lv_config = grammar_lvConf('/etc/lumenvox/client_property.conf');
	$socket = @fsockopen($lv_config['server'], $lv_config['port'], $errno, $errstr, 5);
	if($socket)  {
		echo "Connecting to speech servers (".$lv_config['server'].")....successful<br>";
	}
	else  {
		echo "Connecting to speech servers (".$lv_config['server'].")....<b>un-successful</b><br>";
	}

	$lvSRETestDir = '/usr/share/doc/lumenvox/client/examples';
	$lvSREConfigCheck = array (2 => 'Decode returned!');

	if(is_dir($lvSRETestDir)) {
		$simplesreclient_cmd = $lvSRETestDir.'/SimpleSREClient 1 '.$lvSRETestDir.'/ABNFDigits.gram 2 '.$lvSRETestDir.'/8587070707.pcm '.$lv_config['sre'];
		exec($simplesreclient_cmd, $lvCmdOutput);

		if (!empty($lvCmdOutput)) {
		        if ($lvCmdOutput[3] == $lvSREConfigCheck[2]) {
		        	echo "Connecting to speech servers with authentication credentials....successful<br>";
			} else {
		        	echo "Connecting to speech servers with authentication credentials....<b>unsuccessful</b><br>";
			}
		} else {
			echo "Connecting to speech servers with authentication credentials....<b>unsuccessful</b><br>";
		}
	} else {
		echo "Unable to test speech server authentication credentials<br>";
	}

	if (function_exists('sysadmin_get_public_ip')) {
                $contents = sysadmin_get_public_ip();
        }

	if($contents)  {
		echo "Connected to keep-alive with IP address of $contents<br>";
	}
	else  {
		echo "<b>Unable</b> to connect to keep-alive<br>";
	}

?>
</td></tr>
<?php
	} // End if($_REQUEST['debug'])
		include_once("/var/lib/asterisk/agi-bin/magic-button/MagicButton.class.php");
?>
<tr><td colspan="2"><h5><?php echo _("Magic Button Options")?><hr></h5></td></tr>
	<tr>
	<td><a href=# class="info"><?php echo _("Enable Magic Button")?><span><?php echo _("Check this box to enable the Magic Button")?></span></a></td>
	<td align=right><input type="checkbox" value="1" name="magicbuttonenabled" <?php  echo ($magicbuttonenabled ? 'CHECKED' : '')?> tabindex="<?php echo ++$tabindex;?>"></td>
	</tr>
	<tr>
	<td><a href=# class="info"><?php echo _("Zip Code:")?><span><?php echo _("Zip code is used for \"What is the weather like?\" command.")?><br>
	</span></a></td>
	<td align=right><input type="text" size="19" maxlength="5" name="zip_code" value="<?php  echo htmlspecialchars($zip_code)?>" tabindex="<?php echo ++$tabindex;?>"/></td>
	</tr>
	<?php //ModuleHook from our tts_engines module ?>
	<?php echo $module_hook->hookHtml; ?>
	<tr>
	<td><a href=# class="info"><?php echo _("Enable Aastra Sync")?><span><?php echo _("Check this box to enable synchronization with Aastra phone applications.  This will sync buttons like DND and Away when users give commands to the magic button")?></span></a></td>
	<td align=right><input type="checkbox" value="1" name="aastra_support" <?php  echo ($aastra_support ? 'CHECKED' : '')?> tabindex="<?php echo ++$tabindex;?>"></td>
	</tr>

	<tr>
		<td colspan="2"><br><h6><input name="Submit" type="submit" value="<?php echo _("Submit Changes")?>" tabindex="<?php echo ++$tabindex;?>"></h6></td>
	</tr>
	</table>
</form>
