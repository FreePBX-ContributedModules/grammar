#!/usr/bin/php
<?php
//error_reporting(E_ALL);
error_reporting(0);
//uncomment next line to debug the agi's
//set_error_handler("error_handler");
function error_handler($errno, $errstr, $errfile, $errline, $errcontext){
	if ($errno == 2048){return;} //keeps out erros related to pear::db
	$error = "\n\n";
	$error .= date("Y-M-d H:i:s").$disc."\n"; //add timestamp
	$error .= 'Error Number '.$errno.": ".$errstr."\n";
	$error .= 'In file: '.$errfile."\n";
	$error .=  'At line number: '.$errline."\n";
	//$error .=  print_r($errcontext)."\n";
	file_put_contents('/tmp/freepbx_debug.log',$error, FILE_APPEND);
}
require_once "DB.php";

$amp_config = parse_amportal_conf("/etc/amportal.conf");

$asm_user = $amp_config['AMPMGRUSER'];
$asm_secret = $amp_config['AMPMGRPASS'];
$asm_server = "localhost";

$db_engine = $amp_config['AMPDBENGINE'];
$db_user = $amp_config['AMPDBUSER'];
$db_pass = $amp_config['AMPDBPASS'];
$db_host = $amp_config['AMPDBHOST'];
$db_database = 'asterisk';

$db_url = "$db_engine://$db_user:$db_pass@$db_host/$db_database";
global $db;
$db = DB::connect($db_url);

$sql = "SELECT * FROM grammar_config";
$result = $db->query($sql);
$db_config = $result->fetchRow(DB_FETCHMODE_ASSOC);

$tts_engine = $db_config['tts_engine'];
$default_location = $db_config['zip_code'];
$aastra_support = ($db_config['aastra_support'] == "1"?true:false);


include("magic-button/MagicButton.class.php");

function parse_amportal_conf($filename) {
	$file = file($filename);
	foreach ($file as $line) {
		if (preg_match("/^\s*([a-zA-Z0-9]+)\s*=\s*(.*)\s*([;#].*)?/",$line,$matches)) {
			$conf[ $matches[1] ] = $matches[2];
		}
	}
	return $conf;
}

$magic_button = new MagicButton($asm_server, $asm_user, $asm_secret, $tts_engine, $aastra_support, $default_location, $exchange_server);
