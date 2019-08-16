<?php

$arg = getopt("hn:d", ["help", "name:", "dry-run"]);
foreach (["d" => "dry-run"] as $s => $l) {
    if (isset($arg[$s]) && !isset($arg[$l]))
        $arg[$l] = $arg[$s];
}

$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$pids = Dbl::fetch_first_columns("select distinct paperId from PaperReview where reviewSubmitted is not null and timeDisplayed=0");
if (empty($pids)) {
    exit;
}

$user = $Conf->site_contact();
foreach ($Conf->paper_set(["paperId" => $pids], $user) as $prow) {
    $rrows = array_values($prow->reviews_by_display($user));
    $ids0 = join(",", array_map(function ($rrow) { return $rrow->reviewId; }, $rrows));

    foreach ($rrows as $rrow) {
        if ($rrow->reviewSubmitted && $rrow->timeDisplayed == 0) {
            $rrow->timeDisplayed = $rrow->reviewSubmitted;
        }
    }
    usort($rrows, "PaperInfo::review_or_comment_compare");
    $ids1 = join(",", array_map(function ($rrow) { return $rrow->reviewId; }, $rrows));
    if ($ids0 !== $ids1) {
        fwrite(STDERR, "#{$prow->paperId}: $ids0 != $ids1\n");
    }
}
