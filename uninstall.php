<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed');}
global $db;

echo "dropping table grammar..";
sql("DROP TABLE IF EXISTS `grammar`");
echo "done<br>\n";

?>
