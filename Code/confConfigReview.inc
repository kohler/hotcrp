<?php 

//
// This file provides the "factories" (functions that produce
// instances) that produce specific instances of the review datatype.
//
// Things are organized this way because you may use a different
// subclass of the review class and don't want to change zillions of
// files.
//

include('../Code/ClassHYPO2010Review.inc');

//
// Function to create the appropriate review form for this conference
//
function& ReviewFactory($conf, $contactId, $paperId)
{
  return new HYPO2010Review($conf, $contactId, $paperId);
}

//
// This is effectively a wrapper for the above
//
function& ReviewFactoryById($conf, $reviewId)
{
  $query="SELECT contactId, paperId FROM PaperReview "
    . " WHERE paperReviewid='$reviewId'";
  $result = $conf->q($query);
  if ( DB::isError($result) ) {
    $row=$result->fetchRow(DB_FETCHMODE_ASSOC);
    return new HYPO2010($conf, $row['contactId'], $row['paperId']);
  } else {
    return 0;
  }
}

?>
