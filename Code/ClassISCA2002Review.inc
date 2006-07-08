<?php 
include('../Code/ClassReview.inc');

//
// Create strings we use for labels
//
$scoreName[1] = 'Worst, unacceptable';
$scoreName[2] = 'Poor';
$scoreName[3] = 'Average';
$scoreName[4] = 'Good';
$scoreName[5] = 'Excellent';

$expertiseName[1] = 'I know nothing about this area';
$expertiseName[2] = 'I have passing familiarity';
$expertiseName[3] = 'I know the material, but am not an expert';
$expertiseName[4] = 'I know a lot about this area';
$expertiseName[5] = 'I am a veritable font of knowledge about this topic';

$yesNoName[1] = 'No, this can not be a short paper';
$yesNoName[2] = 'Hard to say';
$yesNoName[3] = 'Yes, this could be a short paper';

$overallName[1] = 'Reject with extreme prejudice';
$overallName[2] = 'Reject, but there is some value';
$overallName[3] = "I can't make up my mind";
$overallName[4] = 'There are problems, but acceptable';
$overallName[5] = 'Definite accept';

$noveltyName[1] = 'This has been done and published before';
$noveltyName[2] = 'This has been done before';
$noveltyName[3] = 'Incremental improvement';
$noveltyName[4] = 'This is a new contribution to an established area';
$noveltyName[5] = 'This is very novel';

$interestName[1] = 'I do not think people would read the paper nor attend the session';
$interestName[2] = 'I doubt anyone would attend session';
$interestName[3] = 'People would attend session';
$interestName[4] = 'People would attend session, but not read it beforehand';
$interestName[5] = 'People would read it before confernece and attend session';

$finalizeName[0] = "Don't finalize, I want to continue editing";
$finalizeName[1] = "Finalize, I am done editing";

class ISCA2002Review extends ConferenceReview {

  //
  // Review form entries
  //
  var $formFinalized;
  var $formReviewerQualification;
  var $formOverAllMerit;
  var $formNovelty;
  var $formTechnicalMerit;
  var $formInterestToCommunity;
  var $formLongevity;
  var $formGrammar;
  var $formSuitableForShort;
  var $formPaperSummary;
  var $formCommentsToAuthor;
  var $formCommentsToPC;

  function ISCA2002Review($Conf, $contactId, $pid) {
    $this->ConferenceReview($Conf, $contactId, $pid);

    global $scoreName;
    global $expertiseName;
    global $yesNoName;
    global $overallName;
    global $finalizeName;
	global $noveltyName;
	global $interestName;

    //
    // Tell the check routine which fields need to be defined
    //
    $this->checkFields = array
      ('reviewSubmitted', 'reviewerQualification',
       'overAllMerit', 'novelty', 'technicalMerit',
       'interestToCommunity', 'longevity',
       'grammar', 
       'suitableForShort',
       'paperSummary', 'commentsToAuthor', 'commentsToPC');

    $this->shortFields = array
      ('reviewSubmitted', 'reviewerQualification',
       'overAllMerit', 'novelty', 'technicalMerit',
       'interestToCommunity', 'longevity',
       'grammar', 
       'suitableForShort');

    $this->longFields = array(
       'paperSummary', 'commentsToAuthor', 'commentsToPC');

    if ($this -> valid) {
      //
      // The order we add these is important
      //

      $formFinalized = new FormNumericLabeledRange("reviewSubmitted",
						  $finalizeName,
						   0, 1,
						  "Are you finished with this review?"
						  );
      $this->add($formFinalized);

      $col = 50;
      $formPaperSummary = new FormTextArea("paperSummary", $col, 10,
					   "Provide a short summary of the paper");
      $this->add($formPaperSummary);

      $formReviewQualification = new FormNumericLabeledRange("reviewerQualification",
							    $expertiseName, 1, 5,
							    "Your qualifications "
							    . " to review this paper ");
      $this->add($formReviewQualification);

      $formOverAllMerit
	= new FormNumericLabeledRange("overAllMerit",
				      $overallName, 1, 5,
				      "Overall paper merit");
      $this->add($formOverAllMerit);
      
      $formNovelty
	= new FormNumericLabeledRange("novelty",
				      $noveltyName, 1, 5,
				      "Novelty of paper");
      $this->add($formNovelty);
      
      $formTechnicalMerit
	= new FormNumericLabeledRange("technicalMerit",
				      $scoreName, 1, 5,
				      "Was the paper technically sound?");
      $this->add($formTechnicalMerit);
      
      $formInterestToCommunity
	= new FormNumericLabeledRange("interestToCommunity",
				      $interestName, 1, 5,
				      "Do you think the paper will be "
				      . " of interest to the ISCA community?" );
      $this->add($formInterestToCommunity);

      $formLongevity
	= new FormNumericLabeledRange("longevity",
				      $scoreName, 1, 5,
				      "Will this paper be important"
				      . " over time?" );
      $this->add($formLongevity);

      $formGrammar
	= new FormNumericLabeledRange("grammar",
				      $scoreName, 1, 5,
				      "Spelling and Grammar?");
      $this->add($formGrammar);
      
      $formSuitableForShort
	= new FormNumericLabeledRange("suitableForShort",
				      $yesNoName, 1, 3,
				      "Could this be a short paper?");
      $this->add($formSuitableForShort);
      
      $formCommentsToAuthor
	= new FormTextArea("commentsToAuthor",
			   50, 20,
			   "Provide detailed comments to the author");
      $this->add($formCommentsToAuthor);
      
      $formCommentsToPC
	= new FormTextArea("commentsToPC",
			   50, 20,
			   "Additional comments to PC <br> (not seen by author)" );
      $this->add($formCommentsToPC);

      //
      // Now load the review from the retrieved data
      //
      if ($this->reviewExisted) {
	$this->load($this->reviewFields);
      }
    }
  }

  //
  // Make certain values are provided for each field.
  // This operates on the posted values, before they're
  // assigned to the form entities for writeback.
  //
  function checkForm($Conf)
    {
      $finalized = $_POST['reviewSubmitted'];

      if ($finalized) {
	reset($this->checkFields);
	for($i = 0; $i < sizeof($this->checkFields); $i++) {
	  $key = $this->checkFields[$i];

	  if ($key != "reviewSubmitted") {
	    $formv = $_POST[$key];
	    if ($formv != "" || $formv != 0) {
	      //
	      // Do nothing - valid entry
	      //
	    } else {
	      $Conf->errorMsg("You need to provide a value for all the fields ($key). "
			      . "You can not finalize your review until you provide "
			      . "values for each field.");
	      $_POST['reviewSubmitted'] = 0;
	      return;
	    }
	  }
	}
      }
    }

  function printViewableAsRows() {
    for($i = 0; $i < sizeof($this->fieldorder); $i++) {
      $name = $this->fieldorder[$i];

      if ($name == "overAllMerit"
	|| $name == "technicalMerit"
	|| $name=="commentsToPC") {
	$start="<b>";
	$end="</b>";
      } else {
	$start=$end="";
      }

      print "<tr> <td> $start\n";
      $this->fields[$name]->printLabel();
      print "$end </td> <td> $start\n";
      $this->fields[$name]->viewable();
      print "$end </td> </tr>\n";
    }
  }


  function authorViewReview($Conf)
    {

      $this->printTableHeader();

      $name="paperSummary";
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";

      $name="commentsToAuthor";
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";

      $this->printTableFooter();
    }
  

  function authorViewDecision($Conf)
    {
      $this->printTableHeader();

      for($i = 0; $i < sizeof($this->fieldorder); $i++) {
	$name = $this->fieldorder[$i];

	if ($name == "reviewSubmitted" || $name == "commentsToPC" ) {
	  //
	  // Don't print these fields
	  //
	} else {

	  if ($name == "overAllMerit"
	      || $name == "technicalMerit") {
	    $start="<b>";
	    $end="</b>";
	  } else {
	    $start=$end="";
	  }

	  print "<tr> <td> $start\n";
	  $this->fields[$name]->printLabel();
	  print "$end </td> <td> $start\n";
	  $this->fields[$name]->viewable();
	  print "$end </td> </tr>\n";
	}
      }
      $this->printTableFooter();
    }
}


?>
