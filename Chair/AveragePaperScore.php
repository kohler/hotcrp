<?php 
require_once('../Code/header.inc');
$Me = $_SESSION["Me"];
$Me->goIfInvalid();
$Me->goIfNotPC('../index.php');

$isChair = $_SESSION['Me']->isChair;
$meetingTime = $Conf->timePCViewGrades();

$outcomeName = array(
  "unspecified" => "Unspecified",
  "accepted" => "Accept",
  "rejected" => "Reject",
  "acceptedShort" => "Short Paper"
);

$pcmodes = array(
  "PCAll" => "Show All Papers",
  "PCX" => "X Out PC Member Papers",
  "PCHide" => "Hide PC Member Papers",
  "PCOnly" => "Only Show PC Member Papers"
);

$pcmode = "";

if( IsSet($_REQUEST['pcmode']) ){
  $pcmode = $_REQUEST['pcmode'];
}

if( !IsSet($pcmodes[$pcmode]) ){
  $pcmode = "PCAll";
}

$stats = array( "AVG" => "Average", "STDDEV" => "Standard Deviation" );

$stat = "";

if( IsSet($_REQUEST['stat']) ){
  $stat = $_REQUEST['stat'];
}

if( !IsSet($stats[$stat]) ){
  $stat = "AVG";
}


$graphs = array(
  "grade" => "PC Grade",
  "overAllMerit" => "Overall Merit",
  "reviewerQualification" => "Reviewer Qualification",
  "novelty" => "Novelty",
  "technicalMerit" => "Technical Merit",
  "grammar" => "Writing Quality"
  );

$target = "";

if( IsSet($_REQUEST['target']) ){
  $target = $_REQUEST['target'];
}

if( !IsSet($graphs[$target]) ){
  $target = "grade";
}

if( 0 ){
$group_order = array( "groupABC", "groupA-E", "groupRest", "groupCDE" );

$groups = array(
  "groupABC" => "Only A, B or C",
  "groupA-E" => "At Least One A, No Es (has a D)",
  "groupRest" => "At Least One B (has a D or an E)",
  "groupCDE" => "Only C, D or E",
  );
} else {
$group_order = array( "groupW", "groupX", "groupY", "groupZ");

$groups = array(
  "groupW" => "Group W",
  "groupX" => "Group X",
  "groupY" => "Group Y",
  "groupZ" => "Group Z",
  );
}

$kept_groups = array();

if( IsSet($_REQUEST['groups']) ){
  foreach( $_REQUEST['groups'] as $key => $val ){
    if( IsSet($groups[$val]) ){
      $kept_groups[$val] = 1;
    }
  }
}

if( IsSet($_REQUEST['use_groups']) ){
  $use_groups = 1;
} else {
  $use_groups = 0;
}

if( (! $_SESSION['Me']->isChair) && $Conf->timePCViewGrades()) ) {
  $use_groups = 1;
  foreach( $groups as $key => $val ){
    $kept_groups[$key] = 1;
  }
}

?>

<html>

<?php  $Conf->header("Distribution of " . $graphs[$target] . " Scores for Papers") ?>

<body>
<H1>Choose Sorting Order and View Options</H1>
<FORM METHOD="POST" ACTION="<?php echo $_SERVER['PHP_SELF'] ?>" TARGET=_blank>
<H2>Information to show</H2>
<P>
<?php
$redCol = 0;
$judgeCol = 0;
$judgeData = "";
$authorData = "";
$authorSpace = "";
$authorTable = "";
$authorQual = "";

if( $_SESSION['Me']->isChair ){
  print "<INPUT TYPE='CHECKBOX' NAME='AuthorRow'";
  if( IsSet($_REQUEST['AuthorRow']) ){
    $authorRow = 1;
    $authorSpace = " ROWSPAN=2 ";
    $authorData = " , Paper.authorInformation, ContactInfo.email, ContactInfo.firstName, ContactInfo.lastName, ContactInfo.affiliation ";
    $authorTable = " , ContactInfo ";
    $authorQual = " AND ContactInfo.contactId = Paper.contactId ";
    print " CHECKED";
  }
  print "> Author Info Row<BR>\n";
  print "<INPUT TYPE='CHECKBOX' NAME='RedCol'";
  if( IsSet($_REQUEST['RedCol']) ){
    $redCol = 1;
    print " CHECKED";
  }
  print "> Highlighted PC Paper Column<BR>\n";
  print "<INPUT TYPE='CHECKBOX' NAME='GradeCol'";
  if( IsSet($_REQUEST['GradeCol']) ){
    $judgeCol = 1;
    $judgeData = ", Paper.outcome ";
    print " CHECKED";
  }
  print "> Judgement Column<BR>\n";
}

if( IsSet($_REQUEST['COLS']) ){
  $kept_graphs = array();
  foreach( $_REQUEST['COLS'] as $ind => $val ){
    if( IsSet($graphs[$val]) ){
      $kept_graphs[$val] = $graphs[$val];
    }
  }
} else {
  $kept_graphs = $graphs;
}

foreach($graphs as $val => $name){
  print "<INPUT TYPE='CHECKBOX' NAME='COLS[]' VALUE='$val'";
  if( IsSet($kept_graphs[$val]) ){
    print " CHECKED";
  }
  print "> $name Column<BR>\n";
}

print "</P>\n";

if( ($_SESSION['Me']->isChair) || (! $Conf->timePCViewGrades()) ) {
?>
<H2>Sort Order and Row Selection</H2>
<SELECT NAME="target">
<?php 
foreach($graphs as $val => $name){
  print "<OPTION VALUE=\"$val\"";
  if( $val == $target ){
    print " SELECTED";
  }
  print ">$name</OPTION>\n";
}
?>
</SELECT>
<SELECT NAME="stat">
<?php 
foreach($stats as $val => $name){
  print "<OPTION VALUE=\"$val\"";
  if( $val == $stat ){
    print " SELECTED";
  }
  print ">$name</OPTION>\n";
}

?>
</SELECT>
<?php 
}

if( $_SESSION['Me']->isChair ){
  print '<SELECT NAME="pcmode">';

  foreach($pcmodes as $val => $name){
    print "<OPTION VALUE=\"$val\"";
    if( $val == $pcmode ){
      print " SELECTED";
    }
    print ">$name</OPTION>\n";
  }
  print '</SELECT>';
}

//if( ($_SESSION['Me']->isChair) || ($Conf->timePCViewGrades()) ) {
if( ($_SESSION['Me']->isChair) ) {
  print "<H2>Discussion Order</H2>\n";

  print "<TABLE><TR><TD ALIGN=TOP><INPUT TYPE='CHECKBOX' NAME='use_groups'";
  if( $use_groups ){
    print " CHECKED";
  }
  print ">Enable Discussion Order: </TD><TD>\n";
  foreach($group_order as $val ){
    print "<INPUT TYPE='CHECKBOX' NAME='groups[]' VALUE='$val'";
    if( IsSet($kept_groups[$val]) ){
      print " CHECKED";
    }
    print "> " . $groups[$val] . "<BR>\n";
  }
  print "</TD></TR></TABLE>\n";
}

if( IsSet($_REQUEST['Order']) || IsSet($_REQUEST['Update']) ){
  $optionsSet = 1;
} else {
  $optionsSet = 0;
}

?>
<BR><INPUT TYPE="SUBMIT" VALUE="Select Ordering" NAME="Order">
</FORM>
<?php

if( $_SESSION['Me']->isChair && IsSet($_REQUEST['judgement']) ){
  $judgement = $_REQUEST['judgement'];

  foreach( $outcomeName as $oc => $name ){
    $query = "UPDATE Paper SET outcome = '$oc' WHERE paperId < 0 ";
    foreach( $judgement as $paperId => $j ){
      if( $j == $oc ){
	$paperId = addSlashes( $paperId );
        $query .= " OR paperId = '$paperId' ";
      }
    }

      //$Conf->infoMsg($query);
    $result=$Conf->qe( $query );

    if (MDB2::isError($result)) {
      $Conf->errorMsg("Error in sql " . $result->getMessage());
    } 
  }
}

// $Conf->infoMsg("Chair: $isChair<BR>Meeting: $meetingTime");

// Don't show anything until a view is chosen

if( $optionsSet ){

$Conf->infoMsg("This table is sorted by descending " . $stats[$stat] . " of " . $graphs[$target] . " scores.  ");

$Conf->infoMsg("This display only shows information only for finalized reviews.  Also, if you sort on `PC Grade' then only graded papers will show.  If you select standard deviation then two values are required."
	       );


$extracond = "";
$extrapaper = "";

if( $target == 'grade' ){
  $table = 'PaperGrade';
  $qual = "";
  $count = "";
  $countOrd = "";
} else {
  $table = 'PaperReview';
  $qual =  "   AND PaperReview.reviewSubmitted>0 ";
  $count = " , COUNT(PaperReview.reviewSubmitted) AS count ";
  $countOrd = " count DESC, ";
}

if( ! $isChair ){
  if ( $meetingTime ) {
    $pcmode = 'PCHide';
    $Conf->infoMsg("PC member papers will not be shown.");
  } else {
    $pcmode = 'PCAll';
    $me = addSlashes( $_SESSION['Me']->contactId );
    $extrapaper = " , PrimaryReviewer AS prim, PaperReview AS other ";
    $extracond =
      " AND $table.paperId = prim.paperId AND prim.contactId = '$me' " .
      " AND $table.paperId = other.paperId AND other.contactId = '$me' " .
      " AND other.reviewSubmitted ";

    $Conf->infoMsg("As a PC member you can only see papers that you reviewed.");
  }
}

if( $pcmode == 'PCOnly' ){
  $qual .= ' AND Paper.pcPaper = 1 ';
} else if( $pcmode == 'PCHide' ){
  $qual .= ' AND Paper.pcPaper = 0 ';
}

$query  = "SELECT $table.paperId, Paper.title, Paper.pcPaper, "
	. " $stat($table.$target) AS merit "
	. $count . $judgeData . $authorData
	. " FROM $table, Paper $extrapaper $authorTable "
	. " WHERE Paper.paperId = $table.paperId $extracond "
	. $qual . $authorQual
	. " GROUP BY $table.paperId "
	. " ORDER BY merit DESC, $countOrd $table.paperId ";

//$Conf->infoMsg( $query  );

$result=$Conf->qe( $query );


if (MDB2::isError($result)) {
  $Conf->errorMsg("Error in sql " . $result->getMessage());
  exit();
} 

if( $judgeCol ){
  print "<FORM METHOD='POST' ACTION='" . $_SERVER['PHP_SELF'] . "'>\n";
  print "<INPUT TYPE='HIDDEN' NAME='GradeCol' VALUE=1>\n";

  if( $authorRow ){
    print "<INPUT TYPE='HIDDEN' NAME='AuthorRow' VALUE=1>\n";
  }

  if( $redCol ){
    print "<INPUT TYPE='HIDDEN' NAME='RedCol' VALUE=1>\n";
  }

  if( $use_groups ){
    print "<INPUT TYPE='HIDDEN' NAME='use_groups' VALUE=1>\n";
  }

  foreach($kept_groups as $val => $name){
    print "<INPUT TYPE='HIDDEN' NAME='groups[]' VALUE='$val'>\n";
  }

  foreach($kept_graphs as $val => $name){
    print "<INPUT TYPE='HIDDEN' NAME='COLS[]' VALUE='$val'>\n";
  }

  print "<INPUT TYPE='HIDDEN' NAME='target' VALUE='$target'>\n";
  print "<INPUT TYPE='HIDDEN' NAME='stat' VALUE='$stat'>\n";
  print "<INPUT TYPE='HIDDEN' NAME='pcmode' VALUE='$pcmode'>\n";
}

$rf = reviewForm();
foreach( $kept_graphs as $field => $name ){
    $meritMax[$field] = $rf->maxNumericScore($field);
}

$grouped_rows = array();

if( $use_groups ){
  foreach( $kept_groups as $x => $val ){
    $grouped_rows[$x] = array();
  }
} else {
  $group_order = array( 'all' );
  $groups['all'] = 'All Selected Papers';
  $grouped_rows['all'] = array();
  $dest = 'all';
}

while ($row=$result->fetchRow(MDB2_FETCHMODE_ASSOC)) {
  if( $use_groups ){
    $query = "SELECT grade, COUNT(grade) AS count FROM PaperGrade WHERE " .
	     "paperId = " . $row['paperId'] ." GROUP BY grade";
    $grades = array();

    $gresult=$Conf->qe( $query );

    while ($grow=$gresult->fetchRow(MDB2_FETCHMODE_ASSOC)) {
      $grades[$grow['grade']] = $grow['count'];
    }

if( 0 ){
    if( !$grades[1] && !$grades[2] ){
      $dest = "groupABC";
    } else if( !$grades[1] && $grades[5] ){
      $dest = "groupA-E";
    } else if( !$grades[4] && !$grades[5] ){
      $dest = "groupCDE";
    } else {
      $dest = "groupRest";
    }
}

    if( !$grades[1] && !$grades[2] && !$grades[3] ){
      $dest = "groupW";
    } else if( !$grades[4] && !$grades[5] && !$grades[3] ){
      $dest = "groupZ";
    } else if( !$grades[4] && !$grades[5] && $grades[1] ){
      $dest = "groupY";
    } else {
      $dest = "groupX";
    }

    if( $row['paperId'] == 89 ){
      $dest = "groupX";
    }
    // print     "<P>Chose $dest for " . $row['paperId'] ."</P>\n";
  }

  if( IsSet($grouped_rows[$dest]) ){
    $grouped_rows[$dest][] = $row;
  }
}

foreach( $group_order as $group ){
  if( IsSet($grouped_rows[$group]) && count($grouped_rows[$group]) ){
?>

<H1><?php echo $groups[$group]?></H1>
<table border=1 align=center>
<tr bgcolor=<?php echo $Conf->contrastColorOne?>>
<th colspan=<?php echo count($kept_graphs)+2+$judgeCol ?>> Paper Ranking by <?php echo $stats[$stat] . " of " . $graphs[$target] ?></th> </tr>

<tr>
<th> Row#<BR>(Pap#)</th>
<th> Paper </th>
<?php

  if( $judgeCol ){
    print "<TH>Judgement</TH>";
  }

  foreach( $kept_graphs as $field => $name ){
    print "<th>$name</th>";
  }

?>
</tr>
<td> <b> 
<?php 
$rowNum = 0;
foreach( $grouped_rows[$group] as $row ){
  $rowNum++;
  $pcPaper=$row['pcPaper'];

  $redText = "";

  if( $pcPaper && $pcmode == 'PCX' ){
    $paperId= 'X';
  } else {
    $paperId=$row['paperId'];
    $paperTitle=$row['title'];
  }

  if( $pcPaper && $redCol ){
    $redText = " BGCOLOR='Red' ";
  }

  $redText .= $authorSpace;

  print "<tr> <td $redText> $rowNum<BR>($paperId)</td> ";

  if( $paperId == 'X' ){
    print "<TD $redText>X</TD>";
  } else {
    print "<td> <A HREF=\"${ConfSiteBase}review.php?paperId=$paperId\" TARGET=\"_blank\">";
    print htmlentities($paperTitle);
    print "</A></td> \n";
  }

  if( $judgeCol ){
    if( $paperId == 'X' ){
      print "<TD>X</TD>";
    } else {
      $outcome = $row['outcome'];
      print "<TD><SELECT NAME='judgement[$paperId]'>\n";
      foreach( $outcomeName as $val => $name ){
        print "<OPTION VALUE='$val'";
	if ($val == $outcome) {
	  print " SELECTED";
	}
	print ">$name</OPTION>\n";
      }
      print "</SELECT></TD>\n";
    }
  }

  foreach( $kept_graphs as $field => $name ){
    print "<td align=center>";
    if( $paperId == 'X' ){
      print 'X';
    } else {
      if( $field == 'grade' ){
	$q = "SELECT $field FROM PaperGrade WHERE paperID='$paperId'";
      } else {
	$q = "SELECT $field FROM PaperReview WHERE paperID='$paperId' AND reviewSubmitted>0";
      }

      $Conf->graphValues($q, $field, $meritMax[$field]);
    }
    print "</td>";
  }

  print "</tr> \n";

  if( $authorRow ){
    print "<TR><TD COLSPAN='";
    print count($kept_graphs)+1+$judgeCol;
    print "'>" . $Conf->safeHtml($row['authorInformation']) . "<BR><EM>"
	  . ($Conf->safeHtml($row['firstName'] . " " . $row['lastName'] . " (" . $row['email'] . "), " . $row['affiliation']))
	  . "</EM></TD></TR>\n";
  }
}
?>
</table>

<?php
}
}
if( $judgeCol ){
  print "<INPUT TYPE='SUBMIT' VALUE='Update Judgements' NAME='Update'>\n";
  print "</FORM>\n";
}

}
?>

<?php $Conf->footer() ?>

