<?php
// Code/reviewtemplate.inc -- HotCRP template review forms
// Placed in the public domain

function setField($field, $shortname, $authorview, $description, $order, $options = null) {
    $_REQUEST["shortName_$field"] = $shortname;
    if ($authorview)
	$_REQUEST["authorView_$field"] = 1;
    else
	unset($_REQUEST["authorView_$field"]);
    $_REQUEST["description_$field"] = $description;
    $_REQUEST["order_$field"] = $order;
    if ($options !== null) {
	$ot = "";
	reset($options);
	$on = key($options);
	foreach ($options as $ox => $o)
	    $ot .= ($on ? $ox : $ox + 1) . ". " . $o . "\n";
	$_REQUEST["options_$field"] = $ot;
    }
}

function unsetField($field, $shortname) {
    unset($_REQUEST["shortName_$field"]);
    $_REQUEST["order_$field"] = -1;
}

function unsetWholeField($field) {
    unset($_REQUEST["shortName_$field"]);
    unset($_REQUEST["order_$field"]);
    unset($_REQUEST["authorView_$field"]);
    unset($_REQUEST["description_$field"]);
    unset($_REQUEST["options_$field"]);
}

function loadedForm($conf) {
    global $Conf;
    $Conf->infoMsg("This page now shows a version of the review form for
$conf.  Update it as you'd like, then <b>Save changes</b> to save the form,
or <b>Cancel</b> to return to the current form.");
}

function sigcomm2005Form() {
    setField("overAllMerit", "Overall merit", true,
	     "Overall paper merit", 1,
	     array("Bottom 50% of submitted papers",
		   "Top 50% but not top 25% of submitted papers",
		   "Top 25% but not top 10% of submitted papers",
		   "Top 10% but not top 5% of submitted papers",
		   "Top 5% of submitted papers!"));
    setField("reviewerQualification", "Reviewer qualification", true,
	     "Your qualifications to review this paper", 2,
	     array("I know nothing about this area",
		   "I have passing familiarity",
		   "I know the material, but am not an expert",
		   "I know a lot about this area",
		   "I am an expert on this topic"));
    setField("paperSummary", "Paper summary", true,
	     "Please summarize this paper in your own words.", 3);
    setField("strengthOfPaper", "Strengths", true,
	     "What are the strengths of this paper?  Just a couple sentences, please.", 4);
    setField("weaknessOfPaper", "Weaknesses", true,
	     "What are the weaknesses of this paper?  Just a couple sentences, please.", 5);
    setField("commentsToAuthor", "Comments to author", true,
	     "Detailed comments to the author.", 6);
    setField("commentsToPC", "Comments to PC", false,
	     "", 7);
    unsetField("longevity", "Long-term importance");
    unsetField("grammar", "Editorial quality");
    unsetField("suitableForShort", "Suitable for short paper?");
    unsetField("novelty", "Novelty");
    unsetField("technicalMerit", "Technical merit");
    unsetField("interestToCommunity", "Interest to community");
    unsetField("commentsToAddress", "Comments to address in response");
    unsetField("likelyPresentation", "Value of presentation");
    unsetField("potential", "Potential");
    unsetField("fixability", "Fixability");
    unsetField("textField7", "Additional text field");
    unsetField("textField8", "Additional text field");
    loadedForm("the SIGCOMM 2005 conference");
}

function worlds2005Form() {
    setField("overAllMerit", "Overall marks", true,
	     "", 1,
	     array("Bottom 50% of submitted papers",
		   "Top 50% but not top 25% of submitted papers",
		   "Top 25% but not top 10% of submitted papers",
		   "Top 10% but not top 5% of submitted papers",
		   "Top 5% of submitted papers!"));
    setField("reviewerQualification", "Confidence level", true,
	     "How confident are you in your assessment of the paper?", 2,
	     array("Not at all confident",
		   "Somewhat confident",
		   "Confident",
		   "Extremely confident"));
    setField("technicalMerit", "Technical quality", true,
	     "", 3,
	     array("Bottom 50% of submitted papers",
		   "Top 50% but not top 25% of submitted papers",
		   "Top 25% but not top 10% of submitted papers",
		   "Top 10% but not top 5% of submitted papers",
		   "Top 5% of submitted papers!"));
    setField("grammar", "Editorial quality", true,
	     "", 4,
	     array("Bottom 50% of submitted papers",
		   "Top 50% but not top 25% of submitted papers",
		   "Top 25% but not top 10% of submitted papers",
		   "Top 10% but not top 5% of submitted papers",
		   "Top 5% of submitted papers!"));
    setField("interestToCommunity", "Suitability", true,
	     "", 5,
	     array("Not suitable for this conference",
		   "Somewhat suitable",
		   "Good match for this conference"));
    setField("commentsToPC", "Comments to PC", false,
	     "", 6);
    setField("commentsToAuthor", "Comments to authors", true,
	     "", 7);
    setField("paperSummary", "Comments to the public", true,
	     "", 8);
    unsetField("longevity", "Long-term importance");
    unsetField("suitableForShort", "Suitable for short paper?");
    unsetField("novelty", "Novelty");
    unsetField("commentsToAddress", "Comments to address in response");
    unsetField("likelyPresentation", "Value of presentation");
    unsetField("potential", "Potential");
    unsetField("fixability", "Fixability");
    unsetField("strengthOfPaper", "Paper strengths");
    unsetField("weaknessOfPaper", "Paper weaknesses");
    unsetField("textField7", "Additional text field");
    unsetField("textField8", "Additional text field");
    loadedForm("the WORLDS 2005 workshop");
}

function cgo36Form() {
    setField("paperSummary", "Paper summary", true,
	     "Provide a short summary of the paper.", 1);
    setField("strengthOfPaper", "Strengths", true,
	     "What is the strength of the paper? (1-3 sentences)", 2);
    setField("weaknessOfPaper", "Weaknesses", true,
	     "What is the weakness of the paper? (1-3 sentences)", 3);
    setField("reviewerQualification", "Reviewer qualification", true,
	     "Your qualifications to review this paper", 4,
	     array("I know nothing about this area",
		   "I have passing familiarity",
		   "I know the material, but am not an expert",
		   "I know a lot about this area",
		   "I am an expert on this topic"));
    setField("grammar", "Writing quality", true,
	     "", 5,
	     array("Unacceptable", "Poor", "Average", "Good", "Excellent"));
    setField("interestToCommunity", "Relevance", true,
	     "", 6,
	     array("I do not think people would read the paper or attend the session",
		   "I doubt anyone would attend a session including this paper",
		   "People would attend a session including this paper",
		   "People would attend the session, but not read the paper beforehand",
		   "People would read the paper before the conference and attend the session"));
    setField("technicalMerit", "Experimental methodology", true,
	     "", 7,
	     array("Unacceptable", "Poor", "Average", "Good", "Excellent"));
    setField("novelty", "Paper novelty", true,
	     "", 8,
	     array("This has been done and published before",
		   "This has been done before",
		   "Incremental improvement",
		   "This is a new contribution to an established area",
		   "This is very novel"));
    setField("overAllMerit", "Overall merit", true,
	     "", 9,
	     array('Reject - Paper is not of interest, it has already been done, or has serious methodology flaws.',
		   'Weak Reject - Paper is potentially of interest, and has some value in the ideas presented, but it has either potential methodology issues or it is a borderline incremental/already been done contribution.',
		   'Weak Accept - Paper is of interest, and is an incremental contribution paper with average/weak methodology.',
		   'Accept - This is of interest, a novel or new contribution with average/weak methodology, or incremental contribution paper that has good methodology.',
		   'Strong accept - This is of interest, and a novel or new contribution to existing area with good/average methodology, or an incremental contribution paper that has excellent methodology.',
		   'Definite accept - This is of interest, and a novel or new contribution to existing area with excellent/good methodology.'));
    setField("commentsToAddress", "Comments to address", true,
	     "Provide comments for the author to address in the rebuttal.", 10);
    setField("commentsToAuthor", "Comments to author", true,
	     "Provide additional detailed comments to the authors.", 11);
    setField("commentsToPC", "Comments to PC", false,
	     "", 12);
    unsetField("longevity", "Long-term importance");
    unsetField("suitableForShort", "Suitable for short paper?");
    unsetField("likelyPresentation", "Value of presentation");
    unsetField("potential", "Potential");
    unsetField("fixability", "Fixability");
    unsetField("textField7", "Additional text field");
    unsetField("textField8", "Additional text field");
    loadedForm("the CGO 2004 conference");
}

function hotnetsVForm() {
    setField("overAllMerit", "Overall merit", true,
	     "", 1,
	     array("Reject", "Weak reject", "Weak accept", "Accept", "Strong accept"));
    setField("reviewerQualification", "Reviewer expertise", true,
	     "", 2,
	     array("No familiarity", "Some familiarity", "Knowledgeable", "Expert"));
    setField("novelty", "Novelty", true,
	     "", 3,
	     array("Published before", "Very incremental",
		   "Incremental", "Novel", "This paper will surprise the community"));
    setField("technicalMerit", "Technical merit", true,
	     "", 4,
	     array("No technical merit", "Obvious flaws", "Good/minor flaws", "Outstanding"));
    setField("interestToCommunity", "Discussability", true,
	     "", 5,
	     array("No", "Somewhat", "Yes", "Passionate fights will break out, hire security"));
    setField("paperSummary", "Paper summary", true,
	     "", 6);
    setField("commentsToAuthor", "Comments for author", true,
	     "", 7);
    setField("commentsToPC", "Comments for PC", false,
	     "", 8);
    setField("suitableForShort", "Should the authors be invited?", false,
	     "Based on the contents of the paper, do you think the authors would make a contribution to the workshop atmosphere, and should be invited independent of whether their paper gets in?  For non-blind submissions, please think more about the paper than the authors' identities.", 9,
	     array("No", "Maybe", "Yes", "No opinion"));
    unsetField("strengthOfPaper", "Strengths");
    unsetField("weaknessOfPaper", "Weaknesses");
    unsetField("grammar", "Writing quality");
    unsetField("commentsToAddress", "Comments to address");
    unsetField("longevity", "Long-term importance");
    unsetField("likelyPresentation", "Value of presentation");
    unsetField("potential", "Potential");
    unsetField("fixability", "Fixability");
    unsetField("textField7", "Additional text field");
    unsetField("textField8", "Additional text field");
    loadedForm("the HotNets V workshop");
}

function pldi2008Form() {
    setField("paperSummary", "Summary", true,
	     "Please give a brief, 2-3 sentence summary of the main ideas of the paper.", 1);
    setField("strengthOfPaper", "Strength", true,
	     "What is the strength of the paper? (1-3 sentences)", 2);
    setField("weaknessOfPaper", "Weakness", true,
	     "What is the weakness of the paper? (1-3 sentences)", 3);
    setField("overAllMerit", "Evaluation", true,
	     "", 4,
	     array("A" => "Excellent paper. I'd really want to see this paper in PLDI (will Champion it if I am in the PC).",
		   "B" => "Good paper, I don't mind having it in PLDI.",
		   "C" => "Weak paper, though it will not be an embarrassment to have it in PLDI.",
		   "D" => "Serious problems.  This paper should not be in PLDI (will fight to reject it if I am in the PC)."));
    setField("reviewerQualification", "Confidence", true,
	     "", 5,
	     array("X" => "I am an expert in the subject area of this paper.",
		   "Y" => "I am knowledgeable in the area, though not an expert.",
		   "Z" => "I am not an expert. My evaluation is that of an informed outsider."));
    setField("likelyPresentation", "Can we anonymously publish this review?", false,
	     "This year, we plan to create a discussion board for PLDI papers. If this paper is accepted, we would like to seed the discussion of this paper with the summary, strength and weakness sections of your review. These comments will be published anonymously (eg: \"PLDI Reviewer 1 Said:\").", 6,
	     array("Yes", "No"));
    setField("commentsToAuthor", "Detailed Comments", true,
	     "Please supply detailed comments to back up your rankings. These comments will be forwarded to the authors of the paper.", 7);
    setField("commentsToAddress", "Questions for Authors", true,
	     "Please use this area to pose specific questions the authors should resolve.", 8);
    setField("commentsToPC", "Confidential Comments for Committee", false,
	     "You may wish to withhold some comments from the authors, and include them solely for the committee's internal use.", 9);
    unsetField("novelty", "Novelty");
    unsetField("technicalMerit", "Technical merit");
    unsetField("interestToCommunity", "Discussability");
    unsetField("suitableForShort", "Should the authors be invited?");
    unsetField("grammar", "Writing quality");
    unsetField("longevity", "Long-term importance");
    unsetField("potential", "Potential");
    unsetField("fixability", "Fixability");
    unsetField("textField7", "Additional text field");
    unsetField("textField8", "Additional text field");
    loadedForm("the PLDI 2008 conference");
}

function noForm() {
    unsetWholeField("overAllMerit");
    unsetWholeField("reviewerQualification");
    unsetWholeField("technicalMerit");
    unsetWholeField("grammar");
    unsetWholeField("interestToCommunity");
    unsetWholeField("commentsToPC");
    unsetWholeField("commentsToAuthor");
    unsetWholeField("paperSummary");
    unsetWholeField("longevity");
    unsetWholeField("suitableForShort");
    unsetWholeField("novelty");
    unsetWholeField("commentsToAddress");
    unsetWholeField("likelyPresentation");
    unsetWholeField("potential");
    unsetWholeField("fixability");
    unsetWholeField("strengthOfPaper");
    unsetWholeField("weaknessOfPaper");
    unsetWholeField("textField7");
    unsetWholeField("textField8");
}
