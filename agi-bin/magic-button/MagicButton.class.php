<?php // $Id: MagicButton.class.php 13747 2011-09-29 16:20:45Z tony.lewis $

error_reporting(E_ALL);
//error_reporting(1);
require_once("/var/lib/asterisk/agi-bin/phpagi.php");
require_once("/var/lib/asterisk/agi-bin/magic-button/MagicVoicemail.class.php");

require_once("/var/lib/asterisk/agi-bin/magic-button/MagicWeather.class.php");
require_once("/var/lib/asterisk/agi-bin/magic-button/MagicHelpers.class.php");
// For Aastra Away button and iSymphony integration, we use our presence class to do the heavy lifting
include_once('/var/www/html/admin/modules/presence/presence.class.php');

$MagicButton = true;


class MagicButton  {
	var $asmServer;
	var $asmUser;
	var $asmPassword;
	var $database;
	var $tts_engine;
	// zip code of default location
	var $default_location;
	var $aastra_support;

	var $asm;
	var $agi;

	var $function;
	var $extension;
	var $description;
	var $extraoptions;
	var $action;

	var $channel;
	var $peer;

	var $away;

	// Constructor
	function __construct($asmServer, $asmUser, $asmPassword, $tts_engine, $aastra_support, $default_location)  {

		$this->asmServer = $asmServer;
		$this->asmUser = $asmUser;
		$this->asmPassword = $asmPassword;
		$this->tts_engine = $tts_engine;
		$this->aastra_support = $aastra_support;
		$this->default_location = $default_location;
		if(class_exists('Presence')){
			$this->pres = new Presence();
		}
		pcntl_signal(SIGHUP,  array($this,"sig_handler"));  // catch hangups
		$this->pres->dbug('1');

		$this->agi = new AGI();
		$this->manager_connect();
		$this->pres->dbug('2');
		//Connect to the database
		$this->database = $this->__construct_db();

		$this->extension = $this->agi_get_lumenvox("EXTENSION");
		$this->action = $this->agi_get_lumenvox("FUNCTION");
		$this->description = $this->agi_get_lumenvox("DESCRIPTION");

		$options = $this->agi_get_lumenvox("EXTRAOPTIONS");
		if($options)  {
			$t = explode("&",$options);
			foreach($t as $option)  {
				list($key,$val) = split("=",$option);
				$this->extraoptions[$key] = $val;
			}
		}

		$current_channel = $this->agi_get_variable("CHANNEL");
		$transfer_context = $this->agi_get_variable("TRANSFER_CONTEXT");

		// This means we have a peer
		if(preg_match('/'.$transfer_context.'/',$current_channel))  {
			$this->discover_peer($current_channel);
		}	else {  // This call was initited locally so there is no peer.  The CHANNEL variable is correct
			$this->channel = $current_channel;
		}

		if(version_compare(phpversion(), "5", "<")) {
			register_shutdown_function(array(&$this, "__destruct"));
		}

    $action = "action_".$this->action;
    $this->$action();
    $this->pres->dbug('3');
	} // end constructor

	//get database handle, called by __construct()
	// TODO: hardcoded mysql, deal with sqlite...
	//
	function __construct_db(){
		require_once("DB.php");
		$dsn=array(
			'phptype'  => $this->agi_get_variable('AMPDBENGINE'),
			'hostspec' => $this->agi_get_variable('AMPDBHOST'),
			'database' => $this->agi_get_variable('AMPDBNAME'),
			'username' => $this->agi_get_variable('AMPDBUSER'),
			'password' => $this->agi_get_variable('AMPDBPASS'),
		);
		$db=DB::connect($dsn);
		return $db;
	}
	function __destruct()  { // destructor

		foreach($this->cleanup_functions as $cleaner) {
			$func = $cleaner['function'];
			if($cleaner[params])
			$this->$func($cleaner[params]);
			else
			$this->$func();
		}
		exit;
	}
	function discover_peer($current_channel) {
		// This is an ugly way to find the real channel and peer coming out of a atxfer
		// Unfortunately, the channel variables and manager Status command don't link
		// together the channels properly, so we have to parse the links ourselves.
		// You may wonder why I didn't use applicationmaps instead of atxfer.
		// Initially I did.  The problem was that it was impossible to control both
		// legs of the channel, specifically for putting the peer on hold while
		// speech recognition was going on.

		$actionid = md5(time()); // TODO: use this actionid for safety
		$channels = $this->asm->Command("core show channels concise",$actionid);

		$lines = explode("\n",$channels[data]);

		foreach($lines as $line)  {
			unset($channel_arr);
			if(substr_count($line, '!') == 13)  {
				$channel_arr = explode("!",$line);
				if($channel_arr[13] != "(None)")  {
					//Array format:  Array("unique channel id" => array("peer") => "peer"))
					$channel[$channel_arr[0]]["peer"] = $channel_arr[13];
				}
			}
		}
		while(list($key,$val) = each($channel))  {
			if($val[peer] == $current_channel)  {
				$real_peer = $key;
			}
			else if(str_replace(";1",";2",$key) == $current_channel)  {
				$real_channel = $val[peer];
			}
			// If user received an inbound call then pushed the magic button
			if($key == $real_channel)  {
				$real_peer = $val[peer];
			}
			// If user made an outbound call then pushed the magic button
			if($val[peer] == $real_channel)  {
				$real_peer = $key;
			}
		}
		$this->channel = $real_channel;
		$this->peer = $real_peer;
	}
	function sig_handler($sig)  { // catch hangups and still cleanup

		//    $this->__destruct();
	}
	function manager_connect()  {

		$this->asm = new AGI_AsteriskManager();
		$this->asm->connect($this->asmServer, $this->asmUser, $this->asmPassword);
	}

	function clean_lumenvox($data)  {
		$data = str_replace("[object clsGeneral]","",$data); // Don't know what this is but it crops up now and again
		$data = trim(iconv("UTF-8","UTF-8//IGNORE",$data)); // sometimes LV returns binary

		return $data;
	}

	function agi_get_variable($variable)  {
		$tmp = $this->agi->get_variable($variable);
		//Fix for phpagi bug which means the first variable call is always null, ticket can be found at http://sourceforge.net/tracker/?func=detail&aid=1996081&group_id=106629&atid=645057
		$tmp = $this->agi->get_variable($variable);
		//$this->pres->dbug('$tmp '.$variable,$tmp);
		return $tmp['data'];
	}

	function agi_get_lumenvox($variable)  {
		$tmp = $this->agi_get_variable($variable);
		return $this->clean_lumenvox($tmp);

	}

	function say_digits($digits)  {
		$this->agi->exec("SayDigits",$digits);

	}
	function say_time($time){
		$this->agi->exec("SayUnixTime",$time);
	}
	function play_sound($sound_file)  {
		$this->agi->exec("Playback",$sound_file);
	}
	function tts($text)  {
		$this->agi->exec($this->tts_engine,"\"$text\"");

	}
	function set_cid()  {
		$this->agi->exec("Macro","user-callerid");
	}
	function speech_prompt($grammar,$prompt,$timeout="unlimited")  {

		if(preg_match("/builtin:/",$grammar))  {
			if (is_dir("/opt/lumenvox/engine/Lang/BuiltinGrammars")) {
				$grammar_dir = "/opt/lumenvox/engine/Lang/BuiltinGrammars";
			} else {
				$grammar_dir = "/etc/lumenvox/Lang/BuiltinGrammars";
			}
			$grammar = str_replace("builtin:","",$grammar);
		}	else {
			$grammar_dir = "/etc/asterisk/grammars";
		}

		if($timeout == "unlimited") {
			$timeout = "";
		}

		$grammar = str_replace(".gram","",$grammar);
		if (file_exists($grammar_dir . '/' . $grammar .'.gram')) {
			$gram_file = $grammar_dir . '/' . $grammar .'.gram';
		} elseif(file_exists($grammar_dir . '/' . $grammar)) {
			$gram_file = $grammar_dir . '/' . $grammar;
		} else {
			//crash & burn
		}

		$this->agi->exec("SpeechCreate");



		$this->agi->exec("SpeechLoadGrammar $grammar,$gram_file");
		$this->agi->exec("SpeechActivateGrammar $grammar");
		$this->agi->exec("SpeechStart");
		do  {
			$speech_background = "SpeechBackground $prompt";
			if($timeout)
			$speech_background .= ",$timeout";
			$result = $this->agi->exec($speech_background);
			$score = (int)$this->agi_get_lumenvox("SPEECH_SCORE(0)");
			$text = $this->agi_get_lumenvox("SPEECH_TEXT(0)");
			if($score != 0 && $score < 600)  { // If no score, they didn't say anything, which is what we expect sometimes
				$this->play_sound("magic-button/sorry-couldnt-understand");
			}
		} while($score !=0 && $score < 600);
		$this->agi->exec('SpeechDeactivateGrammar');
		$this->agi->exec('SpeechDestroy');

		return $text;

	}
	function speech_prompt_password($grammar,$prompt,$password,$timeout="unlimited")  {
		while($spoken_password != $password && $count != 3)  {
			$count++;
			$spoken_password = $this->speech_prompt($grammar,$prompt,$timeout);

			if($spoken_password == $password)  {
				$this->play_sound("auth-thankyou");
				return true;
			}	else  {
				$this->play_sound("vm-incorrect");
			}
		}
		return false;
	}

	function announce_name($extension,$wait_cancel="",$return_only=null)  {
		$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");

		if(file_exists("$spool_dir/voicemail/default/$extension/greet.wav") ||
		file_exists("$spool_dir/voicemail/default/$extension/greet.WAV"))  {

			$greeting = "$spool_dir/voicemail/default/$extension/greet";

			if($wait_cancel || $return_only)
			return $greeting;
			else
			$this->play_sound($greeting);
			return;
		}	else if($return_only)  {
			return;  // there isn't a name recording.  That's all we need to know.
		}	else  {
			if($wait_cancel)  {
				$this->agi->exec("SayDigits",substr($extension,0,strlen($extension)-1)); // say all digits except for the last
				return "digits/".substr($extension,-1,1); // return the last digit to be said by SpeechBackground
			}	else  {
				$this->say_digits($extension);
			}
		}
	}

	function announce_wait_cancel($exten)  {
		$sound_file = $this->announce_name($exten,true);

		$spoken = $this->speech_prompt("cancel",$sound_file,3);
		if($spoken == "cancel")
		exit;
		return;
	}

	function remove_temporary_message($exten,$silent=false)  {
		if($this->check_for_voicemail_box($exten,true))  {
			$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");
			$vmail_temp = $spool_dir."/voicemail/default/$exten/temp";
			$rm_temp = `rm -f $vmail_temp.*`;
		}
	}
	function record_temporary_message($exten)  {
		$exten = $this->who_am_i();
		if($this->check_for_voicemail_box($exten))  {
			$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");
			$vmail_temp = $spool_dir."/voicemail/default/$exten/temp";
			$this->agi->record_file($vmail_temp, "wav", "#", -1, "", TRUE, 2);
			$create_wav49 = `/usr/bin/sox $vmail_temp.wav -c 1 -r 8000 -g -t wav $vmail_temp.WAV`;
			$this->play_sound("auth-thankyou&magic-button/temporary-message-saved");

		}	else  {
			$this->play_sound("magic-button/temporary-message-restricted");
		}
	}

	function record_greeting($exten)  {
		if($this->check_for_voicemail_box($exten))  {
			$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");
			$vmail_unavail = $spool_dir."/voicemail/default/$exten/unavail";
			$this->agi->record_file($vmail_unavail, "wav", "#", -1, "", TRUE, 2);
			$create_wav49 = `/usr/bin/sox $vmail_unavail.wav -c 1 -r 8000 -g -t wav $vmail_unavail.WAV`;
			$this->play_sound("auth-thankyou&magic-button/vm-greeting-saved");
		}	else {
			$this->play_sound("magic-button/vm-cannot-record-greeting");
		}
	}
	function record_name($exten)  {
		if($this->check_for_voicemail_box($exten))  {
			$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");
			$vmail_name = $spool_dir."/voicemail/default/$exten/greet";
			$this->agi->record_file($vmail_name, "wav", "#", -1, "", TRUE, 1);
			$create_wav49 = `/usr/bin/sox $vmail_name.wav -c 1 -r 8000 -g -t wav $vmail_name.WAV`;
			$this->play_sound("auth-thankyou&magic-button/vm-name-recording-saved");
		}	else {
			$this->play_sound("magic-button/vm-cannot-record-name");
		}
	}

	function check_for_voicemail_box($exten,$silent=false,$self=true,$context="default")  {
		$cmd = $this->agi->exec("MailboxExists",$exten."@".$context);
		$vmexists = $this->agi_get_variable("VMBOXEXISTSSTATUS");
		//not sure why we need two calls here, but the first never seems to get set... -MB
		$vmexists = $this->agi_get_variable("VMBOXEXISTSSTATUS");

		if($vmexists == "SUCCESS")  {
			return true;
		}	else if(!$silent) {
			if($self == true)
			$this->play_sound("magic-button/vm-you-no-box");
			else
			$this->play_sound("magic-button/vm-no-box");
		}
		return false;
	}

	function get_voicemail_password($exten)  {
		$conf = $this->agi_get_variable("ASTETCDIR")."/voicemail.conf";
		$vm_users = file($conf);
		foreach($vm_users as $line)  {
			if(preg_match("/^$exten => ([0-9]+),/",$line,$regs))
			$password = $regs[1];
		}
		return $password;
	}
	function get_groups($group)  {
		if($group == "vmblast")
		$regex = "(.*)(\{out=\")([0-9&]+)_(.*)_";
		else
		$regex = "(.*)(\{out=\")([0-9&]+)_(.*)\"";

		$file = file("/etc/asterisk/grammars/$group.gram");
		$index = 0;
		for($i = 0; $i < sizeof($file); $i++)  {
			preg_match('/'.$regex.'/',$file[$i],$regs);
			if($regs[3] && $regs[4])  {
				$groups[$index][group] = $regs[3];
				$groups[$index][description] = $regs[4];
				unset($regs);
				$index++;
			}
		}
		return $groups;
	}

	//TODO: I doubt were still going to be using this... -MB
	function setting_exists($exten,$setting,$family,$key)  {
		if($setting == "home" || $setting == "cell")  {
			$dest_t = $this->agi->database_get("AMPUSER",$key);
			$dest = $dest_t[data];

			if($dest)  {
				return $dest;
			}	else  {
				if($setting == "cell")
				$this->play_sound("magic-button/mobile-number-set");
				if($setting == "home")
				$this->play_sound("magic-button/no-mobile-set");

				$response = $this->speech_prompt("yesno","magic-button/would-you-like-to-set");
				if($response == "yes")  {
					// We may temporarily override these and will need to get them back
					$extension_tmp = $this->extension;
					$description_tmp = $this->description;

					$number_confirmed = false;
					while(!$number_confirmed && $count != 3)  {
						$count++;
						$phone_number = $this->speech_prompt("phone_numbers","magic-button/say-phone-number");
						$this->say_digits($phone_number);
						$confirm_phone = $this->speech_prompt("yesno","magic-button/is-this-correct");
						if($confirm_phone == "yes")  {
							$number_confirmed = true;              //temporarily set these variables for the action_set function
							$this->description = $setting;
							$this->extension = $phone_number;
							// Make these settings last
							$this->action_set();
						}
					}
					// Chage them back
					$this->description = $description_tmp;
					$this->extension = $extension_tmp;
					if($confirm_phone == "false")  {
						$this->play_sound("magic-button/sorry-couldnt-understand");
						return false;
					}
					else  {
						return $phone_number;
					}
				}
				else  { // user didn't want to set their home/cell number
					return false;
				}
			}
		} // end home/cell settings
		return false;
	} // end function setting_exists()

	function run_background()  {
		$args = func_get_args();
		$script = $args[0];
		unset($args[0]);
		if(is_array($args)) {
			$args = implode(" ",array_vaules($args));
		}
		system("(/usr/bin/php /var/lib/asterisk/agi-bin/magic-button/$script $args) >/dev/null &");
	}

	//What dose this do? -mb
	function status($for,$extension)  {
		if($for == "dnd") {}
		if($for == "cfwd") {}
		if($for == "presence") {}
	}

	function who_am_i()  {
		$this->set_cid();
		$exten = $this->agi_get_variable("WHOAMI");
		if(!$exten)
		$exten = $this->agi_get_variable("AMPUSER");
		return $exten;
	}

	//cleanup functions will be executed in the destructor, allowing for event-specific cleanup to happen
	function register_cleanup_function($cleaner,$params=array())  {
		$this->cleanup_functions[] = array("function" => $cleaner, "params" => $params);

	}
	function cleanup_voicemail($params)  {}

	function auto_dial($channel,$context,$extension,$priority,$retries=null,$retrytime=null,$waittime=30)  {
		$call_file = "channel: $channel\n";
		if($retries)  {
			$call_file .= "maxretries: $retries\n";
			if($retrytime) {
				$call_file .= "retrytime: $retrytime\n";
			}
		}
		$call_file .= "waittime: $waittime\n".
                  "context: $context\n".
                  "extension: $extension\n".
                  "priority: $priority";
		$file = md5(time()).".call";
		file_put_contents("/tmp/$file",$call_file);
		rename("/tmp/$file","/var/spool/asterisk/outgoing/$file");
	}

	function action_call()  {
		if(preg_match("/Call|Dial|extension/",$this->extension))  {
			$this->extension = preg_replace("/[^0-9]/","",$this->extension);
		}
		$this->extension = preg_replace("/[^0-9]/","",$this->extension);

		$this->play_sound("magic-button/calling");
		$this->announce_name($this->extension);

		$dial_string = $this->extension."@from-internal";
		$this->set_cid();
		$this->agi->exec("Wait","1");
		$this->agi->exec_dial("Local", $dial_string,null,null,null);

	}

	function action_intercom()  {
		if(preg_match("/Intercom/",$this->extension))  {
			$this->extension = preg_replace("/[^0-9]/","",$this->extension);
		}
		$this->play_sound("magic-button/intercom");
		$this->announce_name(str_replace("*80","",$this->extension));

		$this->set_cid();
		$dial_string = "*80".$this->extension."@from-internal";
		$this->agi->exec_dial("Local", $dial_string,null,null,null);
	}
	function action_park()  {
		$this->asm->send_request('Park', array('Channel'=>$this->peer, 'Channel2'=>$this->channel,'Timeout'=>0));
		//$this->agi->set_variable("DONTHANGUP","true");
	}
	function action_transfer2voicemail()  {

		$this->play_sound("magic-button/transfer-to-voicemail");
		$this->announce_name($this->extension);

		$dial_string = "*".$this->extension;
		$res = $this->asm->Redirect($this->peer, null, $dial_string, "ext-local", 1);
		$this->agi->set_variable("DONTHANGUP","true");

	}

	function action_transfer()  {
		$this->set_cid();

		$this->play_sound("magic-button/transferring-to");
		$this->announce_name($this->extension);

		// Default to attended transfer.  If they don't want it attended, they can hang up
		$dial_string = $this->extension."@from-internal";
		$this->agi->exec_dial("Local", $dial_string,null,null,null);

		// since we are already in atxfer, we don't need to redirect the channel.  We can just dial it
		// and if the magic button user disconnects before the party answers, it becomes a blind transfer!

		//    $this->asm->Redirect($this->peer, null, $this->extension, "default", 1);
		//    $this->agi->set_variable("DONTHANGUP","true");

	}
	function action_retrievecalls()  {

		if($this->extension == "list")  {
			$res = $this->asm->Command('parkedcalls show');
			$line= split("\n", $res['data']);
			$index=0;
			$count=0;
			foreach ($line as $myline)  {
				if((!strstr($myline,"Privilege")) && (!strstr($myline,"Extension")) && (!strstr($myline,"parked")) && ($myline!=""))  {
					$linevalue= preg_split("/ /", $myline,-1,PREG_SPLIT_NO_EMPTY);
					if($linevalue[0] != "")  {
						$park[$count][slot] = $linevalue[0];
						$res_i = $this->asm->Command('show channel '.$linevalue[1]);
						$line_i= @split("\n", $res_i['data']);
						foreach ($line_i as $myline_i)  {
							if (strstr($myline_i,"Caller ID:"))
							$park[$count][cid]=substr(substr(strrchr($myline_i,":"),1),1);
							if (strstr($myline_i,"Caller ID Name"))
							$park[$count][cidname]=substr(substr(strrchr($myline_i,":"),1),1);
						}
						$count++;
					}
				}
				$index++;
			}
			if(sizeof($park) == 0)  {
				$this->play_sound("magic-button/there-are-no-parked-calls");
			}
			else  {
				$this->play_sound("magic-button/parked-calls-available");
				for($i = 0; $i < sizeof($park); $i++)  {
					$this->play_sound("custom/parked-call");
					$slot = $park[$i][slot];
					$this->say_digits($slot);
					$cidname = $park[$i][cidname];
					if($cidname && $cidname != "")
					$this->tts(",,,$cidname");
					$cid = $park[$i][cid];
					$this->say_digits($cid);

				}
			}
		}
		else  {  // They asked to retrieve a specific park slot
			$this->play_sound("magic-button/retrieving-parked-call");
			$this->say_digits($this->extension);
			$this->asm->Redirect($this->channel, null, $this->extension, "parkedcalls", 1);
		}
	}

	function action_informational()  {
		if($this->extension == "ringgroups" || $this->extension == "paging" || $this->extension == "vmblast")  {
			if($this->extension == "vmblast")
			$message = "magic-button/you-can-message-groups";
			else if($this->extension == "ringgroups")
			$message = "magic-button/you-can-call-groups";
			else if($this->extension == "paging")
			$message = "magic-button/you-can-page-groups";
			$this->play_sound($message);
			$groups = $this->get_groups($this->extension);
			for($i = 0; $i < sizeof($groups); $i++)  {
				$this->tts($groups[$i][description]);
			}
		}
		else if($this->extension == "time")  {

			$this->play_sound("current-time-is");
			$this->agi->exec("SayUnixTime",",,IMP \'digits/at\'");
		}
		else if($this->extension == "date")  {
			$this->play_sound("today&is");
			$this->agi->exec("SayUnixTime",",,ABdY \'digits/at\'");
		}
		else if($this->extension == "help" || $this->extension == "introduction")  {
			if($this->extension == "introduction")  {
				$this->play_sound("magic-button/magic-intro_MIXDOWN");
			} else {
				$help_sounds = "magic-button/you-can-say1&magic-button/you-can-say2&magic-button/you-can-say3&magic-button/you-can-say4&".
                       "magic-button/you-can-say5&magic-button/you-can-say6&magic-button/you-can-say7";
				$this->play_sound($help_sounds);
			}
		}
		else if($this->extension == "weather")  {
			$this->play_sound("magic-button/weather-please-wait");
			$weather = new MagicWeather($this->default_location);
			$data = $weather->get_current_conditions();
			$location = $data[location];
			$this->play_sound("magic-button/weather-default-location");
			$this->tts($location);
			$new_location = $this->speech_prompt("weather","magic-button/weather-change-location","5");
			if($new_location == "changelocation")  {
				$new_location = $this->speech_prompt("weather","magic-button/weather-say-zip","5");
			}
			if(preg_match("/([0-9]{5})/",$new_location))  {
				$this->play_sound("magic-button/weather-please-wait");
				$weather->MagicWeather($new_location);
				$data = $weather->get_current_conditions();
			}
			$text = "Current conditions for $data[location], at $data[updated] the temperature was $data[temperature],".
               "conditions were $data[conditions].";
			$this->tts($text);
			$repeat = $this->speech_prompt("weather","magic-button/weather-repeat","5");
			if($repeat == "repeat")
			$this->tts($text);
		}
	}
	function action_dnd()  {
		$exten = $this->who_am_i();
		if($this->extension == "enable")  {
			$res = $this->asm->Command('database put DND '.$exten.' YES');
			$res = $this->asm->SetVar('','DEVSTATE(Custom:DEVDND'.$exten.')','INUSE');
			$this->play_sound("do-not-disturb&activated");
		}
		else  {
			$res = $this->asm->Command('database del DND '.$exten);
			$res = $this->asm->SetVar('','DEVSTATE(Custom:DEVDND'.$exten.')','NOT_INUSE');
			$this->play_sound("do-not-disturb&de-activated");
		}
		if($this->aastra_support)  {
			$this->sip_notify("aastra-xml",$exten);

		}
	}

	function action_cfwd()  {
		$exten = $this->who_am_i();
		if($this->extension == "disable")  {
			$res = $this->asm->Command('database del CF '.$exten);
			$this->play_sound("call-fwd-unconditional&de-activated");
		}	else  {
			$dest = $this->extension;

			if($this->extension == "home" || $this->extension == "cell")  {
				$key = "$exten/presence/".$this->extension;
				$dest = $this->setting_exists($exten,$this->extension,"AMPUSER",$key);
				if(!$dest)  {
					$this->play_sound("magic-button/cfwd-not-set");
					return false;
				}
			}
			$this->play_sound("call-fwd-unconditional&for&extension");
			$this->say_digits($exten);
			$this->play_sound("is-set-to");

			if(!preg_match("/[^0-9]/",$dest))  { // only numbers
				$this->say_digits($dest);
				$res = $this->asm->Command('database put CF '.$exten.' '.$dest);

			}
			else  {
				$this->tts($dest);
			}
		}
		if($this->aastra_support)  {
			$this->sip_notify("aastra-xml",$exten);
		}

	}
	function action_paging()  {
		if($this->description == "no groups")  {
			$this->play_sound("magic-button/no-page-groups");
		}
		else  {
			$this->play_sound("magic-button/paging");
			$this->tts($this->description);
			$this->play_sound("magic-button/after-beep");

			sleep(2);
			$dial_string = $this->extension."@from-internal";
			$this->agi->exec_dial("Local", $dial_string,null,null,null);
		}
	}
	function action_groups()  {
		if($this->description == "no groups")  {
			$this->play_sound("magic-button/no-ring-groups");
		}
		else  {
			$this->play_sound("magic-button/calling-group");
			$this->tts($this->description);

			$dial_string = $this->extension."@from-internal";
			$this->agi->exec_dial("Local",$dial_string,null,null,null);
		}
	}

	//this should probly be depreciated, as we dont use these features -MB
	function action_set()  {
		$exten = $this->who_am_i();
		$key = "$exten/presence/".$this->description;
		$this->asm->database_put("AMPUSER",$key,$this->extension);

		if($this->description == "cell")  {
			$message = "magic-button/mobile-number-set";
		}
		if($this->description == "home")  {
			$message = "magic-button/home-number-set";
		}
		$this->play_sound($message);
		$this->say_digits($this->extension);

	}

	function action_presenceset()  {
	$this->pres->dbug('4');
		$exten = $this->who_am_i();
		$foo=$this->pres->set($exten,'status',$this->extension);

		if ($this->pres->stat[$this->extension]['recording']) {
			$recording = $this->pres->stat[$this->extension]['recording'];
			$sql = 'SELECT filename FROM recordings WHERE id=?';
			$file = $this->database->getOne($sql, array($recording));
			$this->pres->dbug('5',$file);
			$this->play_sound("your-new-status-is&$file");
		}
		 //Only present option for setting return date if status == busy
		if($this->pres->stat[$this->extension]['type'] == 'busy') {
			if ($this->check_for_voicemail_box($exten, true))  {
				$response = $this->speech_prompt("yesno","magic-button/temporary-message-record");
				if($response == "yes")  {
					$this->record_temporary_message($exten);
				}
			}

		//promt user for return time
		$year = date("Y");
		$month = date("m");
		$day = date("d");
		$hour = 0;
		$minute = 0;

		$responsedate = $this->speech_prompt("yesno","magic-button/return-date-prompt");
		if($responsedate == "yes")  {
			$response = $this->speech_prompt("builtin:ABNFDate","beep");
			$retyear = substr($response,0,4);
			$retmonth = substr($response,4,2);
			$day = substr($response,6,2);
			if($retyear != "????"){
				$year = $retyear;;
			}
			if($retmonth != "??"){
				$month = $retmonth;
			}
			$this->pres->dbug('return date',$year.'-'.$month.'-'.$day);
		}

		$responsetime = $this->speech_prompt("yesno","magic-button/return-time-prompt");
		if($responsetime == "yes")  {
			$response = $this->speech_prompt("builtin:ABNFTime","beep");
			$hour = (int)substr($response,0,2);
			$minute = (int)substr($response,2,4);
			$this->pres->dbug('return time',$hour.':'.$minute);

		}
		if ($responsedate == 'yes'|| $responsetime == 'yes') {
			$return = mktime($hour, $minute, '00', $month, $day, $year);
			$this->pres->set($exten,'return','time',$return);
			$this->say_time($return);
		}
		$this->play_sound("auth-thankyou");
		}
	}

	function action_presenceget()  {
		$this->pres->myuser = $this->extension;
		//get presence from engine

		if(!$this->pres->my['status'])  {
			$where = "is-not-set";
		}else {
			$recording = $this->pres->stat[$this->pres->my['status']]['recording'];
			$sql = 'SELECT filename FROM recordings WHERE id=?';
			$where = $this->database->getOne($sql, array($recording));
		}

		$this->announce_name($this->extension);

		$this->play_sound('status&'.$where);
		//get return timeß
		if($this->pres->my['status'] && (int)$this->pres->my['return']['time'])  {
			$this->play_sound("magic-button/until");
			$this->say_time($this->pres->my['return']['time']);
		}

		if ($this->pres->my['status']
			&& $this->pres->stat[$this->pres->my['status']]['type'] != 'busy')  {
			$response = $this->speech_prompt("yesno","magic-button/would-you-like-to-connect");
			if($response == "yes")  {
				$this->action_call();
			}
		}	else {
			$response = $this->speech_prompt("yesno","magic-button/notified-upon-return");
			if($response == "yes")  {
				$exten = $this->who_am_i();
				$watchers = ($this->pres->my['return']['watchers'] != 't' )
							? $this->pres->my['return']['watchers'].',' : '';
				$this->pres->set($this->extension,'return','watchers',$watchers.$exten);
				$this->play_sound("magic-button/you-will-be-notified");
				$this->announce_name($this->extension);
			}

		}
	}

	function action_vmblast()  {
		$this->play_sound("magic-button/record-group-message");
		$this->tts($this->description);
		if($this->extraoptions[password])  {
			if(!$this->speech_prompt_password("numbers","vm-password",$this->extraoptions[password],5))
			exit;
		}
		$blast_extensions = explode("&",$this->extension);

		for($i = 0; $i < sizeof($blast_extensions); $i++)  {
			$this->agi->exec("Macro","get-vmcontext",$blast_extensions[$i]);
			$vmcontext = $this->agi_get_variable("VMCONTEXT");
			$grplist[] = $blast_extensions[$i]."@".$vmcontext;
		}
		$voicemail_to = implode("&",$grplist);
		if($this->extraoptions[grpnum])
		$this->say_digits($this->extraoptions[grpnum]);
		if($this->extraoptions[recording])
		$this->play_sound("custom/".$this->extraoptions[recording]);
		$this->agi->exec("Voicemail","$voicemail_to");
	}

	function action_sendmessage()  {
		$this->play_sound("magic-button/leaving-message-for");
		$this->announce_name($this->extension);
		if($this->check_for_voicemail_box($this->extension,false,false))
		$this->agi->exec("Voicemail",array($this->extension,"s"));
	}

	function action_voicemail()  {
		$this->register_cleanup_function("cleanup_voicemail",array("test" => "test1234"));

		$exten = $this->who_am_i();
		if($this->extension == "removetemporarymessage")  {
			$this->remove_temporary_message($exten);
			$this->play_sound("magic-button/temporary-message-remove");
		}
		if($this->description == "check")  {
			$cmd = $this->agi->exec("Macro","get-vmcontext",$exten);
			$vmcontext = $this->agi_get_variable("VMCONTEXT");

			$vmail_box = $exten."@".$vmcontext;

			$this->check_for_voicemail_box($exten,false,true,$vmcontext);
			$vmail_password = $this->get_voicemail_password($exten);
			//if($this->speech_prompt_password("numbers","vm-password",$vmail_password,5))  {
			if(1==1)  {
				//$res = $this->agi->exec("HasNewVoicemail",$vmail_box);
				$vmail = new MagicVoicemail($exten);
				$new_messages = $vmail->get_messages("INBOX");
				$old_messages = $vmail->get_messages("Old");
				$this->agi->exec("Playback","vm-youhave");
				if(sizeof($new_messages) == 0)
				$this->agi->exec("Playback","vm-no");
				else
				$this->agi->exec("SayNumber",sizeof($new_messages));
				$play = "vm-INBOX";
				if(!sizeof($old_messages))  {
					if(sizeof($new_messages) == 1)
					$play .= "&vm-message";
					else
					$play .= "&vm-messages";
				}
				$this->agi->exec("Playback",$play);

				if(sizeof($old_messages) > 0)  {
					$this->agi->exec("Playback","vm-and");
					$this->agi->exec("SayNumber",sizeof($old_messages));
					$play = "vm-Old";
					if(sizeof($old_messages) == 1)
					$play .= "&vm-message";
					else
					$play .= "&vm-messages";
					$this->agi->exec("Playback",$play);
				}
				do  {
					if(sizeof($new_messages) && sizeof($old_messages))  {
						$prompt = "vm-new-old-change-help";
						$grammar = "voicemail_new_old_change_help";
					}
					else if(sizeof($new_messages))  {
						$prompt = "vm-new-change-help";
						$grammar = "voicemail_new_change_help";
					}
					else if(sizeof($old_messages))  {
						$prompt = "vm-old-change-help";
						$grammar = "voicemail_old_change_help";
					}
					else  {
						$prompt = "vm-change-help";
						$grammar = "voicemail_change_help";
					}
					$response = $this->speech_prompt($grammar,"magic-button/$prompt","5");
					if($response == "newmessages" || $response == "oldmessages" || $response == "changefolders")  {
						if($response == "changefolders")  {
							$folder = false;
							while($folder != "INBOX" && $folder != "Old" && $folder != "Family" && $folder != "Work" && $folder != "Friends")  {
								$folder = $this->speech_prompt("voicemail_folders","magic-button/vm-folder-choices","5");
								if($folder)  {
									$this->play_sound("magic-button/vm-folder-$folder");
									$messages = $vmail->get_messages($folder);
								}
								else
								$this->agi->exec("Playback","magic-button/sorry-couldnt-understand");
							}
						}
						if($response == "newmessages")  {
							$this->play_sound("magic-button/vm-folder-INBOX");
							$folder = "INBOX";
							$messages = $new_messages;
						}
						if($response == "oldmessages")  {
							$this->play_sound("magic-button/vm-folder-Old");
							$folder = "Old";
							$messages = $old_messages;
						}
						//            for($i = 0; $i < sizeof($messages); $i++)  {
						$i = 0;
						while(sizeof($messages) && $i != sizeof($messages))  {

							$saymessagenumber = false;
							if($i == 0 && sizeof($messsages) != 1) // first message, but not only message
							$play = "vm-first&vm-message";
							else if($i != 0 && $i == sizeof($messages)-1) // last message but not only message
							$play = "vm-last&vm-message";
							else  { // play "Message 1", "Message 2", etc
								$play = "vm-message";
								$saymessagenumber = true;
							}
							$this->agi->exec("Playback",$play);
							if($saymessagenumber)
							$this->agi->exec("SayNumber",$i+1);
							if($vmail->user_conf["saycid"] == "yes")  {
								if($messages[$i][cidnumber])  {
									$this->play_sound("vm-from");
									$this->announce_name($messages[$i][cidnumber]);
								}
							}
							if($vmail->user_conf[envelope] == "yes")  {
								if($messages[$i][origtime])  {
									$this->agi->exec("SayUnixTime","$messages[$i][origtime]||\"ABdY \'digits/at\' IMP\"");
								}
							}
							$spool_dir = $this->agi_get_variable("ASTSPOOLDIR");
							$file = $spool_dir."/voicemail/default/$exten/$folder/msg".$messages[$i][file];

							// Play message and allow a user to say "rewind, fast forward, or delete"
							$timer = mktime();

							$breakout = $this->speech_prompt("voicemail_message",$file,"1");

							$endtimer = mktime();
							while($breakout == "delete" || $breakout == "fastforward" || $breakout == "rewind")  {
								if($breakout == "delete")  {
									$confirm_delete = $this->speech_prompt("yesno","magic-button/vm-confirm-delete",0);
									if($confirm_delete == "yes")  {
										$this->play_sound("magic-button/vm-deleted");
										$vmail->delete_message($folder,$messages[$i][file]);
										unset($messages[$i]);
										$messages = array_values($messages);
										if($i != 0)
										$i -= 1;
									}
									else
									$this->play_sound("magic-button/vm-not-deleted");
									break;
								}
								else if($breakout == "rewind" || $breakout == "fastforward")  {
									$breakout_time = $endtimer-$timer-1;
									if($breakout_time < 0)
									$breakout_time = 0;
									$time_shifted += $breakout_time;

									if($breakout == "rewind")  {
										$trim_time = $time_shifted-5;
										$time_shifted = $trim_time;
									}
									else  {
										$trim_time = $time_shifted+5;
										$time_shifted = $trim_time;
									}
									$hash = md5(time());
									$cmd = `/usr/bin/sox $file.wav /tmp/$hash.wav trim $trim_time`;
									$audio_length = `sox /tmp/$hash.wav -e stat 2>&1 >/dev/null|grep Length`;
									list(,$length) = split(":",$audio_length);
									$length = round(trim($length));
									$breakout = false;
									$timer = mktime();
									$breakout = $this->speech_prompt("voicemail_message","/tmp/$hash","1");
									$endtimer = mktime();
								}
								else  {
									break;
								}
							} // end while playing message and listening for rewind, fast-forward, delete
							if($i+1 != sizeof($messages))  {  // this is not the last message
								if($i > 0)  {  // this is not the last message and also not the first message
									$prompt = "vm-next-previous-repeat-move-delete";
									$grammar = "voicemail_endmessage_next_previous_delete";
								}
								else  {  // this is the first message
									$prompt = "vm-next-repeat-move-delete";
									$grammar = "voicemail_endmessage_next_delete";
								}
							}
							else if($i != 0){ // this is the last message and not the only message
								$prompt = "vm-previous-repeat-move-delete";
								$grammar = "voicemail_endmessage_previous_delete";
							}
							else  {
								$prompt = "vm-delete-repeat-move";
								$grammar = "voicemail_endmessage_delete";
							}
							//TODO: must move listened to message to Old folder, yet be able to go back to it (temp?
							$action_message = $this->speech_prompt($grammar,"magic-button/$prompt","5");
							if($action_message == "deletemessage" || $action_message == "movemessage")  {
								if($action_message == "deletemessage")  {
									$confirm_delete = $this->speech_prompt("yesno","magic-button/vm-confirm-delete");
									if($confirm_delete == "yes")  {
										$this->play_sound("magic-button/vm-deleted");
										$vmail->delete_message($folder,$messages[$i][file]);
										unset($messages[$i]);
										$messages = array_values($messages);
									}
									else  {
										$this->play_sound("magic-button/vm-not-deleted");
										$i++;
									}
								}
								else  { // move message
									while(!$tofolder)  {
										$tofolder = $this->speech_prompt("voicemail_folders","magic-button/vm-folder-choices","5");
										if($tofolder)  {
											$this->play_sound("magic-button/vm-folder-$folder");
											$messages = $vmail->move_message($message, $folder, $tofolder);
										}
										else  {
											$this->agi->exec("Playback","magic-button/sorry-couldnt-understand");
										}
									}
								}
							}
							else if($action_message == "repeatmessage")  {
								// don't inrement or decrement counter
							}
							else if($action_message == "nextmessage")  {
								$i++;  // increment message counter
							}
							else if($action_message == "previousmessage")  {
								$i--;
							}
							else  { // no response
								$i++;
							}
						} // end loop playing available messages in a folder
						$this->play_sound("vm-nomore");
					} // End response of new messages, old messages or change folder
					$new_messages = $vmail->get_messages("INBOX");
					$old_messages = $vmail->get_messages("Old");

				} while ($response);
			} // End verify password
		} // End check voicemail
	}
	function action_vmailrecord()  {
		$exten = $this->who_am_i();
		if($this->extension == "name")
		$this->record_name($exten);
		if($this->extension == "greeting")
		$this->record_greeting($exten);
		if($this->extension == "temporarygreeting")
		$this->record_temporary_message();

	}
	function action_remoteauthenticate()  {
		$exten = $this->speech_prompt("numbers","vm-extension", 5);
		$cmd = $this->agi->exec("Macro","get-vmcontext",$exten);
		$vmcontext = $this->agi_get_variable("VMCONTEXT");

		$vmail_box = $exten."@".$vmcontext;

		$this->check_for_voicemail_box($exten,false,true,$vmcontext);
		$vmail_password = $this->get_voicemail_password($exten);
		if($this->speech_prompt_password("numbers","vm-password",$vmail_password,5))  {
			$this->agi->set_variable("_AUTHORIZED","true");
			$this->agi->set_variable("_WHOAMI",$exten);
		}

	}
	function action_notifyconnectme()  {
		$response = $this->speech_prompt("yesno","magic-button/would-you-like-to-connect",5);
		if($response == "yes")  {
			$this->set_cid();
			$this->action_call();
		}
	}

	function action_conference()  {
		$this->play_sound("conference-call");
		$this->announce_name($this->extension);
		$conference_room = $this->who_am_i()."2663"; // extension+"conf" on dialpad
		// Conference in our peer if there is one
		$this->discover_peer($this->channel);
		// Conference in our peer if there is one
		if($this->peer)  {
			$this->asm->Redirect($this->peer, null, $conference_room, "magic-button-conf", 1);
		}
		// Conference in the requested party through auto dial to meet me
		$channel = "Local/$conference_room@magic-button-conf";
		$this->auto_dial($channel,"from-internal",$this->extension,"1");
		//$this->agi->exec("MeetMe","$conference_room|Axqdz");
		$this->agi->exec_dial("Local", "$conference_room@magic-button-conf",null,null,null);

	}

}
?>
