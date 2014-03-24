<?php

class Loader {
  var $raw = NULL;
  var $adress = NULL;
  var $preparing = array();
  
  function __construct($URL, $preparing=array('absolute_urls')) {
    $this->raw = file_get_contents($URL);
    $this->adress = self::explode_address($URL);
    $this->preparing = $preparing;
  }
  
  function prepare() {
    $result = $this->raw;
    foreach ($this->preparing as $prepare_function) {
      $result = $prepare_function($result, $this->adress);
    }
    return $result;
  }
  
  private static function explode_address($URL) {
    $result = array();
    $arr = explode("://", $URL);
    $result['protocol'] = $arr[0];
    $arr = explode("/", $arr[1]);
    $result['domain'] = $arr[0];
    $result['adress'] = $arr[1];
    
    return $result;
  }
}

function absolute_urls($code, $address) {
  $base = $address['protocol'] . '://' . $address['domain'];
  
  $attributes = array("href=\"", "src=\"", "action=\"", "url(");
  $result = $code;
  foreach ($attributes as $attribute) {
    $from = "$attribute/";
    $to   = "{$attribute}{$base}/";
    $result = str_replace($from, $to, $result);
  }
  
  return $result;
}