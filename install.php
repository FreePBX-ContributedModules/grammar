<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed');}
require_once dirname(__FILE__)."/functions.inc.php";

global $db, $amp_conf;

// First we want to detect if this is a clean installation or an upgrade
// If it is a clean installation, we want to pre-populate extensions with
// grammars so the user isn't confused about configuration
$clean_install = false;
$sql = "SELECT * FROM grammar";
$check = $db->query($sql);
// If it fails, it means the table doesn't exist.  It will get created below, and then we will populate it afterward
if(DB::IsError($check)) {
	$clean_install = true;
}

$sql = "
CREATE TABLE IF NOT EXISTS `grammar` (
  `type` varchar(30) NOT NULL,
  `id` varchar(30) NOT NULL,
  `vocabulary` varchar(80) NOT NULL,
  `sequence` tinyint(4) NOT NULL,
  `enabled` smallint(6) NOT NULL default '1',
  `directory` smallint(6) NOT NULL default '0',
  PRIMARY KEY  (`type`,`id`,`vocabulary`,`sequence`)
)";

$check = $db->query($sql);
if(DB::IsError($check)) { die_freepbx($check->getDebugInfo()); }

$sql = "
CREATE TABLE IF NOT EXISTS `grammar_config` (
`magicbuttonenabled` TINYINT NOT NULL ,
`zip_code` VARCHAR( 5 ) NOT NULL ,
`tts_engine` VARCHAR( 16 ) NOT NULL
)";

$check = $db->query($sql);
if(DB::IsError($check)) { die_freepbx($check->getDebugInfo()); }

$sql = "
CREATE TABLE IF NOT EXISTS `grammar_config_ivr` (
  `ivr_id` int(11) NOT NULL,
  `fallback_announcement` int(11) NOT NULL,
  `sorry_announcement` int(11) NOT NULL,
  KEY `ivr_id` (`ivr_id`)
)";

$check = $db->query($sql);
if(DB::IsError($check)) { die_freepbx($check->getDebugInfo()); }

$sql = "SELECT aastra_support FROM grammar_config";
$check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
if(DB::IsError($check)) {
        // add new field
    $sql = "ALTER TABLE grammar_config ADD aastra_support SMALLINT( 6 ) NOT NULL DEFAULT 0 ;";
    $result = $db->query($sql);
    if(DB::IsError($result)) { die_freepbx($result->getDebugInfo()); }
}
$sql = "SELECT enabled FROM grammar";
$check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
if(DB::IsError($check)) {
        // add new field
    $sql = "ALTER TABLE grammar ADD enabled SMALLINT( 6 ) NOT NULL DEFAULT 1 ;";
    $result = $db->query($sql);
    if(DB::IsError($result)) { die_freepbx($result->getDebugInfo()); }
}
$sql = "SELECT directory FROM grammar";
$check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
if(DB::IsError($check)) {
        // add new field
    $sql = "ALTER TABLE grammar ADD directory SMALLINT( 6 ) NOT NULL DEFAULT 0 ;";
    $result = $db->query($sql);
    if(DB::IsError($result)) { die_freepbx($result->getDebugInfo()); }
}

//If we have information we no longer need, let's get rid of it
$sql = "SELECT * FROM  `grammar_config` WHERE `exchange_server`";
$check_exchange = $db->query($sql);
if(!DB::IsError($check_exchange)) {
	$sql = "ALTER TABLE `grammar_config` DROP `exchange_server`";
	$check = $db->query($sql);
	if(DB::IsError($check)) { die_freepbx($check->getDebugInfo()); }
}

//If we have information we no longer need, let's get rid of it
$sql = "SELECT * FROM  `grammar_config` WHERE `licenseserverenabled`";
$check_licenseserverenabled = $db->query($sql);
if(!DB::IsError($check_licenseserverenabled)) {
	$sql = "ALTER TABLE `grammar_config` DROP `licenseserverenabled`";
	$check = $db->query($sql);
	if(DB::IsError($check)) { die_freepbx($check->getDebugInfo()); }
}


//remove tz column
$sql = "SELECT * FROM  `grammar_config` WHERE `timezone`";
$check_timezone = $db->query($sql);
if(!DB::IsError($check_timezone)) {
	$sql = "ALTER TABLE `grammar_config` DROP `timezone`";
	$check = $db->query($sql);
	if(DB::IsError($check)) {
		die_freepbx($check->getDebugInfo());
	}
}

global $amp_conf;
$htdocs_source = dirname(__FILE__);
$htdocs_dest = $amp_conf['AMPWEBROOT'];
$etc = $amp_conf['ASTETCDIR'];
$agi = $amp_conf['ASTAGIDIR'];



$sql = "SELECT * FROM grammar_config";
$check = $db->getRow($sql, DB_FETCHMODE_ASSOC);
if(($curr_username && $curr_deployment) && (!is_array($check) || sizeof($check) == 0)) {
	$licenseserverenabled = "0";
	$file = "/opt/lumenvox/engine/bin/license_client.conf";
	if(file_exists($file) && preg_match("/speech.schmoozecom.com/",file_get_contents($file)))  { // Lumenvox < 8.5
		$licenseserverenabled = "1";
	}
	else  {
		$file = "/etc/lumenvox/lumenvox_settings.conf";
		if(file_exists($file) && preg_match("/speech.schmoozecom.com/",file_get_contents($file)))  { // LumenVox 8.5+
			$licenseserverenabled = "1";
		}
	}


	$sql = "INSERT INTO grammar_config (email_address,deployment,magicbuttonenabled)
			VALUES ('$curr_username','$curr_deployment','1')";
	$result = $db->query($sql);
	if(DB::IsError($result)) { die_freepbx($result->getDebugInfo()); }
}


if($clean_install)  {
	$sql = "INSERT INTO grammar (type,id,vocabulary,sequence,enabled,directory) SELECT 'user',extension,name,'1','1','1' FROM users";
	$result = $db->query($sql);
	if(DB::IsError($result)) {
		die_freepbx($result->getDebugInfo());
	}
	else  {
		// Find all grammars that have bad characters
		$sql = "SELECT * FROM grammar WHERE vocabulary NOT REGEXP '^[0-9a-zA-Z ]'";
		$result = $db->getAll($sql,DB_FETCHMODE_ASSOC);
		if(DB::IsError($result)) {
			die_freepbx($result->getDebugInfo());
		}
		if(sizeof($result))  {
			foreach($result as $bad)  {
				// Clean the bad characters
				$vocab = preg_replace("/[^a-zA-Z0-9 ]/","",$bad['vocabulary']);
				$id = $bad['id'];
				// Submit the cleaned grammars
				$sql = "UPDATE grammar SET vocabulary='$vocab' WHERE id='$id'";
				$result2 = $db->query($sql);
				if(DB::IsError($result2)) {
					die_freepbx($result->getDebugInfo());
				}
			}
		}
	}
}


// Temporarily move data directory, then move it back
exec("rm -rf /tmp/agi-bin-data");
exec("mv $agi/magic-button/data /tmp/agi-bin-data");
exec("rm -rf $agi/magic-button");
exec("rm -rf $agi/magic-button/data/");
//exec("mv /tmp/agi-bin-data $agi/magic-button/data");

//appease 2.9 and create the folder so it will link all the data we need
if(!is_dir($amp_conf['ASTVARLIBDIR']."/sounds/magic-button")) {
        mkdir($amp_conf['ASTVARLIBDIR']."/sounds/magic-button");
}
?>
