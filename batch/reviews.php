<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:t:xwacN", ["help", "name:", "type:", "narrow", "wide", "all", "no-header", "comments", "sitename"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/reviews.php [-n CONFID] [-t COLLECTION] [-acx] [QUERY...]
Output a CSV file containing all reviews for the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -x, --narrow           Narrow output.
  -a, --all              Include all reviews, not just submitted reviews.
  -c, --comments         Include comments.
  -N, --sitename         Include site name and class in CSV.
  --no-header            Omit CSV header.
  QUERY...               A search term.\n");
    exit(0);
}
$narrow = isset($arg["x"]) || isset($arg["narrow"]);
$all = isset($arg["a"]) || isset($arg["all"]);
$comments = isset($arg["c"]) || isset($arg["comments"]);
if ($comments && !$narrow) {
    fwrite(STDERR, "batch/reviews.php: ‘-c’ requires ‘--narrow’ format.\n");
    exit(1);
}

require_once("$ConfSitePATH/src/init.php");

$user = $Conf->site_contact();
$t = get($arg, "t", "s");
$searchtypes = PaperSearch::search_types($user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/reviews.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
foreach ($search->warnings as $w) {
    fwrite(STDERR, "$w\n");
}

$csv = new CsvGenerator;
$header = [];
if (isset($arg["N"]) || isset($arg["sitename"])) {
    array_push($header, "sitename", "siteclass");
}
array_push($header, "pid", "review", "email", "round");
if ($all || $comments) {
    $header[] = "status";
}
if ($narrow) {
    $header[] = "field";
    $header[] = "format";
    $header[] = "data";
}
$pset = $Conf->paper_set(["paperId" => $search->paper_ids()]);
$rf = $Conf->review_form();

$output = [];
function add_row($x) {
    global $csv, $header, $arg, $narrow, $output;
    if ($narrow && empty($output)) {
        $csv->select($header, !isset($arg["no-header"]));
        $output[] = true;
    }
    if ($narrow) {
        $csv->add($x);
    } else {
        $output[] = $x;
    }
}

$fields = [];
foreach ($search->sorted_paper_ids() as $pid) {
    $prow = $pset[$pid];
    $prow->ensure_full_reviews();
    $prow->ensure_reviewer_names();
    foreach ($comments ? $prow->viewable_reviews_and_comments($user) : $prow->reviews_by_display($user) as $rrow) {
        $iscomment = isset($rrow->commentId);
        if ($iscomment
            ? !$all && ($rrow->commentType & COMMENTTYPE_DRAFT)
            : $rrow->reviewModified <= 1
              || (!$all && $rrow->reviewSubmitted <= 0)) {
            continue;
        }
        $x = [
            "sitename" => $Conf->opt("confid"),
            "siteclass" => $Conf->opt("siteclass"),
            "pid" => $prow->paperId
        ];
        if ($iscomment) {
            $x["review"] = $rrow->unparse_html_id();
            $x["email"] = $rrow->reviewEmail;
            if ($rrow->commentType & COMMENTTYPE_RESPONSE) {
                $x["round"] = $Conf->resp_round_text($rrow->commentType);
            }
            $rs = $rrow->commentType & COMMENTTYPE_DRAFT ? "draft " : "";
            if ($rrow->commentType & COMMENTTYPE_RESPONSE) {
                $rs .= "response";
            } else if ($rrow->commentType & COMMENTTYPE_BYAUTHOR) {
                $rs .= "author comment";
            } else {
                $rs .= "comment";
            }
            $x["status"] = $rs;
            $x["field"] = "comment";
            $x["format"] = $rrow->commentFormat;
            $x["data"] = $rrow->commentOverflow ? : $rrow->comment;
            add_row($x);
        } else {
            $x["review"] = $rrow->unparse_ordinal();
            $x["email"] = $rrow->email;
            $x["round"] = $Conf->round_name($rrow->reviewRound);
            $x["status"] = $rrow->status_description();
            if ($rrow->reviewFormat === null) {
                $x["format"] = $Conf->default_format;
            } else {
                $x["format"] = $rrow->reviewFormat;
            }
            foreach ($rf->paper_visible_fields($user, $prow, $rrow) as $fid => $f) {
                $fv = $f->unparse_value(get($rrow, $fid), ReviewField::VALUE_TRIM | ReviewField::VALUE_STRING);
                if ($fv === "") {
                    // ignore
                } else if ($narrow) {
                    $x["field"] = $f->name;
                    $x["data"] = $fv;
                    add_row($x);
                } else {
                    $fields[$fid] = true;
                    $x[$f->name] = $fv;
                }
            }
            if (!$narrow) {
                add_row($x);
            }
        }
    }
}

if (!empty($output) && !$narrow) {
    foreach ($rf->all_fields() as $fid => $f) {
        if (isset($fields[$fid])) {
            $header[] = $f->name;
        }
    }
    $csv->select($header, !isset($arg["no-header"]));
    foreach ($output as $orow) {
        $csv->add($orow);
    }
}

@fwrite(STDOUT, $csv->unparse());
