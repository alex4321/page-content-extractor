<?php
  require_once 'Loader.class.php';
  require_once 'HTML5/Parser.php';
  
  class ExtractorHelp {
    public static function node_inner_html(DOMDocument $document, DOMNode $mainnode) {
      $code = "";
      foreach($mainnode->childNodes as $node) {
        $code .= $document->saveHTML($node);
      }
      return $code;
    }
    
    public static function extract_title(DOMDocument $document) {
      $title_elements = $document->getElementsByTagName('title');
      if($title_elements->length===1) {
        $title_element = $title_elements->item(0);
        return ExtractorHelp::node_inner_html($document, $title_element);
      }
      else {
        return NULL;
      }
    }
    
    public static function get_block_parent(DOMDocument $document, DOMNode $node) {
      $block_tags = array('html', 'body', 'div', 'section', 'article', 'aside', 'main', 'form');
      
      $parent = $node->parentNode;
      if(!in_array($parent->tagName, $block_tags)) {
        return ExtractorHelp::get_block_parent($document, $parent);
      }
      else {
        return $parent;
      }
    }
    
    public static function len_sort($a, $b) {
      return strlen($a) < strlen($b);
    }
  }
  
  class Extractor {
    var $extracted = NULL;
    var $full = NULL;
    var $address = NULL;
    
    function __construct($URL) {
      $info = new Loader($URL);
      $code = $info->prepare();
      $this->address = $info->adress;
      
      $this->extracted = $this->extract_preview($code);
      $this->full = $code;
    }
    
    function getExtracted() {
      return $this->extracted;
    }
    
    function getFull() {
      return $this->full;
    }
    
    /*
     * Extract information from OpenGraph tags.
     * @return FALSE if not have og:description
     *  array('title'=>, 'description'=>, ...) if contain
     */
    private function extract_social_preview(DOMDocument $document) {      
      $result = array();
      $result['title'] = ExtractorHelp::extract_title($document);
      
      $meta = $document->getElementsByTagName('meta');
      
      foreach($meta as $item) {
        $name = $item->getAttribute('name');
        if(strstr($name, 'og:')!==FALSE) {
          $result[str_replace('og:', '', $name)] = $item->getAttribute('value');
        }
      }
      
      if(isset($result['description'])) {
        return $result;
      }
      else {
        return FALSE;
      }
    }
    
    private function image_by_code($code) {
      $arr = explode("<img", $code);
      if(count($arr)!=0) {
        $arr = explode("src=\"", $arr[1]);
        $arr = explode("\"", $arr[1]);
        $image_URL = $arr[0];
      }
      else {
        $image_URL = $this->address['protocol'] . '://' . $this->address['domain'] . '/favicon.ico';
      }
      
      return $image_URL;
    }
    
    /*
     * Extract information by semantic HTML5 tags.
     * Tryes to find <article> in <main>, <* role="main">, or <body>
     */
    private function extract_semantic_preview(DOMDocument $document) {      
      $result = array();
      $result['title'] = ExtractorHelp::extract_title($document);
      
      $xpath = new DOMXPath($document);
      if($xpath->query('//main')->length!=0) {
        $query_start = '//main';
      }
      else if($xpath->query('//*[@role="main"]')->length!=0) {
        $query_start = '//*[@role="main"]';
      }
      else {
        $query_start = '//body';
      }
      
      $articles = $xpath->query("$query_start//article");
      $article_codes = array();
      foreach ($articles as $article) {
        $article_codes[] = ExtractorHelp::node_inner_html($document, $article);
      }
      if(!$article_codes)
        return FALSE;
      usort($article_codes, array('ExtractorHelp', 'len_sort'));
      
      $main_article = $article_codes[0];
      $result['description'] = $main_article;
      
      if(isset($result['description'])) {
        $result['image'] = $this->image_by_code($main_article);
        return $result;
      }
      else {
        return FALSE;
      }
    }
    
    /*
     * Try to get <p> tags and determine main part from it (by block parent sizes)
     */
    private function extract_size_preview(DOMDocument $document) {
      $result = array();
      $result['title'] = ExtractorHelp::extract_title($document);
      $xpath = new DOMXPath($document);
      
      $elements = $xpath->query('//p');
      $codes = array();
      foreach ($elements as $node) {
        $parent = ExtractorHelp::get_block_parent($document, $node);
        if($parent->tagName!="body") {
          $code = ExtractorHelp::node_inner_html($document, $parent);
          if(!in_array($code, $codes)) {
            $codes[] = $code;
          }
        }
      }
      usort($codes, array('ExtractorHelp', 'len_sort'));
      if(!$codes)
        return FALSE;
      $main_code = $codes[0]; 
      $result['description'] = $main_code;     
      
      if(isset($result['description'])) {
        $result['image'] = $this->image_by_code($main_code);
        return $result;
      }
      else {
        return FALSE;
      }
    }
    
    private function extract_preview($code) {
      $doc = HTML5_Parser::parse($code);
      $calls = array(
        array($this, 'extract_social_preview'),
        array($this, 'extract_semantic_preview'),
        array($this, 'extract_size_preview')
      );
      foreach ($calls as $call) {
        try {
          $result = call_user_func($call, $doc);
          if($result)
            return $result;
        } 
        catch (Exception $ex) {}
      }
      return FALSE;
    }
  }