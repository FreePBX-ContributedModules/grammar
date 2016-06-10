<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed');}

class grammar_conf {
    // Grammar files can't have comments that start with ';'!
    var $use_warning_banner = false;
    // return an array of filenames to write
    // files named like pinset_N
    function get_filename() {
        $files = array();
        if (isset($this->_grammars) && is_array($this->_grammars)) {
            $keys = array_keys($this->_grammars);
            foreach($keys as $file)  {
                $files[] = "grammars/$file.gram";
            }
            return $files;
        }
   		return $files;
    }

    function addGrammar($function,$vocabulary,$extension,$description=null,$extra_options=null) {
        // Strip non-alpha-numberic characters, as they will hose the grammar file
        $values = array();
		$vocabulary = preg_replace("/[^a-zA-Z0-9|() ]/","",$vocabulary);
        if($extension != "" && $extension != null) {
            $values[] = $extension;
        }
        if($extra_options)  {
            $values[] = $description;
            if($extra_options === true)  { // just leave a blank trailing _ for vmblast groups that don't have extra options
                $values[] = "";
        }
        else  {
            $values[] = $extra_options;
        }
    }
	else if($description)  {
        $values[] = $description;
    }
        $data = implode("_",$values);
        $this->_grammars[$function][] = array("vocabulary" => $vocabulary, "data" => $data);
    }

    // return the output that goes in each of the files
    function generateConf($file) {

        $grammar_header = <<<END
#ABNF 1.0;
mode voice;
language en-US;
tag-format <semantics/1.0.2006>;
END;

		preg_match("/\/(.*).gram/",$file,$regs);
		$grammar = $regs[1];

		$output = $grammar_header."\n\n";
		$output .= "root \$$grammar;\n\n";
		$output .= "\$$grammar = (\n\t  ";
		foreach($this->_grammars[$grammar] as $grammar_data)  {
			$data[] = $grammar_data['vocabulary']." {out=\"".$grammar_data['data']."\"}";
		}
		$output .= implode("\n\t| ",$data);
		$output .= "\n);";

        return $output;
    }
}
function grammar_get_config($engine) {
	global $ext;  // is this the best way to pass this?
	global $asterisk_conf;
	global $grammar_conf; // our pinsets object (created in retrieve_conf)

	switch($engine) {
		case "asterisk":
			$results = sql("SELECT DISTINCT type FROM grammar","getAll",DB_FETCHMODE_ASSOC);
			if(is_array($results)){
				foreach($results as $result){
					$function = $result['type'];
					switch ($function) {
						case 'vmblast':
							$sql	= "SELECT g.id,vg.ext,g.vocabulary,r.displayname,v.password FROM grammar g "
									. "LEFT JOIN vmblast_groups vg ON g.id=vg.grpnum "
									. "LEFT JOIN vmblast v ON g.id=v.grpnum "
									. "LEFT JOIN recordings r ON v.audio_label=r.id WHERE g.type='vmblast' "
									. "AND vg.ext IS NOT NULL ORDER BY g.id";
							$groups = sql($sql,"getAll",DB_FETCHMODE_ASSOC);
							if (is_array($results)) {
								foreach ($groups as $group) {
									$vocabs_arr[]	= trim($group['vocabulary']);
									$exts_arr[] 	= $group['ext'];
									$id 			= $group['id'];
									$next 			= next($groups);
									if($next['id'] == $id) {
										continue;
									} else {
										$vocabs = array_unique($vocabs_arr);
										$exts = array_unique($exts_arr);
										if(sizeof($vocabs) > 1) {
											$vocab = "(".implode(" | ",$vocabs).")";
										} else {
											$vocab = $vocabs[0];
										}
										$vmblast_extensions = implode("&",$exts);
										if($group['displayname']) {
											$extra[] = "recording=".$group['displayname'];
										}
										if($group['password']) {
											$extra[] = "password=".$group['password'];
										}
										$extra_options = implode("&",$extra);
										if(!$extra_options) {
											$extra_options=true;
										}
										$grammar_conf->addGrammar($function,$vocab,$vmblast_extensions,$vocabs[0],$extra_options);
										unset($vocabs);
										unset($exts_arr);
										unset($vocabs_arr);
								}
							}
						}
						break;

						case 'presence':
							$grams = sql("SELECT * FROM grammar WHERE type='$function' ORDER BY id","getAll",DB_FETCHMODE_ASSOC);
							if(is_array($grams)){
								foreach($grams as $gram){
									$vocabs[] = trim($gram['vocabulary']);
									$id = $gram['id'];
									$enabled = $gram['enabled'];
									$next = next($grams);
									if($enabled == "1")  {
										if($next['id'] == $id)  {
											continue;
										} else {
											if(sizeof($vocabs) > 1) {
												$vocab = "(".implode(" | ",$vocabs).")";
											}
											else {
												$vocab = $vocabs[0];
											}
											// we don't want extra options to be appended to the return from the grammar (separated by _)
											$grammar_conf->addGrammar($function,$vocab,"presenceset_$id");
											unset($vocabs);
										}
									}
								}
							}
							break;

						default:
							$grams = sql("SELECT * FROM grammar WHERE type='$function' AND enabled = 1 ORDER BY id","getAll",DB_FETCHMODE_ASSOC);
							if(is_array($grams)){
								foreach($grams as $gram){
									$vocabs[] = trim($gram['vocabulary']);
									$id = $gram['id'];
									$next = next($grams);
									if ($next['id'] == $id) {
										continue;
									} else  {
										if(sizeof($vocabs) > 1) {
											$vocab = "(".implode(" | ",$vocabs).")";
										} else {
											$vocab = $vocabs[0];
										}
										// we don't want extra options to be appended to the return from the grammar (separated by _)
										if($function != "user" && $function != "presence" && substr($function, 0, 3) != "vmx" && substr($function,0, 3) != "ivr") {
											$grammar_conf->addGrammar($function,$vocab,$id,$vocabs[0]);
										} else  {
											$grammar_conf->addGrammar($function,$vocab,$id);
										}
										unset($vocabs);
									}
								}
							}
							break;
						}
					}
				}
				$sql = "SELECT * FROM grammar_config";
				$results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);
				if(DB::IsError($results)) {
					$results = null;
				}
				$grammar_conf->_config = $results[0];

				// Lumenvox needs grammar files for each of these to function properly, so if the user hasn't configured
				// any of them, we need to put placeholders in
				if(!isset($grammar_conf->_grammars['ringgroups'])
					|| !is_array($grammar_conf->_grammars['ringgroups'])
					|| sizeof($grammar_conf->_grammars['ringgroups']) == 0
				)  {
					$grammar_conf->addGrammar("ringgroups","Placeholder for ringgroups Do not delete","");
				}
				if(!isset($grammar_conf->_grammars['vmblast'])
					|| !is_array($grammar_conf->_grammars['vmblast'])
					|| sizeof($grammar_conf->_grammars['vmblast']) == 0
				)  {
					$grammar_conf->addGrammar("vmblast","Placeholder for vmblasting Do not delete","");
				}
				if(!isset($grammar_conf->_grammars['paging'])
					|| !is_array($grammar_conf->_grammars['paging'])
					|| sizeof($grammar_conf->_grammars['paging']) == 0
				)  {
					$grammar_conf->addGrammar("paging","Placeholder for paging Do not delete","");
				}
				if(!isset($grammar_conf->_grammars['user'])
					|| !is_array($grammar_conf->_grammars['user'])
					|| sizeof($grammar_conf->_grammars['user']) == 0
				)  {
					$grammar_conf->addGrammar("user","Placeholder for user Do not delete","");
				}
				if(!isset($grammar_conf->_grammars['presence'])
					|| !is_array($grammar_conf->_grammars['presence'])
					|| sizeof($grammar_conf->_grammars['presence']) == 0
				)  {
					$grammar_conf->addGrammar("presence","Placeholder for presence Do not delete","");
				}
				break;
			}
	function generate_ini_file($assoc_array) {
		foreach ($assoc_array as $key => $item) {
			if (is_array($item)) {
				$content .= "\n[$key]\n";
				foreach ($item as $key2 => $item2) {
					$content .= "$key2 = \"$item2\"\n";
				}
			}
			else {
				$content .= "$key = \"$item\"\n";
			}
		}

		return $content;
	}

}


function grammar_configpageinit($pagename) {
	global $currentcomponent;
	$currentcomponent->addprocessfunc('grammar_configprocess', 1);

	//shoudnt all this be in configpageload?? -mb
	$action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
	$id = isset($_REQUEST['id'])?$_REQUEST['id']:null;
	$extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
	$extension = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;
	$tech_hardware = isset($_REQUEST['tech_hardware'])?$_REQUEST['tech_hardware']:null;

	switch ($pagename) {
		case 'users':
		case 'extensions':
			// On a 'new' user, 'tech_hardware' is set, and there's no extension. Hook into the page.
			if ($tech_hardware != null || $pagename == 'users') {
				grammar_user_applyhooks();
				$currentcomponent->addprocessfunc('grammar_user_configprocess', 8);
			} elseif ($action=="add") {
				// We don't need to display anything on an 'add', but we do need to handle returned data.
				$currentcomponent->addprocessfunc('grammar_user_configprocess', 8);
			} elseif ($extdisplay != '') {
				// We're now viewing an extension, so we need to display _and_ process.
				grammar_user_applyhooks();
				$currentcomponent->addprocessfunc('grammar_user_configprocess', 8);
			}
			break;
		case 'presence';
			if ($id && (!isset($_REQUEST['action']) || $_REQUEST['action'] != 'delete_confirm')) {
				$currentcomponent->addguielem('', new gui_textarea('grammar', grammar_get('presence',$id), 'Speech Entries',
													'Words that can be used to acctive this status using the Magic Button.'));
			}
			break;
		default:
			return true;
	}
}

function grammar_configprocess() {
	$display	= isset($_REQUEST['display'])	? $_REQUEST['display']	: '';
	$action		= isset($_REQUEST['action'])	? $_REQUEST['action']	: '';
	$id			= isset($_REQUEST['id'])		? $_REQUEST['id']		: '';
	$grammar 	= isset($_REQUEST['grammar'])	? $_REQUEST['grammar']	: '';
	switch ($display) {
		case 'presence':
			switch ($action) {
				case 'edit';
					grammar_update('presence', $id, $grammar);
					break;
				case 'delete':
					grammar_del('presence', $id);
					break;
			}
			break;
	}

}
function grammar_user_applyhooks() {
	global $currentcomponent;

	// displaying stuff on the page.
	$currentcomponent->addguifunc('grammar_user_configpageload');
}

// This is called before the page is actually displayed, so we can use addguielem().
function grammar_user_configpageload() {
	global $currentcomponent;

	// Init vars from $_REQUEST[]
	$action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
	$extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;

	// Don't display this stuff it it's on a 'This xtn has been deleted' page.
	if ($action != 'del') {
		$grammar_list = grammar_get('user',$extdisplay);
		$section = _('Magic Button');
		$currentcomponent->addguielem($section, new gui_textarea('grammar_list', $grammar_list, _('Grammar'), _('List the words/phrases to be added to the speech recognition engine, one per line'), "", "", true));
	}
}

// This is called before the page is actually displayed, so we can use addguielem().

function grammar_user_configprocess() {
	//create vars from the request
	$action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
	$id = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
	$extn = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;
	$grammar_list = isset($_REQUEST['grammar_list'])?$_REQUEST['grammar_list']:null;
	$extdisplay = (isset($extn) && $extn !== null)?$extn:$id;
	if ($action == "add" || $action == "edit") {
		if (!isset($GLOBALS['abort']) || $GLOBALS['abort'] !== true) {
			if (isset($extdisplay) && !empty($extdisplay)) {
				grammar_update('user', $extdisplay, $grammar_list,1);
			}
		}
	} elseif ($action == "del") {
		grammar_del('user', $extdisplay);
		grammar_del("vmx$extdisplay");
	}
}

function grammar_display_textarea($type, $viewing_itemid) {
	$grammar_list = grammar_get($type,$viewing_itemid);
	$list = explode("\n",$grammar_list);
	$rows = count($list);
	$rows = (($rows > 20) ? 20 : $rows);

	$html = '<tr><td colspan="2"><h5>';
	$html .= _("Speech Recognition");
	$html .= '<hr></h5></td></tr>';
	$html .= '<tr>';
	$html .= '<td valign="top"><a href="#" class="info">';
	$html .= _("Speech").'<span>'._("List the words/phrases to be added to speech recognition, one per line").'.</span></a>:</td>';
	$html .= '<td valign="top"><textarea id="grammar_list" rows="'.$rows.'" cols="24" name="grammar_list">'.htmlentities($grammar_list).'</textarea>';
	$html .= '</td></tr>';

	return $html;
}

function grammar_hook_ringgroups($viewing_itemid, $target_menuid) {
	return grammar_display_textarea('ringgroups', ltrim($viewing_itemid, "GRP-"));
}

function grammar_hookProcess_ringgroups($viewing_itemid, $request) {

	$viewing_itemid = ltrim($viewing_itemid,"GRP-");
	if(!$viewing_itemid)  {
		$viewing_itemid = (isset($request['account'])?$request['account']:"");
	}
	$description = (isset($request['description'])?$request['description']:"");
	if (!isset($request['action'])) {
		return;
	}

	$grammar_list = isset($request['grammar_list'])?$request['grammar_list']:'';

	switch ($request['action'])	{
		case 'addGRP':
		case 'edtGRP':
			grammar_update('ringgroups', $viewing_itemid, $grammar_list);
		break;
		case 'delGRP':
			grammar_del('ringgroups', $viewing_itemid);
		break;
	}
}

function grammar_hook_vmblast($viewing_itemid, $target_menuid) {
	return grammar_display_textarea('vmblast',ltrim($viewing_itemid, "GRP-"));
}

function grammar_hookProcess_vmblast($viewing_itemid, $request) {

	$viewing_itemid = ltrim($viewing_itemid, "GRP-");
	if(!$viewing_itemid)  {
		$viewing_itemid = (isset($request['account'])?$request['account']:"");
	}
	$description = (isset($request['description'])?$request['description']:"");
	if (!isset($request['action'])) {
		return;
	}

	$grammar_list = isset($request['grammar_list'])?$request['grammar_list']:'';

	switch ($request['action'])	{
		case 'addGRP':
		case 'editGRP':
			grammar_update('vmblast', $viewing_itemid, $grammar_list);
		break;
		case 'delGRP':
			grammar_del('vmblast', $viewing_itemid);
		break;
	}
}

function grammar_hook_paging($viewing_itemid, $target_menuid) {
	return '<table>'
			. grammar_display_textarea('paging', $viewing_itemid)
			. '</table>';
}

function grammar_hookProcess_paging($viewing_itemid, $request) {

	if (!isset($request['action'])) {
		return;
	}
	$grammar_list = isset($request['grammar_list'])?$request['grammar_list']:'';

	if(!$viewing_itemid)  {
		$viewing_itemid = isset($request['pagegrp'])
						? $request['pagegrp']
						: '';
	}
	if(!$viewing_itemid)  {
		$viewing_itemid = isset($request['pagenbr'])
						? $request['pagenbr']
						: '';
	}

	switch ($request['action'])	{
		case 'submit':
			grammar_update('paging', $viewing_itemid, $grammar_list);
		break;
		case 'delete':
			grammar_del('paging', $viewing_itemid);
		break;
	}
}
function grammar_get($type, $xtn) {
	global $db;

	$grammar_arr = $db->getCol("SELECT `vocabulary` FROM `grammar` WHERE `type` = '$type' AND `id` = '$xtn' ORDER BY `sequence`");
	if(DB::IsError($grammar_arr)) {
		die_freepbx($grammar_arr->getDebugInfo()."<br><br>".'selecting from grammar table');
	}
	return implode("\n",$grammar_arr);

}

function grammar_enabled($type, $xtn)  {
	global $db;
	$grammar_arr = $db->getCol("SELECT `enabled` FROM `grammar` WHERE `type` = '$type' AND `id` = '$xtn'");
	if(DB::IsError($grammar_arr)) {
		die_freepbx($grammar_arr->getDebugInfo()."<br><br>".'selecting from grammar table');
	}
	if(isset($grammar_arr[0]))  {
		return $grammar_arr[0];
	}
	return false;

}
function grammar_update($type, $id, $grammar_text, $enabled=1) {
	global $db;

	sql("DELETE from `grammar` WHERE `type` = '$type' AND `id` = '$id'");

	$grammar_arr = explode("\n",$grammar_text);
	$seq = 0;
	foreach($grammar_arr as $word) {
		if (trim($word) == '') {
			continue;
		}
		$seq += 1;
		$dbconfitem[]=array($word,$seq);
	}
	if (isset($dbconfitem) && $dbconfitem != '') {
		$compiled = $db->prepare("INSERT INTO `grammar` (type, id, vocabulary, sequence, enabled) values ('$type', '$id', ?, ?, '$enabled')");
		$result = $db->executeMultiple($compiled,$dbconfitem);
		if(DB::IsError($result)) {
			die_freepbx($result->getMessage()."<br><br>INSERT INTO `grammar` (type, id, vocabulary, sequence, enabled) values ('$type','$id'?,?,'$enabled')");
		}
	}
	needreload();
}

function grammar_del($type, $id=null) {

	if($id)  { // Delete the whole file
		sql("DELETE from `grammar` WHERE `type` = '$type' AND `id` = '$id'");

	} else {
		sql("DELETE from `grammar` WHERE `type` = '$type'");
		if (file_exists("/etc/asterisk/grammars/$type.gram")) {
            unlink("/etc/asterisk/grammars/$type.gram");
        }
	}

}
function grammar_hookGet_config($engine)  {
	global $ext;
	global $db;
        switch($engine) {
                case "asterisk":
  			// Generate Magic Button dialplan if enabled
			$magicbuttonenabled = $db->getOne("SELECT magicbuttonenabled FROM grammar_config");
			if($magicbuttonenabled == "1")  {
				// This block of dialplan establishes the *22 and 2 extensions for the magic button
				$ext->addInclude('from-internal-additional', 'magic-button-feature');
				$ext->add("magic-button-feature","*22","",new ext_noop('MagicButton was called with *22'));
				$ext->add("magic-button-feature","*22","",new ext_goto('magic-button,s,1'));
				$ext->add("magic-button-feature","2","",new ext_noop('MagicButton was called by transfer to extension 2'));
				$ext->add("magic-button-feature","2","",new ext_goto('magic-button,s,1'));
				$ext->addInclude('magic-button-feature', 'magic-button');
				// This block of dialplan forms the guts of the magic button
				$ext->add("magic-button","s","",new ext_answer());
				$ext->add("magic-button","s","",new ext_macro("magic-button-listen"));
				$ext->add("magic-button-auth","s","",new ext_answer());
				$ext->add("magic-button-auth","s","",new ext_macro("magic-button-auth"));
				$ext->add("magic-button-vm-notify","_X.","",new ext_answer());
				$ext->add("magic-button-vm-notify","_X.","",new ext_vm('${EXTEN}@default,s'));
				$ext->add("magic-button-notify","_X.","",new ext_answer());
				$ext->add("magic-button-notify","_X.","",new ext_set("DIAL",'${IF($["${NOTIFY_VM}" = ""]?Local/*80${EXTEN}@from-internal:Local/${EXTEN}@magic-button-vm-notify)}'));
				$ext->add("magic-button-notify","_X.","",new ext_dial('${DIAL},30','M(magic-button-playnotification^${NAME_RECORDING}^${NOTIFY_VM})'));
				$ext->add("macro-magic-button-playnotification","s","",new ext_wait("1"));
				$ext->add("macro-magic-button-playnotification","s","",new ext_set("_REALCALLERIDNUM",'${REALCALLERIDNUM'));
				$ext->add("macro-magic-button-playnotification","s","",new ext_gotoif('$["${ARG1}" = ""]','play-extension',"play-name-recording"));
				$ext->add("macro-magic-button-playnotification","s","play-name-recording",new ext_playback('${ARG1}'));
				$ext->add("macro-magic-button-playnotification","s","",new ext_goto("available"));
				$ext->add("macro-magic-button-playnotification","s","play-extension",new ext_playback("extension"));
				$ext->add("macro-magic-button-playnotification","s","",new ext_saydigits('${EXTENSION}'));
				$ext->add("macro-magic-button-playnotification","s","available",new ext_playback("is&available"));
				$ext->add("macro-magic-button-playnotification","s","",new ext_gotoif('$["${ARG2}" = ""]','speech-connect','end-novm'));
				$ext->add("macro-magic-button-playnotification","s","speech-connect",new ext_set('FUNCTION','notifyconnectme'));
				$ext->add("macro-magic-button-playnotification","s","",new ext_agi("magic-button.php"));
				$ext->add("macro-magic-button-playnotification","s","end-novm",new ext_hangup());
				$ext->add("macro-magic-button-auth","s","",new ext_answer());
				$ext->add("macro-magic-button-auth","s","",new ext_set("FUNCTION","remoteauthenticate"));
				$ext->add("macro-magic-button-auth","s","",new ext_agi("magic-button.php"));
				$ext->add("macro-magic-button-auth","s","",new ext_gotoif('$["${AUTHORIZED}" = ""]',"hangup","authenticated"));
				$ext->add("macro-magic-button-auth","s","authenticated",new ext_macro("magic-button-listen"));
				$ext->add("macro-magic-button-auth","s","hangup",new ext_hangup());
				$ext->add("macro-magic-button-listen","s","",new ext_wait("1"));
				$ext->add("macro-magic-button-listen","s","",new ext_execif('$["${WHOAMI}" = ""]',"Macro","user-callerid"));
				$ext->add("macro-magic-button-listen","s","",new ext_execif('$["${WHOAMI}" = ""]',"Set",'WHOAMI=${AMPUSER}'));
				$ext->add("macro-magic-button-listen","s","",new ext_speechcreate());
				$ext->add("macro-magic-button-listen","s","",new ext_speechloadgrammar("magicbutton","/etc/asterisk/grammars/magicbutton.gram"));
				$ext->add("macro-magic-button-listen","s","",new ext_speechactivategrammar("magicbutton"));
				$ext->add("macro-magic-button-listen","s","",new ext_agi('SpeechLoadGrammarIfExists.php,/etc/asterisk/grammars/contactdir${WHOAMI}.gram'));
				$ext->add("macro-magic-button-listen","s","",new ext_gotoif('$["${EXTRA_GRAMMAR}" = ""]',"Listen","LoadExtraGrammar"));
				$ext->add("macro-magic-button-listen","s","LoadExtraGrammar",new ext_speechloadgrammar('${EXTRA_GRAMMAR_NAME}','${EXTRA_GRAMMAR}'));
				$ext->add("macro-magic-button-listen","s","",new ext_speechactivategrammar('${EXTRA_GRAMMAR_NAME}'));
				$ext->add("macro-magic-button-listen","s","",new ext_set("SPEECH_DTMF_MAXLEN","1"));
				$ext->add("macro-magic-button-listen","s","Listen",new ext_speechbackground("magic-button/magic-button"));
				$ext->add("macro-magic-button-listen","s","",new ext_set("RESULT",'${SPEECH_TEXT(0)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_noop('RESULT ${RESULT} ${EXTEN}'));
				//We need some way to debug Speech, as a result, what better way than to start displaying the confidence score
				$ext->add("macro-magic-button-listen","s","",new ext_noop('Speech Recognition Score was 0${SPEECH_SCORE(0)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_gotoif('$[ 0${SPEECH_SCORE(0)} < 600]',"Questionable","Process"));
				$ext->add("macro-magic-button-listen","s","Questionable",new ext_playback("magic-button/sorry-couldnt-understand"));
				$ext->add("macro-magic-button-listen","s","",new ext_macro("end-speech",'${EXTRA_GRAMMAR},${EXTRA_GRAMMAR_NAME}'));
				$ext->add("macro-magic-button-listen","s","",new ext_goto("1"));
				$ext->add("macro-magic-button-listen","s","Process",new ext_set("FUNCTION",'${CUT(RESULT,_,1)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_set("EXTENSION",'${CUT(RESULT,_,2)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_set("DESCRIPTION",'${CUT(RESULT,_,3)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_set("EXTRAOPTIONS",'${CUT(RESULT,_,4)}'));
				$ext->add("macro-magic-button-listen","s","",new ext_macro("end-speech",'${EXTRA_GRAMMAR},${EXTRA_GRAMMAR_NAME}'));
				$ext->add("macro-magic-button-listen","s","",new ext_agi("magic-button.php"));
				$ext->add("macro-magic-button-listen","s","",new ext_gotoif('$["${AUTHORIZED}" = "true"]',"1","End"));
				$ext->add("macro-magic-button-listen","s","End",new ext_hangup());
				$ext->add("magic-button-conf","_X.","",new ext_answer());
				$ext->add("magic-button-conf","_X.","",new ext_set("CONFNO",'${EXTEN}'));
				$ext->add("magic-button-conf","_X.","",new ext_meetme('${CONFNO}',"Axqdz"));
				$ext->add("magic-button-conf","_X.","",new ext_hangup());
				$ext->add("macro-end-speech","s","",new ext_set("EXTRA_GRAMMAR",'${ARG1}'));
				$ext->add("macro-end-speech","s","",new ext_set("EXTRA_GRAMMAR_NAME",'${ARG2}'));
				$ext->add("macro-end-speech","s","",new ext_speechdeactivategrammar("magicbutton"));
				$ext->add("macro-end-speech","s","",new ext_gotoif('$["${EXTRA_GRAMMAR}" = ""]',"Destroy","DeactivateExtraGrammar"));
				$ext->add("macro-end-speech","s","DeactivateExtraGrammar",new ext_speechdeactivategrammar('${EXTRA_GRAMMAR_NAME}'));
				$ext->add("macro-end-speech","s","Destroy",new ext_speechdestroy());
			}
		break;
	}
}
function grammar_destinations() {

	$extens[] = array('destination' => "magic-button-auth,s,1", 'description' => 'Magic Button DISA', 'category', "Speech Recognition");
	return $extens;
}

function speech_config_add($magicbuttonenabled,$zip_code,$tts_engine,$aastra_support)  {
	global $db;

        $sql = "DELETE FROM grammar_config";
        $result = $db->query($sql);
        if(DB::IsError($result)) {
                die_freepbx($result->getMessage().$sql);
        }

	$sql = "INSERT INTO
			grammar_config (magicbuttonenabled,zip_code,tts_engine,aastra_support)
		VALUES ('$magicbuttonenabled','$zip_code','$tts_engine','$aastra_support')";

	$results = sql($sql);


}
function speech_getconfig()  {
        global $db;
        $sql = "SELECT * FROM grammar_config";
        $results = sql($sql,"getAll",DB_FETCHMODE_ASSOC);
        if(DB::IsError($results)) {
                $results = null;
        }
        return $results[0];
}
function grammar_lvConf($file) {

	$conf = file_get_contents($file);
        $servers = '/LICENSE_SERVERS = (.*):[0-9]{4};(.*):[0-9]{4};(.*):[0-9]{4}/';
        $ports = '/LICENSE_SERVERS = .*:([0-9]{4});.*:([0-9]{4});.*:([0-9]{4})/';
	$sre = '/SRE_SERVERS = (\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b)/';
        preg_match($servers, $conf, $server_matches);
        preg_match($ports, $conf, $port_matches);
	preg_match($sre, $conf, $sre_matches);

        unset($server_matches[0],$port_matches[0],$sre_matches[0]);

        $key = array_rand($server_matches);

	return array('server' => $server_matches[$key], 'port' => $port_matches[$key], 'sre' => $sre_matches[array_rand($sre_matches)]);
}
