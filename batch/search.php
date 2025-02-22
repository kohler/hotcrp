<?php
// search.php -- HotCRP command-line search script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Search_Batch::make_args($argv)->run());
}

class Search_Batch {
    /** @var Contact */
    public $user;
    /** @var PaperSearch */
    public $search;
    /** @var list<string> */
    public $fields;
    /** @var ?bool */
    public $header;
    /** @var bool */
    public $sitename;

    function __construct(Contact $user, $arg) {
        $t = $arg["t"] ?? "s";
        if (!in_array($t, PaperSearch::viewable_limits($user, $t))) {
            throw new CommandLineException("No search collection ‘{$t}’");
        }

        $this->user = $user;
        $this->search = new PaperSearch($user, ["q" => join(" ", $arg["_"]), "t" => $t]);
        $this->fields = $arg["f"] ?? [];
        $this->sitename = isset($arg["N"]);
        if (isset($arg["no-header"])) {
            $this->header = false;
        } else if (isset($arg["header"]) || $this->sitename) {
            $this->header = true;
        }
    }

    /** @return int */
    function run() {
        $pl = new PaperList("empty", $this->search);
        $pl->set_view("pid", true, PaperList::VIEWORIGIN_MAX);
        foreach ($this->fields as $f) {
            $pl->set_view($f, true, PaperList::VIEWORIGIN_MAX);
        }
        list($header, $body) = $pl->text_csv();

        if ($this->search->has_problem()) {
            fwrite(STDERR, $this->search->full_feedback_text());
        }
        if (empty($body)) {
            return 0;
        }
        $csv = new CsvGenerator;
        $siteid = $this->search->conf->opt("confid");
        $siteclass = $this->search->conf->opt("siteclass");
        if ($this->header ?? (count($header) > 1)) {
            $header = array_keys($header);
            $this->sitename && array_unshift($header, "sitename", "siteclass");
            $csv->add_row($header);
        }
        foreach ($body as $row) {
            $this->sitename && array_unshift($row, $siteid, $siteclass);
            $csv->add_row($row);
        }
        fwrite(STDOUT, $csv->unparse());
        return 0;
    }

    /** @return Search_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "t:,type: =COLLECTION Search “s” (submitted) or “all” [default s]",
            "f[],show[],field[] =FIELD Include FIELD in output",
            "N,sitename Include site name and class in output",
            "header Always include CSV header",
            "no-header Omit CSV header",
            "help,h !"
        )->description("Output CSV of the papers matching HotCRP search QUERY.
Usage: php batch/search.php [-n CONFID] [-t COLLECTION] [-f FIELD]+ QUERY...")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Search_Batch($conf->root_user(), $arg);
    }
}
