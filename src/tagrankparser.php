<?php
// tagrankparser.php -- HotCRP offline rank parsing
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class TagRankParser {
    /** @var Contact */
    public $user;
    /** @var ?string */
    public $tag;

    /** @param Contact $user */
    function __construct(Contact $user) {
        $this->user = $user;
    }

    function set_tag($tag) {
        $this->tag = $tag;
    }

    function parse($text, $filename = null) {
        if ($text instanceof CsvParser) {
            $csv = $text;
        } else {
            $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
            $csv->set_comment_chars("%#");
            $csv->set_filename($filename);
        }
        $csva = [["paper", "action", "tag", "landmark", "message"]];

        if (!$csv->header()) {
            if (!($req = $csv->next_list())) {
                $csva[] = ["", "error", "", $csv->filename(), "Empty file."];
                return $csva;
            }
            if (!preg_grep('/\A(?:paper|pid|tag|index|action)\z/', $req)) {
                $csv->unshift($req);
                $req = ["action", "paper"];
            }
            $csv->set_header($req);
        }
        $csv->add_synonym("paper", "pid");
        $csv->add_synonym("paper", "paperid");
        $csv->add_synonym("paper", "id");
        $csv->add_synonym("action", "tag");
        $csv->add_synonym("action", "index");

        $settings = $pids = [];
        $tagger = new Tagger($this->user);
        $tag = $this->tag;
        $curIndex = 0;
        while (($row = $csv->next_row())) {
            if (empty($row) || !isset($row["paper"]) || !isset($row["action"])) {
                continue;
            }

            $landmark = $filename !== false ? $csv->landmark() : "";
            $idxs = trim($row["action"]);
            $pid = trim($row["paper"]);
            if ($idxs === "tag") {
                if (($t = $tagger->check($pid, Tagger::NOVALUE))) {
                    $tag = $t;
                    $curIndex = 0;
                } else {
                    $settings[] = [null, null, $landmark, "Bad tag: " . Ftext::as(0, $tagger->error_ftext()), null];
                }
            } else if ($pid === "tag") {
                if (($t = $tagger->check($idxs, Tagger::NOVALUE))) {
                    $tag = $t;
                    $curIndex = 0;
                } else {
                    $settings[] = [null, null, $landmark, "Bad tag: " . Ftext::as(0, $tagger->error_ftext()), null];
                }
            } else {
                if ($idxs === "X" || $idxs === "x" || $idxs === "clear") {
                    $idx = "clear";
                } else if ($idxs === "" || $idxs === ">") {
                    ++$curIndex;
                    $idx = $curIndex;
                } else if ($idxs === "=") {
                    $idx = $curIndex;
                } else if (strspn($idxs, ">") === strlen($idxs)) {
                    $curIndex += strlen($idxs);
                    $idx = $curIndex;
                } else if (preg_match('/\A[-+]?(?:\d+\.?\d*|\.\d*)\z/', $idxs)) {
                    if (strpos($idxs, ".") === false) {
                        $curIndex = $idx = intval($idxs);
                    } else {
                        $curIndex = $idx = floatval($idxs);
                    }
                } else {
                    $idx = false;
                }
                if ($idx === false || !ctype_digit($pid)) {
                    $settings[] = [null, null, $landmark, "I didn’t understand this line.", null];
                } else if ($tag === "") {
                    $settings[] = [null, null, $landmark, "Tag missing.", null];
                } else {
                    $settings[] = [$pid, "{$tag}#{$idx}", $landmark, null, $row["title"]];
                    $pids[(int) $pid] = true;
                }
            }
        }

        $pset = $this->user->paper_set(["paperId" => array_keys($pids), "minimal" => true, "title" => true]);

        $landmarks = [];
        foreach ($settings as $a) {
            list($pid, $idx, $landmark, $error, $title) = $a;
            if ($pid === null || !ctype_digit($pid)) {
                $csva[] = ["", "error", "", $landmark, $error];
            } else {
                if (($prow = $pset->get(intval($pid)))
                    && $title !== null
                    && $title !== ""
                    && $title !== $prow->title
                    && strcasecmp(simplify_whitespace($title), $prow->title) !== 0
                    && $this->user->can_view_paper($prow)) {
                    $csva[] = [$pid, "warning", "", $landmark, "Warning: Title “{$title}” doesn’t match #{$pid}’s current title."];
                }
                if (isset($landmarks[$pid])) {
                    $csva[] = [$pid, "warning", "", $landmark, "Warning: Resetting tag for #{$pid}."];
                    if ($landmarks[$pid] !== "") {
                        $csva[] = [$pid, "warning", "", $landmarks[$pid], "Previous tag was set here."];
                    }
                }
                $csva[] = [$pid, "tag", $idx, $landmark];
                $landmarks[$pid] = $landmark;
            }
        }

        return $csva;
    }

    /** @return AssignmentSet */
    function parse_assignment_set($text, $filename = null) {
        $aset = new AssignmentSet($this->user);
        $csv = new CsvParser($this->parse($text, $filename));
        $aset->parse($csv);
        return $aset;
    }
}
