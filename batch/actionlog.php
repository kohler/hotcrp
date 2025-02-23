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
    /** @var int */
    public $page_size;
    /** @var ?list<int> */
    public $uids;
    /** @var resource */
    public $out;
    /** @var bool */
    public $sitename;
    /** @var bool */
    public $header;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->narrow = !isset($arg["wide"]);
        $this->page_size = $arg["pagesize"] ?? 10000;
        $this->sitename = isset($arg["N"]);
        $this->header = !isset($arg["no-header"]) || isset($arg["header"]);
        foreach ($arg["u"] ?? [] as $u) {
            $this->add_user_clause($u);
        }

        if (isset($arg["o"]) && $arg["o"] !== "-") {
            $omode = isset($arg["append"]) ? "ab" : "wb";
            if (!($this->out = @fopen($arg["o"], $omode))) {
                throw new CommandLineException($arg["o"] . ": Cannot create output file");
            }
            if (isset($arg["append"])
                && ($stat = fstat($this->out))
                && $stat["size"] > 0
                && !isset($arg["header"])) {
                $this->header = false;
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
        $leg = new LogEntryGenerator($this->conf, $this->page_size);
        if ($this->uids !== null) {
            $leg->set_user_ids($this->uids);
        }
        if ($this->narrow) {
            $leg->set_consolidate_mail(false);
        }
        $sitename = $this->conf->opt("confid");
        $siteclass = $this->conf->opt("siteclass");

        $csvg = (new CsvGenerator)->set_stream($this->out);
        $sitename = $this->conf->opt("confid");
        $siteclass = $this->conf->opt("siteclass");
        $fields = $this->narrow ? $leg->narrow_csv_fields() : $leg->wide_csv_fields();
        if ($this->sitename) {
            array_unshift($fields, "sitename", "siteclass");
        }
        $csvg->set_keys($fields);
        if ($this->header) {
            $csvg->set_header($fields);
        }

        for ($page = 1; ($rows = $leg->page_rows($page)); ++$page) {
            foreach ($rows as $row) {
                if ($this->narrow && !$this->sitename) {
                    $csvg->append($leg->narrow_csv_data_list($row));
                } else if (!$this->sitename) {
                    $csvg->add_row($leg->wide_csv_data($row));
                } else {
                    if ($this->narrow) {
                        $dlist = $leg->narrow_csv_data_list($row);
                    } else {
                        $dlist = [$leg->wide_csv_data($row)];
                    }
                    foreach ($dlist as $data) {
                        array_unshift($data, $sitename, $siteclass);
                        $csvg->add_row($data);
                    }
                }
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
            "pagesize:,page-size: {n} !",
            "u[],user[] =USER Include entries about USER",
            "N,sitename,siteclass Include siteclass and sitename in CSV",
            "no-header Omit CSV header",
            "header !",
            "wide Generate wide CSV",
            "o:,output: =FILE Write output to FILE",
            "append Append to `--output` file"
        )->description("Output HotCRP action log as CSV.
Usage: php batch/actionlog.php [-n CONFID|--config CONFIG]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new ActionLog_Batch($conf, $arg);
    }
}
