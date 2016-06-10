#!/usr/bin/env php
<?php
  error_reporting(E_ALL);
  require_once "phpagi.php";
  $agi = new AGI;

  if(file_exists($argv[1]))  {

    $agi->set_variable("EXTRA_GRAMMAR",$argv[1]);
    list($name,) = explode(".",basename($argv[1]));
    $agi->set_variable("EXTRA_GRAMMAR_NAME",$name);
    
  }
?>
