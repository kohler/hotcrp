<?php 
//
// Configuration for a paper
//
class Paper {	
  var $paperId;
  var $title;
  var $authorInformation;
  var $contactId;
  var $submitted;
  var $acknowledged;
  var $widthdrawn;
  var $pcPaper;
  var $authorsResponse;
    
  //
  // Default constructor - simply mark paper as invalid
  //
  function Paper() {
    $this -> invalidate();
  }

  function invalidate() {
    //
    // Bogus paper
    //
    $this -> paperId = -1;
    $this -> paperId = -1;
    $this -> title = -1;
    $this -> authorInformation = -1;
    $this -> contactId = -1;
    $this -> submitted = -1;
    $this -> acknowledged = -1;
    $this -> widthdrawn = -1;
    $this -> pcPaper = -1;
    $this -> authorsResponse = -1;
  }

  function valid() {
    return $this -> paperId >= 0;
  }
  
  function loadById($paperId, $conf) {
    //
    // Initialize the object based on the paper ID
    //
    $query = "SELECT * FROM Paper WHERE Paper.paperId=$paperId";
    $result = $conf->qe($query);
    if ( DB::isError($result) ) {
      $this -> invalidate();
    } else {

      //
      // Copy database contents
      //

      $fromdb = $result->fetchRow(DB_FETCHMODE_OBJECT);
      $this -> paperId = $fromdb -> paperId;
      $this -> paperId = $fromdb -> paperId;
      $this -> title = $fromdb -> title;
      $this -> authorInformation = $fromdb -> authorInformation;
      $this -> contactId = $fromdb -> contactId;
      $this -> submitted = $fromdb -> submitted;
      $this -> acknowledged = $fromdb -> acknowledged;
      $this -> widthdrawn = $fromdb -> widthdrawn;
      $this -> pcPaper = $fromdb -> pcPaper;
      $this -> authorsResponse = $fromdb -> authorsResponse;
    }
  }
}
?>
