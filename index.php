<?php
  include "Extractor/Extractor.class.php";
  
  class KeyRemove {
    var $key = "";
    
    private function remove_from_node(DOMNode &$node) {
      foreach ($node->childNodes as $child) {
        if($child->nodeType==XML_ELEMENT_NODE) {
          $id = $child->getAttribute("id");
          $class = $child->getAttribute("class");
          if(strstr($id, $this->key)!==FALSE || 
             strstr($class, $this->key)!==FALSE ||
             strstr($child->tagName, $this->key)!==FALSE) {
            $node->removeChild($child);
          }
          else {
            $this->remove_from_node($child);
          }
        }
      }
    }
    
    function __construct($key) {
      $this->key = $key;
    }
    
    function remove(DOMDocument $document) {
      $body = $document->getElementsByTagName('body')->item(0);
      $this->remove_from_node($body);
      return $document;
    }
  }
  
  function scripts_remove(DOMDocument $document) {
    $rem = new KeyRemove("script");
    return $rem->remove($document);
  }
  function comments_remove(DOMDocument $document) {
    $rem = new KeyRemove("comment");
    return $rem->remove($document);
  }
  function banner_remove(DOMDocument $document) {
    $rem = new KeyRemove("banner");
    return $rem->remove($document);
  }
  
  if($URL = $_GET['q']) {
    $extractor = new Extractor($URL, array('scripts_remove','comments_remove', 'banner_remove'));
    print_r($extractor->get_extracted());
  }