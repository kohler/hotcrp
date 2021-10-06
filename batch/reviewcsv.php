<?php
// reviewcsv.php -- HotCRP review export script
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:t:xwarcfN", ["help", "name:", "type:", "narrow", "wide", "all", "no-header", "reviews", "comments", "fields", "sitename", "no-text", "no-score", "format:"]);
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/reviewcsv.php [-n CONFID] [-t COLLECTION] [-acx] [QUERY...]
Output a CSV file containing all reviews for the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -x, --narrow           Narrow output.
  -a, --all              Include all reviews, not just submitted reviews.
  -r, --reviews          Include reviews (default unless -c or -f).
  -c, --comments         Include comments.
  -f, --fields           Include paper fields.
  -N, --sitename         Include site name and class in CSV.
  --no-text              Omit text fields.
  --no-score             Omit score fields.
  --no-header            Omit CSV header.
  --format=FMT           Only output text fields with format FMT.
  QUERY...               A search term.\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

class FieldCSVOutput {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var FieldRender */
    public $fr;
    public $wide = false;
    public $narrow = false;
    public $fields = false;
    public $reviews = false;
    public $comments = false;
    public $all_status = false;
    public $no_header = false;
    public $no_score = false;
    public $no_text = false;
    /** @var ?int */
    public $format;
    /** @var associative-array<string,true> */
    public $rfseen = [];
    /** @var list<string> */
    public $header;
    /** @var list<array> */
    public $output = [];
    /** @var CsvGenerator */
    public $csv;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->fr = new FieldRender(FieldRender::CFHTML, $this->user);
        $this->csv = new CsvGenerator;
    }

    function parse_arg($arg) {
        if (isset($arg["w"]) || isset($arg["wide"])) {
            $this->wide = true;
        }
        if (isset($arg["x"]) || isset($arg["narrow"])) {
            $this->narrow = true;
        }
        if (isset($arg["f"]) || isset($arg["fields"])) {
            $this->fields = true;
        }
        if (isset($arg["r"]) || isset($arg["reviews"])) {
            $this->reviews = true;
        }
        if (isset($arg["c"]) || isset($arg["comments"])) {
            $this->comments = true;
        }
        if (isset($arg["a"]) || isset($arg["all"])) {
            $this->all_status = true;
        }
        if (isset($arg["no-header"])) {
            $this->no_header = true;
        }
        if (isset($arg["no-score"])) {
            $this->no_score = true;
        }
        if (isset($arg["no-text"])) {
            $this->no_text = true;
        }
        if (isset($arg["format"])) {
            if (ctype_digit($arg["format"])) {
                $this->format = intval($arg["format"]);
            } else {
                fwrite(STDERR, "batch/reviewcsv.php: ‘--format’ should be an integer.\n");
                exit(1);
            }
        }
    }

    function prepare($arg) {
        if (!$this->fields && !$this->reviews && !$this->comments) {
            $this->reviews = true;
        }
        if ($this->wide && $this->narrow) {
            fwrite(STDERR, "batch/reviewcsv.php: ‘--wide’ and ‘--narrow’ contradict.\n");
            exit(1);
        } else if (!$this->wide && !$this->narrow) {
            $this->wide = !$this->fields && !$this->comments && $this->format === null;
            $this->narrow = !$this->wide;
        }
        if ($this->no_text && ($this->fields || $this->comments)) {
            fwrite(STDERR, "batch/reviewcsv.php: These options prohibit ‘--no-text’.\n");
            exit(1);
        }
        if (!$this->narrow && ($this->fields || $this->comments || $this->format !== null)) {
            fwrite(STDERR, "batch/reviewcsv.php: These options require ‘-x/--narrow’.\n");
            exit(1);
        }

        $this->header = [];
        if (isset($arg["N"]) || isset($arg["sitename"])) {
            $this->header[] = "sitename";
            $this->header[] = "siteclass";
        }
        array_push($this->header, "pid", "review", "email", "round", "submitted_at");
        if ($this->all_status || $this->comments) {
            $this->header[] = "status";
        }
        if ($this->narrow) {
            $this->header[] = "field";
            $this->header[] = "format";
            $this->header[] = "data";
        }
    }

    function add_row($x) {
        if ($this->format !== null
            && isset($x["format"])
            && $x["format"] !== $this->format) {
            return;
        }
        if ($this->narrow) {
            if (empty($this->output)) {
                $this->csv->select($this->header, !$this->no_header);
                $this->output[] = [];
            }
            $this->csv->add_row($x);
        } else {
            $this->output[] = $x;
        }
    }

    /** @param PaperInfo $prow */
    function add_fields($prow, $x) {
        $x["review"] = "";
        $x["email"] = "";
        $x["round"] = "";
        $x["submitted_at"] = $prow->timeSubmitted > 0 ? $prow->timeSubmitted : "";
        if ($prow->timeSubmitted > 0) {
            $rs = "submitted";
        } else if ($prow->timeWithdrawn > 0) {
            $rs = "withdrawn";
        } else {
            $rs = "draft";
        }
        $x["status"] = $rs;
        foreach ($prow->page_fields() as $o) {
            if (($o->type === "title"
                 || $o->type === "abstract"
                 || $o->type === "text")
                && $o->can_render($this->fr->context)
                && ($v = $prow->option($o))) {
                $o->render($this->fr, $v);
                $x["field"] = $o->title();
                $x["format"] = $this->fr->value_format;
                $x["data"] = $this->fr->value;
                $this->add_row($x);
            }
        }
    }

    /** @param PaperInfo $prow
     * @param CommentInfo $crow */
    function add_comment($prow, $crow, $x) {
        $x["review"] = $crow->unparse_html_id();
        $x["email"] = $crow->email;
        if ($crow->commentType & CommentInfo::CT_RESPONSE) {
            $x["round"] = $prow->conf->resp_round_text($crow->commentRound);
        }
        $rs = $crow->commentType & CommentInfo::CT_DRAFT ? "draft " : "";
        if ($crow->commentType & CommentInfo::CT_RESPONSE) {
            $rs .= "response";
        } else if ($crow->commentType & CommentInfo::CT_BYAUTHOR) {
            $rs .= "author comment";
        } else {
            $rs .= "comment";
        }
        $x["submitted_at"] = $crow->timeDisplayed ? : ($crow->timeNotified ? : $crow->timeModified);
        $x["status"] = $rs;
        $x["field"] = "comment";
        $x["format"] = $crow->commentFormat ?? $prow->conf->default_format;
        $x["data"] = $crow->commentOverflow ? : $crow->comment;
        $this->add_row($x);
    }

    /** @param PaperInfo $prow
     * @param ReviewInfo $rrow */
    function add_review($prow, $rrow, $x) {
        $x["review"] = $rrow->unparse_ordinal_id();
        $x["email"] = $rrow->email;
        $x["round"] = $prow->conf->round_name($rrow->reviewRound);
        $x["submitted_at"] = $rrow->reviewSubmitted;
        $x["status"] = $rrow->status_description();
        $x["format"] = $rrow->reviewFormat ?? $prow->conf->default_format;
        foreach ($rrow->viewable_fields($this->user) as $fid => $f) {
            if ($f->has_options ? $this->no_score : $this->no_text) {
                continue;
            }
            $fv = $f->unparse_value($rrow->$fid ?? null, ReviewField::VALUE_TRIM | ReviewField::VALUE_STRING);
            if ($fv === "") {
                // ignore
            } else if ($this->narrow) {
                $x["field"] = $f->name;
                $x["data"] = $fv;
                $this->add_row($x);
            } else {
                $this->rfseen[$fid] = true;
                $x[$f->name] = $fv;
            }
        }
        if (!$this->narrow) {
            $this->add_row($x);
        }
    }

    function output($stream) {
        if (!empty($this->output) && !$this->narrow) {
            foreach ($this->conf->all_review_fields() as $fid => $f) {
                if (isset($this->rfseen[$fid])) {
                    $this->header[] = $f->name;
                }
            }
            $this->csv->select($this->header, !$this->no_header);
            foreach ($this->output as $orow) {
                $this->csv->add_row($orow);
            }
        }

        @fwrite($stream, $this->csv->unparse());
    }
}

$fcsv = new FieldCSVOutput($Conf);
$fcsv->parse_arg($arg);
$fcsv->prepare($arg);

$t = $arg["t"] ?? "s";
$searchtypes = PaperSearch::viewable_limits($fcsv->user, $t);
if (!isset($searchtypes[$t])) {
    fwrite(STDERR, "batch/reviewcsv.php: No search collection ‘{$t}’.\n");
    exit(1);
}

$search = new PaperSearch($fcsv->user, ["q" => join(" ", $arg["_"]), "t" => $t]);
foreach ($search->problem_texts() as $w) {
    fwrite(STDERR, "$w\n");
}

$pset = $Conf->paper_set(["paperId" => $search->paper_ids()]);
foreach ($search->sorted_paper_ids() as $pid) {
    $prow = $pset[$pid];
    $prow->ensure_full_reviews();
    $prow->ensure_reviewer_names();
    $px = [
        "sitename" => $Conf->opt("confid"),
        "siteclass" => $Conf->opt("siteclass"),
        "pid" => $prow->paperId
    ];
    if ($fcsv->fields) {
        $fcsv->add_fields($prow, $px);
    }
    foreach ($fcsv->comments ? $prow->viewable_reviews_and_comments($fcsv->user) : $prow->reviews_by_display() as $xrow) {
        if ($xrow instanceof CommentInfo) {
            if ($fcsv->comments
                && ($fcsv->all_status || !($xrow->commentType & CommentInfo::CT_DRAFT))) {
                $fcsv->add_comment($prow, $xrow, $px);
            }
        } else if ($xrow instanceof ReviewInfo) {
            if ($fcsv->reviews
                && $xrow->reviewStatus >= ReviewInfo::RS_DRAFTED
                && ($fcsv->all_status || $xrow->reviewStatus >= ReviewInfo::RS_COMPLETED)) {
                $fcsv->add_review($prow, $xrow, $px);
            }
        }
    }
}

$fcsv->output(STDOUT);
