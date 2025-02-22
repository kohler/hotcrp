<?php
// actionlog.php -- HotCRP maintenance script
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ActionLog_Batch::make_args($argv)->run());
}

class ActionLog_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $narrow = true;
    /** @var ?list<int> */
    public $uids;
    /** @var resource */
    public $out;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->narrow = !isset($arg["wide"]);
        foreach ($arg["u"] ?? [] as $u) {
            $this->add_user_clause($u);
        }
        if (isset($arg["o"]) && $arg["o"] !== "-") {
            if (!($this->out = @fopen($arg["o"], "wb"))) {
                throw new CommandLineException($arg["o"] . ": Cannot create output file");
            }
        } else {
            $this->out = STDOUT;
        }
    }

    private function add_user_clause($query) {
        if (trim($query) === "") {
            return;
        }
        $this->uids = $this->uids ?? [];
        $accts = new SearchParser($query);
        $any = false;
        while (($word = $accts->shift_balanced_parens()) !== "") {
            $flags = ContactSearch::F_TAG | ContactSearch::F_USER | ContactSearch::F_ALLOW_DELETED;
            if (substr($word, 0, 1) === "\"") {
                $flags |= ContactSearch::F_QUOTED;
                $word = preg_replace('/(?:\A"|"\z)/', "", $word);
            }
            $search = new ContactSearch($flags, $word, $this->conf->root_user());
            foreach ($search->user_ids() as $id) {
                $this->uids[] = $id;
                $any = true;
            }
        }
        if (!$any) {
            fwrite(STDERR, "No users match ‘{$query}’");
        }
        $this->uids = array_values(array_unique($this->uids));
    }

    /** @return int */
    function run() {
        $leg = new LogEntryGenerator($this->conf, 10000);
        if ($this->uids !== null) {
            $leg->set_user_ids($this->uids);
        }
        if ($this->narrow) {
            $leg->set_consolidate_mail(false);
        }

        $csvg = (new CsvGenerator)->set_stream($this->out);
        $csvg->select($this->narrow ? $leg->csv_narrow_header() : $leg->csv_wide_header());

        $f = $this->narrow ? "add_csv_narrow" : "add_csv_wide";
        for ($page = 1; ($rows = $leg->page_rows($page)); ++$page) {
            foreach ($rows as $row) {
                $leg->$f($row, $csvg);
            }
        }

        $csvg->flush();
        return 0;
    }

    /** @param list<string> $argv
     * @return ActionLog_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "narrow !",
            "u[],user[] =USER Include entries about USER",
            "wide Generate wide CSV",
            "o:,output: =FILE Write output to FILE"
        )->description("Output HotCRP action log as CSV.
Usage: php batch/actionlog.php [-n CONFID|--config CONFIG]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new ActionLog_Batch($conf, $arg);
    }
}
