<?php 
include('../Code/ClassReview.inc');
//
// This is the configuration file the sample HYPO conference that comes with
// the CRP distribution. This is adapted from the MICRO36 review form
//

//
// Create strings we use for labels
//
$scoreName[1] = 'Unacceptable';
$scoreName[2] = 'Poor';
$scoreName[3] = 'Average';
$scoreName[4] = 'Good';
$scoreName[5] = 'Excellent';

$expertiseName[1] = 'I know nothing about this area';
$expertiseName[2] = 'I have passing familiarity';
$expertiseName[3] = 'I know the material, but am not an expert';
$expertiseName[4] = 'I know a lot about this area';
$expertiseName[5] = 'I am an expert on this topic';

$yesNoName[1] = 'No, this can not be a short paper';
$yesNoName[2] = 'Hard to say';
$yesNoName[3] = 'Yes, this could be a short paper';

$overallName[1] = 'Reject - Paper is not of interest to HYPO, it has already been done, or has serious methodology flaws.';
$overallName[2] = 'Weak Reject - Paper is potentially of interest to HYPO, and has some value in the ideas presented, but it has either potential methodology issues or it is a borderline incremental/already been done contribution.';
$overallName[3] = 'Weak Accept - Paper is of interest to HYPO, and is an incremental contribution paper with average/weak methodology.';
$overallName[4] = 'Accept - This is of interest to HYPO, a novel or new contribution with average/weak methodology, or incremental contribution paper that has good methodology.';
$overallName[5] = 'Strong accept - This is of interest to HYPO, and a novel or new contribution to existing area with good/average methodology, or an incremental contribution paper that has excellent methodology.';
$overallName[6] = 'Definite accept - This is of interest to HYPO, and a novel or new contribution to existing area with excellent/good methodology.';

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

class HYPO2010Review extends ConferenceReview {

  //
  // Review form entries
  //
  var $formFinalized;
  var $formStrengthOfPaper;
  var $formWeaknessOfPaper;
  var $formReviewerQualification;
  var $formOverAllMerit;
  var $formNovelty;
  var $formTechnicalMerit;
  var $formInterestToCommunity;
  var $formLongevity;
  var $formGrammar;
  //var $formSuitableForShort;
  var $formPaperSummary;
  var $formCommentsToAddress;
  var $formCommentsToAuthor;
  var $formCommentsToPC;

  function HYPO2010Review($Conf, $contactId, $pid) {
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
      ('reviewSubmitted', 'strengthOfPaper', 'weaknessOfPaper',
       'reviewerQualification', 'overAllMerit', 'novelty', 'technicalMerit',
       'interestToCommunity',
       //'longevity',
       'grammar', 
       //'suitableForShort',
       'paperSummary', 'commentsToAddress', 'commentsToAuthor', 'commentsToPC');

    $this->shortFields = array
      ('reviewSubmitted', 'reviewerQualification',
       'overAllMerit', 'novelty', 'technicalMerit',
       'interestToCommunity',
       //'longevity',
       'grammar', 
       //'suitableForShort'
);

    $this->longFields = array( 'strengthOfPaper', 'weaknessOfPaper',
       'paperSummary', 'commentsToAddress', 'commentsToAuthor', 'commentsToPC');

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

      $formStrengthOfPaper = new FormTextArea("strengthOfPaper", $col, 10,
					   "What is the strength of the paper? (1-3 sentences)");
      $this->add($formStrengthOfPaper);

      $formWeaknessOfPaper = new FormTextArea("weaknessOfPaper", $col, 10,
					   "What is the weakness of the paper? (1-3 sentences)");
      $this->add($formWeaknessOfPaper);


      $formReviewQualification = new FormNumericLabeledRange("reviewerQualification",
							    $expertiseName, 1, 5,
							    "Your qualifications "
							    . " to review this paper ");
      $this->add($formReviewQualification);

      $formGrammar
	= new FormNumericLabeledRange("grammar",
				      $scoreName, 1, 5,
				      "Writing Quality");
      $this->add($formGrammar);
      
      $formInterestToCommunity
	= new FormNumericLabeledRange("interestToCommunity",
				      $interestName, 1, 5,
				      "Relevance to HYPO?" );
      $this->add($formInterestToCommunity);
      
      $formTechnicalMerit
	= new FormNumericLabeledRange("technicalMerit",
				      $scoreName, 1, 5,
				      "Experimental Methodology");
      $this->add($formTechnicalMerit);
      
      $formNovelty
	= new FormNumericLabeledRange("novelty",
				      $noveltyName, 1, 5,
				      "Novelty of paper");
      $this->add($formNovelty);

      $formOverAllMerit
	= new FormLongTextLabeledRange("overAllMerit",
				      $overallName, 1, 6,
				      "Overall paper merit");
      $this->add($formOverAllMerit);

//      $formLongevity
//	= new FormNumericLabeledRange("longevity",
//				      $scoreName, 1, 5,
//				      "Will this paper be important"
//				      . " over time?" );
//      $this->add($formLongevity);

//      $formSuitableForShort
//	= new FormNumericLabeledRange("suitableForShort",
//				      $yesNoName, 1, 3,
//				      "Could this be a short paper?");
//      $this->add($formSuitableForShort);

      $formMessage
	= new FormMessage(
"During the rebuttal process, both of the following 2 boxes (comments " .
"to be addressed in the rebuttal and additional comments) will be given " .
"to the authors to read.  In addition, the summary, and the strength " .
"and weakness boxes will also be given to the authors during the " .
"rebuttal.  Note, no numerical scores or rankings will be given to the " .
"authors only the comments in these 2 boxes.  The authors will be " .
"instructed to focus their rebuttal on the comments in the rebuttal " .
"box. Please list in the rebuttal box only the concerns you explicitly " .
"want the authors to respond to in order to help the authors focus " .
"their rebuttal.",
			   "Message from the Chair");
      //$this->add($formMessage);

      $formCommentsToAddress
	= new FormTextArea("commentsToAddress",
			   50, 20,
			   "Provide comments for the author to address in the rebuttal");
      $this->add($formCommentsToAddress);
      
      $formCommentsToAuthor
	= new FormTextArea("commentsToAuthor",
			   50, 20,
			   "Provide additional detailed comments to the author");
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

      $name="strengthOfPaper";
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";

      $name="weaknessOfPaper";
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";

      $name="paperSummary";
      print "<tr> <td>\n";
      $this->fields[$name]->printLabel();
      print "</td> <td>\n";
      $this->fields[$name]->viewable();
      print "</td> </tr>\n";

      $name="commentsToAddress";
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
