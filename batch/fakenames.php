<?php
// fakenames.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(FakeNames_Batch::make_args($argv)->run());
}

class FakeNames_Batch {
    /** @var Conf */
    public $conf;
    /** @var array<string,list<int|string>> */
    private $data = [];
    /** @var array<string,true> */
    private $emails = [];

    function __construct(Conf $conf) {
        $this->conf = $conf;
        if (!$this->load()) {
            throw new RuntimeException("Can't load fake names database");
        }
    }

    /** @param ?string $file
     * @return bool */
    function load($file = null) {
        if ($file === null) {
            $file = SiteLoader::find("extra/fakenames.csv");
        }
        if (($s = file_get_contents($file)) === false) {
            return false;
        }
        $csv = new CsvParser($s);
        while (($x = $csv->next_list())) {
            list($name, $type, $count) = $x;
            if ((string) $type === "")
                continue;
            if (!isset($this->data[$type])) {
                $this->data[$type] = [0];
            }
            $max = $this->data[$type][count($this->data[$type]) - 1];
            $max += (int) ((float) $count * 10 + 0.5);
            array_push($this->data[$type], $name, $max);
        }
        return true;
    }
    function random($type) {
        if (!isset($this->data[$type])) {
            return false;
        }
        $nd = count($this->data[$type]);
        $idx = mt_rand(0, $this->data[$type][$nd - 1] - 1);
        $l = 0;
        $r = ($nd - 1) >> 1;
        while ($l < $r) {
            $m = $l + (($r - $l) >> 1);
            $i = $m << 1;
            if ($idx < $this->data[$type][$i]) {
                $r = $m;
            } else if ($idx > $this->data[$type][$i + 2]) {
                $l = $m + 1;
            } else {
                return $this->data[$type][$i + 1];
            }
        }
        return false;
    }
    function first() {
        return $this->random("f");
    }
    function last() {
        return $this->random("l");
    }
    function affiliation() {
        return $this->random("a");
    }
    function country() {
        $n = mt_rand(0, count(Countries::$list) - 1);
        return Countries::$list[$n];
    }

    function new_fake_email() {
        $l = "a e i o u y a e i o u y a e i o u y a e i o u y a e i o u y b c d g h j k l m n p r s t u v w trcrbrfrthdrchphwrstspswprslcl2 3 4 5 6 7 8 9 _ ";
        $n = strlen($l) >> 1;
        while (true) {
            $bytes = random_bytes(24);
            $n1 = mt_rand(4, 9);
            $n2 = $n1 + mt_rand(4, 9);
            $e = "";
            for ($i = 0; $i < $n1; ++$i) {
                $x = ord($bytes[$i]) % $n;
                $e .= rtrim(substr($l, 2 * $x, 2));
            }
            $e .= "@";
            for (; $i < $n2; ++$i) {
                $x = ord($bytes[$i]) % $n;
                $e .= rtrim(substr($l, 2 * $x, 2));
            }
            $e .= ".edu";
            if (!isset($this->emails[$e])) {
                $this->emails[$e] = true;
                return $e;
            }
        }
    }

    /** @return int */
    function run() {
        // load all contacts
        $result = $this->conf->qe("select contactId, firstName, lastName, unaccentedName, email, affiliation, contactTags, country, password, disabled, primaryContactId from ContactInfo");
        $users = $emails = [];
        while (($c = Contact::fetch($result, $this->conf))) {
            $users[] = $c;
            $emails[strtolower($c->email)] = true;
        }
        Dbl::free($result);

        // update names, emails, affiliations, passwords
        $q = $qv = [];
        $email_map = [];
        foreach ($users as $c) {
            $q[] = "update ContactInfo set firstName=?, lastName=?, unaccentedName=?, email=?, preferredEmail='', affiliation=?, country=?, password=? where contactId={$c->contactId}";
            $qv[] = $f = $this->first();
            $qv[] = $l = $this->last();
            $aff = $this->affiliation();
            $qv[] = strtolower(UnicodeHelper::deaccent("$f $l ($aff)"));

            // find an unused email
            $qv[] = $e = $this->new_fake_email();

            $qv[] = $aff;
            $qv[] = $this->country();
            $qv[] = " nologin";

            $email_map[$c->email] = [$f, $l, $e, $aff];

            // XXX collaborators
        }

        $mresult = Dbl::multi_qe_apply($this->conf->dblink, join("; ", $q), $qv);
        $mresult->free_all();

        // process papers
        $result = $this->conf->qe("select * from Paper");
        $papers = [];
        while (($p = PaperInfo::fetch($result, null, $this->conf))) {
            $papers[] = $p;
        }
        Dbl::free($result);

        $q = $qv = [];
        foreach ($papers as $p) {
            $ax = [];
            foreach ($p->author_list() as $a) {
                if ($a->email && isset($email_map[strtolower($a->email)])) {
                    $aa = $email_map[strtolower($a->email)];
                } else {
                    $aa = [$this->first(), $this->last(), $this->new_fake_email(), $this->affiliation()];
                }
                $ax[] = join("\t", $aa) . "\n";
            }
            $q[] = "update Paper set authorInformation=? where paperId={$p->paperId}";
            $qv[] = join("", $ax);
        }

        $mresult = Dbl::multi_qe_apply($this->conf->dblink, join("; ", $q), $qv);
        $mresult->free_all();

        // process action log
        $result = $this->conf->qe("select * from ActionLog");
        $q = $qv = [];
        while (($x = $result->fetch_object())) {
            $l = $x->action;
            $nl = "";
            while (preg_match('/\A(.*?)([^\s\(\)\<\>@\/]+@[^\s\(\)\]\>\/]+\.[A-Za-z]+)(.*)\z/', $l, $m)) {
                $nl .= $m[1];
                if (isset($email_map[strtolower($m[2])])) {
                    $nl .= $email_map[strtolower($m[2])][2];
                } else {
                    $ne = $this->new_fake_email();
                    $email_map[strtolower($m[2])] = [$this->first(), $this->last(), $ne, $this->affiliation()];
                    $nl .= $ne;
                }
                $l = $m[3];
            }
            $nl .= $l;
            if ($nl !== $l) {
                $q[] = "update ActionLog set action=? where logId={$x->logId}";
                $qv[] = $nl;
            }
        }
        Dbl::free($result);

        $mresult = Dbl::multi_qe_apply($this->conf->dblink, join("; ", $q), $qv);
        $mresult->free_all();

        return 0;
    }

    /** @param list<string> $argv
     * @return FakeNames_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->description("Replace names and affiliations in a HotCRP database with fakes.
Usage: php batch/fakenames.php")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new FakeNames_Batch($conf);
    }
}
