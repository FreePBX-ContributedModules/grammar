<?php
class MagicXML {
  var $data;
  var $parser;
  var $stack;
  var $index;
  
  function MagicXML() {
  }

  function fetch($raw_xml) {
    $this->index = 0;
    $this->data = null;
    $this->stack = array();
    $this->stack[] = &$this->data;
    $this->parser = xml_parser_create ("UTF-8");
    xml_set_object($this->parser,$this);
    xml_set_element_handler($this->parser, "tag_open", "tag_close");
    xml_set_character_data_handler($this->parser, "cdata");
    xml_parser_set_option($this->parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
    if (!$parsed_xml = xml_parse($this->parser,$raw_xml, true )) {
      return false;
    }
    xml_parser_free($this->parser);
    return true;
  }

  function tag_open($parser, $tag, $attrs) {
    $tag = str_replace("-", "_", $tag);
    $tag = str_replace(":", "_", $tag);
    
    foreach($attrs as $key => $val) {
      $key = str_replace("-", "_", $key);
      $key = str_replace(":", "_", $key);
      $value = $this->clean($val);
      $object->_attr->$key = $value;
    }
    $temp = &$this->stack[$this->index]->$tag;
    $temp[] = $object; 
    $size = sizeof($temp);
    $this->stack[] = &$temp[$size-1];
    $this->index++;
  }

  function tag_close($parser, $tag) {
    array_pop($this->stack);
    $this->index--;
  }
  
  function cdata($parser, $data) {
    if(trim($data)){
        $this->stack[$this->index]->_text .= $data;
    }
  }

  function clean($string){
    return utf8_decode(trim($string));
  }
}
?>
