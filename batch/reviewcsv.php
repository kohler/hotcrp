<?php
require_once(preg_replace('/\/batch\/[^\/]+/', '/src/siteloader.php', __FILE__));

$arg = Getopt::rest($argv, "hn:t:xwacN", ["help", "name:", "type:", "narrow", "wide", "all", "no-header", "comments", "sitename"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/reviewcsv.php [-n CONFID] [-t COLLECTION] [-acx] [QUERY...]
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
    fwrite(STDERR, "batch/reviewcsv.php: ‘-c’ requires ‘--narrow’ format.\n");
    exit(1);
} else if ($narrow && (isset($arg["w"]) || isset($arg["wide"]))) {
    fwrite(STDERR, "batch/reviewcsv.php: ‘--wide’ and ‘--narrow’ contradict.\n");
    exit(1);
}

require_once(SiteLoader::find("src/init.php"));

$user = $Conf->root_user();
$t = $arg["t"] ?? "s";
$searchtypes = PaperSearch::search_types($user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/reviewcsv.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
foreach ($search->problem_texts() as $w) {
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
        $csv->add_row($x);
    } else {
        $output[] = $x;
    }
}

$fields = [];
foreach ($search->sorted_paper_ids() as $pid) {
    $prow = $pset[$pid];
    $prow->ensure_full_reviews();
    $prow->ensure_reviewer_names();
    foreach ($comments ? $prow->viewable_reviews_and_comments($user) : $prow->reviews_by_display($user) as $xrow) {
        $crow = $xrow instanceof CommentInfo ? $xrow : null;
        $rrow = $xrow instanceof ReviewInfo ? $xrow : null;
        if (($crow && !$all && ($crow->commentType & COMMENTTYPE_DRAFT))
            || ($rrow && ($rrow->reviewStatus < ReviewInfo::RS_DRAFTED
                          || (!$all && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED)))) {
            continue;
        }
        $x = [
            "sitename" => $Conf->opt("confid"),
            "siteclass" => $Conf->opt("siteclass"),
            "pid" => $prow->paperId
        ];
        if ($crow) {
            $x["review"] = $crow->unparse_html_id();
            $x["email"] = $crow->email;
            if ($crow->commentType & COMMENTTYPE_RESPONSE) {
                $x["round"] = $Conf->resp_round_text($crow->commentRound);
            }
            $rs = $crow->commentType & COMMENTTYPE_DRAFT ? "draft " : "";
            if ($crow->commentType & COMMENTTYPE_RESPONSE) {
                $rs .= "response";
            } else if ($crow->commentType & COMMENTTYPE_BYAUTHOR) {
                $rs .= "author comment";
            } else {
                $rs .= "comment";
            }
            $x["status"] = $rs;
            $x["field"] = "comment";
            $x["format"] = $crow->commentFormat;
            $x["data"] = $crow->commentOverflow ? : $crow->comment;
            add_row($x);
        } else if ($rrow) {
            $x["review"] = $rrow->unparse_ordinal();
            $x["email"] = $rrow->email;
            $x["round"] = $Conf->round_name($rrow->reviewRound);
            $x["status"] = $rrow->status_description();
            if ($rrow->reviewFormat === null) {
                $x["format"] = $Conf->default_format;
            } else {
                $x["format"] = $rrow->reviewFormat;
            }
            foreach ($prow->viewable_review_fields($rrow, $user) as $fid => $f) {
                $fv = $f->unparse_value($rrow->$fid ?? null, ReviewField::VALUE_TRIM | ReviewField::VALUE_STRING);
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
        $csv->add_row($orow);
    }
}

@fwrite(STDOUT, $csv->unparse());
