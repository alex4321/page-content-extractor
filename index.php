<?php
  include "Extractor/Extractor.class.php";
  if($URL = $_GET['q']) {
    $extractor = new Extractor($URL);
    print_r($extractor->getExtracted());
  }