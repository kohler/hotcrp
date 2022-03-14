<?php
// search.php -- HotCRP batch search script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    define("HOTCRP_NOINIT", 1);
    require_once(dirname(__DIR__) . "/src/init.php");
    Search_Batch::make_args($argv)->run();
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
            throw new Error("No search collection ‘{$t}’");
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

    function run() {
        $pl = new PaperList("empty", $this->search);
        $pl->set_view("pid", true);
        foreach ($this->fields as $f) {
            $pl->set_view($f, true);
        }
        list($header, $body) = $pl->text_csv();

        if ($this->search->has_problem()) {
            fwrite(STDERR, $this->search->full_feedback_text());
        }
        if (!empty($body)) {
            $csv = new CsvGenerator;
            $siteid = $this->search->conf->opt("confid");
            $siteclass = $this->search->conf->opt("siteclass");
            if ($this->header ?? count($header) > 1) {
                $header = array_keys($header);
                $this->sitename && array_unshift($header, "sitename", "siteclass");
                $csv->add_row($header);
            }
            foreach ($body as $row) {
                $this->sitename && array_unshift($row, $siteid, $siteclass);
                $csv->add_row($row);
            }
            fwrite(STDOUT, $csv->unparse());
        }
    }

    static function help() {
        fwrite(STDOUT, "Usage: php batch/search.php [-n CONFID] [-t COLLECTION] [-f FIELD]+ [QUERY...]
Output a CSV file containing the FIELDs for the papers matching QUERY.

Options include:
  -t, --type COLLECTION  Search COLLECTION “s” (submitted) or “all” [s].
  -f, --show FIELD       Include FIELD in output.
  -N, --sitename         Include site name and class in CSV.
  --header               Always include CSV header.
  --no-header            Omit CSV header.
  QUERY...               A search term.\n");
    }

    /** @return Search_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "config:,c:",
            "name:,n:",
            "t:,type:",
            "f[],field[],show[]",
            "N,sitename",
            "header",
            "no-header",
            "help,h"
        )->parse($argv);

        if (isset($arg["help"])) {
            self::help();
            exit(0);
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Search_Batch($conf->root_user(), $arg);
    }
}
