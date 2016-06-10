<?php

class MagicHelpers  {

  function write_ini_file($path, $assoc_array) {
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

    if (!$handle = fopen($path, 'w')) {
      return false;
    }
    if (!fwrite($handle, $content)) {
      return false;
    }
    fclose($handle);
    return true;
  }
  function write_grammar($file,$gram_arr)  {

  $grammar_header = <<<END
#ABNF 1.0;
mode voice;
language en-US;
tag-format <semantics/1.0.2006>;
END;
    list($gram_name,) = explode(".",basename($file));
    $file_data = $grammar_header."\n\n";
    $file_data .= "root \$$gram_name;\n\n";
    $file_data .= "\$$gram_name = (";
    while(list($key,$val) = each($gram_arr))  {
      $file_data .= "\n\t$key (\n\t\t";
      foreach($val as $gram)  {
        $data[] = $gram[vocab]." {out=\"".$gram[out]."\"}";
      }
      $file_data .= implode("\n\t\t| ",$data);

      $file_data .= "\n\t)";
    }
    $file_data .= "\n);";

    touch($file);
    $fd = fopen($file, "r+");
    rewind($fd);
    fwrite($fd,$file_data);
    fflush($fd);
    ftruncate($fd, ftell($fd));  // truncate the file to the end of what we just wrote in case this file already existed on the file system
    fclose($fd);


  }

}
