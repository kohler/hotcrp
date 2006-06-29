<?php 

class FormVariable {
  var $formName;
  var $label;
  var $value;

  function FormVariable($name, $l = "") {
    $this -> formName = $name;
    $this -> label = $l;
  }

  function getLabel() {
    return $this->label;
  }

  function printLabel() {
    print $this->getLabel();
  }

  //
  // Load a value from another object
  //
  function load(&$array) {
    if (IsSet($array[$this->formName])) {
      $this->value=stripslashes($array[$this->formName]);
    }
  }

  function mkset() {
    return "$this->formName='"
      . addslashes($this->value)
      . "'";
  }

  function editable() {
    print "<p> Subclass didn't specify an editable method </p>";
  }

  function viewable() {
    print nl2br(htmlentities($this->value));
  }

  function getASCII() {
    return $this->value;
  }
}

class FormMessage extends FormVariable{
  function FormMessage($message, $name2 = "") {
    $this -> FormVariable("",$name2);
    $this -> value = $message;
  }

  function mkset() {
    return "";
  }

  function editable() {
    print "<TABLE BGCOLOR=\"YELLOW\"><TR><TD>";
    print $this->value;
    print "</TD></TR></TABLE>";
  }

  function viewable() {
    //$this->editable();
  }
}

class FormTextArea extends FormVariable{
  var $cols;
  var $rows;

  function FormTextArea($name, $c, $r, $name2 = "") {
    $this -> FormVariable($name,$name2);
    $this -> cols = $c;
    $this -> rows = $r;
  }

  function editable() {
    print "<textarea wrap=virtual rows=\"$this->rows\" cols=\"$this->cols\" name=\"$this->formName\">";
    print $this->value;
    print "</textarea>";
  }
}

class FormText extends FormVariable{
  var $cols;

  function FormText($name1, $c, $name2 ="") {
    $this -> FormVariable($name1, $name2);
    $this -> cols = $c;
    if ($this->cols == 0) {
      $this->cols=10;
    }
  }

  function editable() {
    print "<input type=\"text\" name=\"$this->formName\" ";
    print "size='$this->cols' ";
    print "value='$this->value'";
    print ">\n";
  }
}


class FormNumericRange extends FormVariable{
  var $min;
  var $max;

  function FormNumericRange($name1, $mn, $mx, $name2="") {
    $this -> FormVariable($name1, $name2);
    $this -> min = $mn;
    $this -> max = $mx;
    return $this;
  }

  function editable() {
    print "<SELECT name=\"$this->formName\" SINGLE>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "SELECTED";
      }
      print "<OPTION $selected VALUE=\"$i\"> $i </OPTION>\n";
    }
    print "</SELECT>";
  }
}

class FormLongTextLabeledRange extends FormNumericRange{
  var $labels;

  function FormLongTextLabeledRange($name1, $l, $mn, $mx, $name2="") {
    $this -> FormNumericRange($name1, $mn, $mx, $name2);
    $this -> labels = $l;
  }

  function editable() {
    print "<TABLE BORDER>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "CHECKED";
      }
      print "<TR><TD><INPUT TYPE=RADIO name=\"$this->formName\" $selected VALUE=\"$i\"></TD><TD>";
      print $this->labels[$i];
      print " </TD></TR>\n";
    }
    print "</TABLE>";
  }

  function viewable() {
    print $this->labels[$this->value];
  }

  function getASCII() {
    return $this->labels[$this->value];
  }
}

class FormNumericLabeledRange extends FormNumericRange{
  var $labels;

  function FormNumericLabeledRange($name1, $l, $mn, $mx, $name2="") {
    $this -> FormNumericRange($name1, $mn, $mx, $name2);
    $this -> labels = $l;
  }

  function editable() {
    print "<SELECT name=\"$this->formName\" SINGLE>\n";
    for ($i = $this -> min; $i <= $this -> max; $i++) {
      $selected = "";
      if ($i == $this->value) {
	$selected = "SELECTED";
      }
      print "<OPTION $selected VALUE=\"$i\">";
      print $this->labels[$i];
      print " </OPTION>\n";
    }
    print "</SELECT>";
  }

  function viewable() {
    print $this->labels[$this->value];
  }

  function getASCII() {
    return $this->labels[$this->value];
  }
}

class FormCollection {
  var $numfields;
  var $fields;
  var $fieldorder;

  function FormCollection() {
    $this->fields=array();
    $this->fieldorder=array();
  }

  function add(&$obj) {
    $name = $obj->formName;
    $this->fields[$name] = &$obj;
    array_push($this->fieldorder, $name);
  }

  function load(&$data) {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      $this->fields[$name]->load($data);
    }
  }

  function printEditableAsRows() {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->editable();
      print "</td> </tr>\n";
    }
  }

  function printViewableAsRows() {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";
    }
  }
  
  function mkset() {
    $set = "";
    $sep = "";
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      $item = $this->fields[$name]->mkset();
      if( $item != "" ){
        $set = $set . $sep . $this->fields[$name]->mkset();
        $sep = ", ";
      }
    }
    return $set;
  }

}

Class ConferenceReview extends FormCollection {

  var $paperId;
  var $reviewId;
  var $valid;

  var $paperFields;
  var $authorFields;

  var $paperTopics;

  var $reviewFields;
  var $reviewExisted;

  var $checkFields;

  //
  // For formatted print/multireviews
  //
  var $shortFields;
  var $longFields;

  function ConferenceReview($Conf, $contactId, $pid)
    {
      $this->FormCollection();

      $this->paperTopics = array();

      $this->paperId = $pid;
      $this->reviewId= $r;
      $this->valid = 0;

      $result = $Conf-> q("SELECT Paper.*, PaperStorage.mimetype "
			  . " FROM Paper LEFT JOIN PaperStorage "
			  . " ON (PaperStorage.paperId='$this->paperId')"
			  . " WHERE Paper.paperId='$this->paperId'"
			  );

      if (DB::isError($result)) {
	$Conf->errorMsg("query : " . $result->getMessage());
	return 0;
      } else if ($result->numRows() == 0) {
	$Conf->errorMsg("No rows of data?");
	return 0;
      } else {
	$this->paperFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	//
	// Pull out the author information, load it.
	//
	$authorId = $this->paperFields['contactId'];
	$result = $Conf->q("SELECT * FROM ContactInfo WHERE contactId='$authorId'");
	if (DB::isError($result)) {
	  $Conf->errorMsg("Invalid query for author information");
	  return;
	} else {
	  $this->authorFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	}

	//
	// Get paper topics
	// 

	$query="select TopicArea.topicName from TopicArea INNER JOIN PaperTopic ON TopicArea.topicAreaId = PaperTopic.topicId WHERE PaperTopic.paperId = " . $this->paperId;
        $result = $Conf->q($query);
        if ( DB::isError($result)) {
          $Conf->errorMsg("Error in query for paper topics: " . $result->getMessage());
        }
        else {
 	  $i = 0;
	  while ( $row = $result->fetchRow()) {
	    $this->paperTopics[$i] = $row[0];
	    $i++;
	  }
        }

	$this->valid = 1;

	//
	// Now, pull the review if it exists
	//
	
	$myid = $contactId;
	$result = $Conf->q("SELECT *, unix_timestamp(lastModified) as timestamp FROM PaperReview WHERE "
			   . " reviewer='$myid' AND paperId='$this->paperId'");
	if (DB::isError($result)) {
	  $this->reviewExisted = 0;
	} else {
	  $this->reviewExisted = 1;
	  $this->reviewFields = $result->fetchRow(DB_FETCHMODE_ASSOC);
	  if ( !is_array($this->reviewFields) ) {
	    $this->reviewExisted = 0;
	  }
	}
      }
    }

  function finalized() {
    return $this->reviewFields['finalized'];
  }

  function saveReview($Conf, $contactId)
    {
      //
      // Collect all the fields..
      //
      if ($this->valid) {
	$set = $this -> mkset();
	$myid = $contactId;
	
	if ($this->reviewExisted) {
	  $result=$Conf->qe("UPDATE PaperReview SET $set WHERE "
			   . " reviewer='$myid' AND paperId='$this->paperId'");
	} else {
	  $result=$Conf->qe("INSERT INTO PaperReview SET "
			   . " reviewer='$myid', paperId='$this->paperId', "
			   . $set );
	}
      } else {
	$Conf->errorMsg("Unable to save review because of invalid state");
      }
    }

  function printAnonReviewHeader($conf, $showAuthorsResponse=0)
    {
      return $this->printVisibleReviewHeader($conf,false,$showAuthorsResponse);
      $title = htmlentities($this->paperFields['title']);
      $abstract = nl2br(htmlentities($this->paperFields['abstract']));
      $collaborators = nl2br(htmlentities($this->paperFields['collaborators']));
      $authorsResponse = nl2br(htmlentities($this->paperFields['authorsResponse']));
      $mimetype=$this->paperFields['mimetype'];
      $paperId=$this->paperId;
      print "<table border=1 align=center>";

      print "<tr> <td width=15%> <h3> <b> Paper #$this->paperId </td> <td>";
      print "<a href=\"";
      print  $conf->makeDownloadPath($paperId, $mimetype);
      print "\" target=_blank>";
      print " (Download paper of type $mimetype ) </a> </b> <h3> </td> <tr>\n";
      print "<tr> <td> Title: </td> <td> $title </td> </tr>\n";
      print "<tr> <td> Abstract: </td> <td> $abstract </td> </tr>\n";
      //print "<tr> <td> Collaborators: </td> <td> $collaborators </td> </tr>\n";
      print "<tr> <td> Paper Topics: </td> <td>";
      foreach ($this->paperTopics as $topic ) {
        print "$topic<br>";
      }
      print "</td> </tr>\n";

      if ($showAuthorsResponse && strlen($authorsResponse) > 0) {
	print "<tr> <td> Authors Response: </td> <td ALIGN=LEFT> $authorsResponse </td> </tr>\n";
      } else if ($showAuthorsResponse) {
	print "<tr> <td colspan=2 align=center> No author response submitted </td> </tr>\n";
      }	
      print "</table>";
    }

  function printVisibleReviewHeader($conf, $showAuthor = true, $showResp = true)
    {
      $authorsResponse = nl2br(htmlentities($this->paperFields['authorsResponse']));
      if( 0 ){
      $title = htmlentities($this->paperFields['title']);
      $abstract = nl2br(htmlentities($this->paperFields['abstract']));
      $collaborators = nl2br(htmlentities($this->paperFields['collaborators']));
      $mimetype=$this->paperFields['mimetype'];

      $email = $this->authorFields['firstName']
	. ' '
	. $this->authorFields['lastName']
	. ' ('
	. $this->authorFields['email']
	. ')';

      $authorInfo = nl2br(htmlentities($this->paperFields['authorInformation']));

      $paperId=$this->paperId;
      print "<table border=1 align=center>";

      print "<tr> <td width=15%> <h3> <b> Paper #$this->paperId </td> <td>";
      print "<a href=\"";
      print  $conf->makeDownloadPath($paperId, $mimetype);
      print "\" target=_blank>";
      print " (Download paper of type $mimetype ) </a> </b> <h3> </td> <tr>\n";
      print "<tr> <td> Contact Email: </td> <td> $email </td> </tr>\n";
      print "<tr> <td> Author Info: </td> <td> $authorInfo </td> </tr>\n";
      print "<tr> <td> Title: </td> <td> $title </td> </tr>\n";
      print "<tr> <td> Abstract: </td> <td> $abstract </td> </tr>\n";
      print "<tr> <td> Collaborators: </td> <td> $collaborators </td> </tr>\n";
      print "<tr> <td> Paper Topics: </td> <td>";
      foreach ($this->paperTopics as $topic ) {
        print "$topic<br>";
      }
      print "</td> </tr>\n";
      }
      $conf->paperTable( $showAuthor, true, $this->paperId, false, false, false );
      if( $showResp ){
	print "<CENTER>";
	if (strlen($authorsResponse) > 0) {
	  print "<TABLE BORDER BGCOLOR='" . $conf->bgOne . "'><TR><TH>Authors Response:</TH></TR><TR>";
	  print "<TD ALIGN=LEFT> $authorsResponse</TD></TR></TABLE>\n";
	} else if ($showAuthorsResponse) {
	  print "<P>No author response submitted</P>\n";
	}	
	print "</CENTER>";
      }
      //print "</table>";
    }

  function printTableHeader()
    {
      print "<table columns=2 border=1 align=center>\n";
      print "<tr>\n";
      print "<th width=25%> Attribute </th>\n";
      print "<th width=75% colspan=12> Value </th>\n";
      print "</tr>\n";
    }

  function printTableFooter()
    {
      print "</table>";
    }

  function printEditable()
    {
      $this->printTableHeader();
      $this->printEditableAsRows();
      $this->printTableFooter();
    }

  function printViewable()
    {
      $this->printTableHeader();
      $this->printViewableAsRows();
      $this->printTableFooter();
    }

  function getAnonReviewHeaderASCII($conf)
    {
      $title = $this->paperFields['title'];
      $abstract = $this->paperFields['abstract'];
      $paperId=$this->paperId;

      return "Review for paper #$paperId\n"
	. "Title: $title\n"
	. "Abstract: $abstract\n\n"
	;
    }

  function getReviewASCII()
    {
      $msg = "";
      for($i = 0; $i < sizeof($this->fieldorder); $i++) {
	$name = $this->fieldorder[$i];
	$msg .= $this->fields[$name]->getLabel()
	  . " :\n " . $this->fields[$name]->getASCII()
	  . "\n\n";
      }
      return $msg;
    }


  /////////////////////////////////////////////////////////////////////////////
  // Viewing reviews
  /////////////////////////////////////////////////////////////////////////////
  function seeReviewAttribute()
    {
      $this -> printViewable();
    }

  function authorViewReview()
    {
      $this -> printViewable();
    }

  function getTextForAuthors() {
    $resultStr = "";
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];
      // exclude the fields that the authors should not see.
      // if the hidden fields are review form specific, this 
      // function should go in the subclasses
      if ($name != "finalized" and   $name != "commentsToPC") {
	$resultStr .= $this->fields[$name]->getLabel() .":\n";
	$resultStr .= $this->fields[$name]->getASCII(). "\n\n";
      }
    }
    return $resultStr;
  }
}

?>
