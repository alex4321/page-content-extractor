<?php
  require_once 'Loader.class.php';
  require_once 'HTML5/Parser.php';
  
  class ExtractorHelp {
    /*
     * 
     */
    public static function node_inner_html(DOMDocument $document, DOMNode $mainnode, $no_tags = FALSE) {
      $code = "";
      foreach($mainnode->childNodes as $node) {
        if($node->nodeType==XML_ELEMENT_NODE) {
          $code .= @$document->saveHTML($node);
        }
      }
      if($no_tags) {
        return strip_tags($code);
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
    
    /*
     * Return list of nodes that exists in $main, but not in $second.
     * Use node tag, id, class and innerText
     */
    public static function node_difference(DOMDocument $document, DOMDocument $second_document,
             DOMElement &$main, DOMElement &$second) {
      $second_list = array();
      foreach($second->childNodes as $node) {
        if($node->nodeType==XML_ELEMENT_NODE) {
          $tag   = $node->tagName;
          $class = $node->getAttribute('class');
          $id    = $node->getAttribute('id');
          
          $second_list["$tag#$id.$class"][] = $node;
        }
      }
      
      $removing = array();
      foreach($main->childNodes as $node) {
        if($node->nodeType!=XML_ELEMENT_NODE)  {
          continue;
        }
        $tag   = $node->tagName;
        $class = $node->getAttribute('class');
        $id    = $node->getAttribute('id');
        
        if(isset($second_list["$tag#$id.$class"])) {
          $code = ExtractorHelp::node_inner_html($document, $node, TRUE);
          $found = FALSE;
          foreach ($second_list["$tag#$id.$class"] as $second_node) {
            if($code==ExtractorHelp::node_inner_html($second_document, $second_node)) {
              $removing[] = $node;
              $found = TRUE;
            }
          }
          
          if(!$found) {
            foreach ($second_list["$tag#$id.$class"] as $second_node) {
              ExtractorHelp::node_difference($document, $second_document, $node, $second_node);
            }
          }
        }
      }
      foreach ($removing as $node) {
        if($node->parentNode) {
          $node->parentNode->removeChild($node);
        }
      }
    }
  }
  
  class Extractor {
    var $extracted = NULL;
    var $full = NULL;
    var $address = NULL;
    var $prepare_document;
    
    function __construct($URL, $prepare=array() ) {
      $this->prepare_document = $prepare;
      $info = new Loader($URL);
      $code = $info->prepare($prepare);
      $this->address = $info->adress;
      
      $this->extracted = $this->extract_preview($code);
      $this->full = $code;
    }
    
    function get_extracted() {
      return $this->extracted;
    }
    
    function get_full() {
      return $this->full;
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
     * Get difference between main page and current and get biggest of them
     */
    private function extract_by_main_preview(DOMDocument $document) {
      $main_URL = $this->address['protocol'] . '://' . $this->address['domain'] . '/';
      $main_loader = new Loader($main_URL);
      $main_code = $main_loader->prepare();
      $main_doc = HTML5_Parser::parse($main_code);
      
      $current_body = $document->getElementsByTagName('body')->item(0);
      $main_body = $main_doc->getElementsByTagName('body')->item(0);
      
      ExtractorHelp::node_difference($document, $main_doc, $current_body, $main_body);
      $xpath = new DOMXPath($document);
      $text_elements = $xpath->query('//p|//a');
      $codes = array();
      foreach ($text_elements as $element) {
        $parent = ExtractorHelp::get_block_parent($document, $element);
        $parent_code = ExtractorHelp::node_inner_html($document, $parent);
        if(!in_array($parent_code, $codes)) {
          $codes[] = $parent_code;
        }
      }
      if(!$codes)
        return FALSE;
      usort($codes, array('ExtractorHelp', 'len_sort'));
      
      return $codes[0];
    }
    
    private function extract_preview($code) {
      $doc = HTML5_Parser::parse($code);
      $xpath = new DOMXPath($doc);
      foreach ($xpath->query('//comment()') as $comment) {
          $comment->parentNode->removeChild($comment);
      }
      
      foreach ($this->prepare_document as $call) {
        $doc = call_user_func($call, $doc);
      }
      $calls = array(
        array($this, 'extract_social_preview'),
        array($this, 'extract_semantic_preview'),
        array($this, 'extract_by_main_preview')
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