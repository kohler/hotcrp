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

function review_id($r) {
    $id = $r->reviewId;
    if ($r->reviewOrdinal) {
        $id .= unparseReviewOrdinal($r->reviewOrdinal);
    }
    return $id;
}

function review_id_displayed($r) {
    $id = review_id($r);
    if ($r->timeDisplayed) {
        $id .= "@" . $r->timeDisplayed;
    }
    return $id;
}

function review_compare_by_ordinal($a, $b) {
    if ($a->reviewOrdinal && $b->reviewOrdinal) {
        return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
    } else if ($a->reviewSubmitted != $b->reviewSubmitted) {
        if ($a->reviewSubmitted != 0 && $b->reviewSubmitted != 0) {
            return $a->reviewSubmitted < $b->reviewSubmitted ? -1 : 1;
        } else {
            return $a->reviewSubmitted != 0 ? -1 : 1;
        }
    } else {
        return $a->reviewId < $b->reviewId ? -1 : 1;
    }
}

function review_compare_by_time_displayed($a, $b) {
    if ($a->timeDisplayed != $b->timeDisplayed) {
        return $a->timeDisplayed < $b->timeDisplayed ? -1 : 1;
    } else if ($a->reviewOrdinal && $b->reviewOrdinal) {
        return $a->reviewOrdinal < $b->reviewOrdinal ? -1 : 1;
    } else if ($a->reviewSubmitted != $b->reviewSubmitted) {
        if ($a->reviewSubmitted != 0 && $b->reviewSubmitted != 0) {
            return $a->reviewSubmitted < $b->reviewSubmitted ? -1 : 1;
        } else {
            return $a->reviewSubmitted != 0 ? -1 : 1;
        }
    } else {
        return $a->reviewId < $b->reviewId ? -1 : 1;
    }
}

function set_review_time_displayed($prow, &$rrows) {
    usort($rrows, "review_compare_by_ordinal");
    $rt = array_map(function ($r) {
        return +$r->timeDisplayed ? : +$r->reviewSubmitted ? : +$r->reviewModified;
    }, $rrows);
    $last = 0;
    for ($i = 0; $i < count($rrows); ++$i) {
        $rrow = $rrows[$i];
        if (!$rrow->timeDisplayed) {
            $t = max($rt[$i], $last);
            for ($j = $i + 1; $j < count($rrows); ++$j) {
                $t = min($t, $rt[$j]);
            }
            $rrow->timeDisplayed = $t;
        }
        $last = $rrow->timeDisplayed;
    }
}

$user = $Conf->site_contact();
foreach ($Conf->paper_set(["paperId" => $pids], $user) as $prow) {
    $rrows = array_values(array_filter($prow->reviews_by_display($user), function ($rrow) { return $rrow->reviewSubmitted || $rrow->reviewOrdinal; }));
    $ids0 = join(",", array_map("review_id", $rrows));
    for ($i = 0; $i < count($rrows) - 1; ++$i) {
        for ($j = $i + 1; $j < count($rrows); ++$j) {
            assert(!$rrows[$j]->timeDisplayed || $rrows[$i]->timeDisplayed <= $rrows[$j]->timeDisplayed);
        }
    }

    set_review_time_displayed($prow, $rrows);
    usort($rrows, "PaperInfo::review_or_comment_compare");
    $ids1 = join(",", array_map("review_id", $rrows));
    if ($ids0 !== $ids1) {
        fwrite(STDERR, "#{$prow->paperId}: $ids0 != $ids1 (" . join(",", array_map("review_id_displayed", $rrows)) . ")\n");
    }
}
