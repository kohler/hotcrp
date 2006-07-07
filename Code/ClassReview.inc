<?php 

class ReviewForm {

    var $updatedWhen;

    var $fieldOrder;
    var $shortName;
    var $fieldName;
    var $description;
    var $options;

    const SECTIONS = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";

    function ReviewForm($conf) {
	$this->updatedWhen = 0;
	$this->updateFromDB($conf);
    }

    function updateFromDB($conf) {
	$this->fieldOrder = array();
	$this->shortName = array();
	$this->fieldName = array();
	$this->description = array();
	$this->options = array();
	
	$result = $conf->qe("select fieldName, shortName, description, sortOrder from ReviewFormField order by sortOrder", "while updating review form information");
	if (DB::isError($result))
	    return;
	while ($row = $result->fetchRow(DB_FETCHMODE_OBJECT)) {
	    if ($row->sortOrder >= 0)
		$this->fieldOrder[] = $row->fieldName;
	    $this->shortName[$row->fieldName] = $row->shortName;
	    $this->fieldName[strtolower($row->shortName)] = $row->fieldName;
	    $this->description[$row->fieldName] = $row->description;
	}
	
	$result = $conf->qe("select fieldName, level, description from ReviewFormOptions order by fieldName, level", "while updating review form information");
	if (DB::isError($result))
	    return;
	while ($row = $result->fetchRow())
	    $this->options[$row[0]][$row[1]] = $row[2];
	$updatedWhen = time();
    }

    function validate($conf) {
	if (!$this->updatedWhen || $this->updatedWhen <= $conf->startTime('reviewFormUpdate'))
	    $this->updateFromDB($conf);
    }

    function formRows($rrow, $useRequest = 0) {
	global $ReviewFormError;
	$x = '';
	foreach ($this->fieldOrder as $field) {
	    $fval = "";
	    if ($useRequest && isset($_REQUEST[$field]))
		$fval = $_REQUEST[$field];
	    else if ($rrow != null)
		$fval = $rrow->$field;
	    
	    $x .= "<tr class='rev_$field'>\n";
	    $x .= "  <td class='form_caption";
	    if (isset($ReviewFormError[$field]))
		$x .= " error";
	    $x .= "'>" . htmlspecialchars($this->shortName[$field]) . ":</td>\n";
	    $x .= "  <td class='form_entry'>";
	    if (isset($this->options[$field])) {
		$x .= "<select name='$field'>\n";
		if ($fval == "" || $fval <= 0 || $fval > count($this->options[$field]))
		    $x .= "    <option value='0' selected='selected'>(Unknown)</option>\n";
		foreach ($this->options[$field] as $num => $what) {
		    $x .= "    <option value='$num'";
		    if ($num == $fval)
			$x .= " selected='selected'";
		    $x .= ">$num. " . htmlspecialchars($what) . "</option>\n";
		}
		$x .= "</select>";
	    } else {
		$x .= "<textarea name='$field'>" . htmlspecialchars($fval) . "</textarea>";
	    }
	    $x .= "</td>\n";
	    if ($this->description[$field])
		$x .= "  <td class='form_hint'>" . htmlspecialchars($this->description[$field]) . "</td>\n";
	    $x .= "</tr>\n";
	}
	return $x;
    }

    function validateRequest($rrow, $final, &$tf = null, $conf = null) {
	global $ReviewFormError, $Conf;
	$conf = ($conf ? $conf : $Conf);
	foreach ($this->fieldOrder as $field) {
	    if (!isset($_REQUEST[$field]) && !$final)
		continue;
	    $fval = (isset($_REQUEST[$field]) ? $_REQUEST[$field]
		     : ($rrow ? $rrow->$field : ""));
	    if (isset($this->options[$field])) {
		$fval = ltrim(rtrim($fval));
		$max = count($this->options[$field]);
		if ($fval == "" || $fval == "0") {
		    if ($final) {
			$provide[] = $field;
			$ReviewFormError[$field] = 1;
		    }
		} else if (($fval = cvtint($fval)) > 0 && $fval <= $max)
		    /* OK */;
		else if (preg_match("/^[0-9]+\\.", $fval, $matches)
			 && $matches[1] > 0 && $matches[1] <= $max)
		    $fval = $matches[1];
		else {
		    $outofrange[] = $field;
		    $ReviewFormError[$field] = 1;
		}
	    }
	}
	if (!isset($provide) && !isset($outofrange))
	    return 1;
	if (isset($provide))
	    $conf->errorMsg("You must choose values for " . textArrayJoin($provide) . " before submitting your review.  Please fix this and submit again.");
	if (isset($outofrange)) {
	    if ($tf)
		foreach ($outofrange as $field)
		    $tf['err'][] = $tf['fieldLineno'][$field] . ": Bad value for field \"" . htmlentities($this->shortName[$field]) . "\".";
	    else {
		foreach ($outofrange as $field)
		    $oor2[] = htmlspecialchars($this->shortName[$field]);
		$conf->errorMsg("Bad values for " . textArrayJoin($oor2) . ".  Please fix this and submit again.");
	    }
	}
	return 0;
    }

    function saveRequest($paperId, $contactId, $rrow, $final, $conf = null) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);
	$q = "";
	foreach ($this->fieldOrder as $field)
	    if (isset($_REQUEST[$field])) {
		$fval = $_REQUEST[$field];
		if (!isset($this->options[$field])) {
		    $fval = preg_replace("/\\A\\s*(.*?)\\s*\\Z/", '$1', $fval);
		    if ($fval != "")
			$fval .= "\n";
		    $_REQUEST[$field] = $fval;
		}
		$q .= "$field='" . sqlq($fval) . "', ";
	    }
	if ($final)
	    $q .= "finalized=1, ";
	if ($rrow)
	    return $conf->qe("update PaperReview set " . substr($q, 0, -2) . " where paperId=$paperId and contactId=$contactId", "while storing review");
	else
	    return $conf->qe("insert into PaperReview set paperId=$paperId, contactId=$contactId, " . substr($q, 0, -2), "while storing review");
    }

    
    function textForm($paperId, $prow, $rrow, $useRequest = 0, $conf = null) {
	global $ReviewFormError, $Conf;
	$conf = ($conf ? $conf : $Conf);
	$sections = self::SECTIONS;

	$x = "==+== " . $conf->shortName . " Paper Review Form
==-== DO NOT DELETE, ALTER, OR INTRODUCE LINES BEGINNING WITH '==+=='!
==-== Fill out lettered sections A through " . $sections[count($this->fieldOrder) - 1] . ".

==-== =========================================================================

==+== Paper #$paperId\n";
	if (isset($prow))
	    $x .= "==-== Title: $prow->title\n";

	$i = 0;
	foreach ($this->fieldOrder as $field) {
	    $x .= "\n==+== " . $sections[$i++] . ". " . $this->shortName[$field] . "\n";
	    if (isset($this->options[$field]))
		foreach ($this->options[$field] as $num => $value)
		    if ($num == 1)
			$x .= "==-== Choices are: $num. $value\n";
		    else
			$x .= "==-==              $num. $value\n";
	    $x .= "\n";
	    
	    $fval = "";
	    if ($useRequest && isset($_REQUEST[$field]))
		$fval = preg_replace("/\\A\\s*(.*?)\\s*\\Z/", '$1', $_REQUEST[$field]);
	    else if ($rrow != null)
		$fval = preg_replace("/\\s+\\Z/", '', $rrow->$field);
	    $x .= preg_replace("/^==\\+==/m", "\\==+==", $fval);

	    $x .= "\n";
	}
	return $x;
    }

    function garbageMessage(&$tf, $lineno, &$garbage) {
	if (isset($garbage))
	    $tf['err'][] = "$lineno: Review form appears to begin with garbage; ignoring it.";
	unset($garbage);
    }

    function beginTextForm($filename, $printFilename) {
	$fd = @fopen($filename, "rb");
	$contents = fread($fd, filesize($filename));
	fclose($fd);
	$contents = preg_replace("/\\r\\n/", "\n", $contents);
	$contents = strtr($contents, "\r", "\n");
	return array('text' => $contents, 'filename' => $printFilename,
		     'lineno' => 0, 'err' => array(), 'confirm' => array());
    }
    
    function parseTextForm(&$tf, $paperId, $conf = null) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

	$text = $tf['text'];
	$lineno = $tf['lineno'];
	$tf['firstLineno'] = $lineno + 1;
	$tf['fieldLineno'] = array();
	
	foreach ($this->fieldOrder as $field)
	    unset($_REQUEST[$field]);

	$mode = 0;
	$nfields = 0;
	$field = 0;
	$anyDirectives = 0;
	
	while ($text != "") {
	    $pos = strpos($text, "\n");
	    $line = ($pos === FALSE ? $text : substr($text, 0, $pos + 1));
	    $lineno++;

	    if (substr($line, 0, 6) == "==+== ") {
		$anyDirectives++;
		if (preg_match("/Paper Form/", $line)
		    && !preg_match("/$conf->shortName Paper Form/", $line)) {
		    $this->garbageMessage($tf, $lineno, $garbage);
		    $tf['err'][] = "$lineno: This review form appears to be for the wrong conference!";
		    return 0;
		} else if (preg_match("/^==\\+== Paper \#(\\d+)/", $line, $match)) {
		    if ($nfields > 0)
			break;
		    else {
			$_REQUEST['paperId'] = $match[1];
			$tf['firstLineno'] = $lineno;
		    }
		} else if (preg_match("/^==\\+== [A-Z]\\.\\s*(.*?)\\s*\$/", $line, $match)) {
		    $fn =& $this->fieldName[strtolower($match[1])];
		    if (isset($fn)) {
			$field = $fn;
			$tf['fieldLineno'][$fn] = $lineno;
			$nfields++;
		    } else {
			$this->garbageMessage($tf, $lineno, $garbage);
			$tf['err'][] = "$lineno: Review field \"" . htmlentities($match[1]) . "\" is not used for $conf->shortName reviews.  Ignoring this section.";
			$field = null;
		    }
		    $mode = 1;
		} else
		    $field = null;
	    } else if ($mode < 2 && (substr($line, 0, 5) == "==-==" || ltrim($line) == ""))
		/* ignore line */;
	    else {
		if ($mode == 0) {
		    $garbage = $line;
		    $field = null;
		}
		if ($field != null)
		    $_REQUEST[$field] = (isset($_REQUEST[$field]) ? $_REQUEST[$field] . $line : $line);
		$mode = 2;
	    }

	    $text = substr($text, strlen($line));
	}

	if ($nfields == 0 && $anyDirectives)
	    $tf['err'][] = "$lineno: That didn't appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.";

	$tf['text'] = $text;
	$tf['lineno'] = $lineno - 1;
	return $nfields > 0 || $text;
    }
    
    function parseTextFormErrors(&$tf, $conf = null) {
	global $Conf;
	$conf = ($conf ? $conf : $Conf);

	if (count($tf['err']) > 0) {
	    $filename = htmlspecialchars($tf['filename']);
	    $conf->errorMsg("There were some errors while parsing the uploaded reviews file. <div class='parseerr'><p>$filename:" . join("</p>\n<p>$filename:", $tf['err']) . "</p></div>");
	}

	if (count($tf['confirm']) > 0)
	    $conf->confirmMsg("<div class='parseerr'><p>" . join("</p>\n<p>", $tf['confirm']) . "</p></div>");
    }
    
}


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

	$query="select TopicArea.topicName from TopicArea INNER JOIN PaperTopic ON TopicArea.topicId = PaperTopic.topicId WHERE PaperTopic.paperId = " . $this->paperId;
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
			   . " contactId='$myid' AND paperId='$this->paperId'");
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
			   . " contactId='$myid' AND paperId='$this->paperId'");
	} else {
	  $result=$Conf->qe("INSERT INTO PaperReview SET "
			   . " contactId='$myid', paperId='$this->paperId', "
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
