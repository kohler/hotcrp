<?php
// papersearch.php -- HotCRP helper class for searching for users
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class ContactSearch {
    const F_SQL = 1;
    const F_TAG = 2;
    const F_PC = 4;
    const F_QUOTED = 8;
    const F_NOUSER = 16;

    public $conf;
    public $type;
    public $text;
    public $user;
    private $cset = null;
    public $ids = false;
    private $only_pc = false;
    private $contacts = false;
    public $warn_html = false;

    public function __construct($type, $text, Contact $user, $cset = null) {
        $this->conf = $user->conf;
        $this->type = $type;
        $this->text = $text;
        $this->user = $user;
        $this->cset = $cset;
        if ($this->type & self::F_SQL) {
            $result = $this->conf->qe("select contactId from ContactInfo where $text");
            $this->ids = Dbl::fetch_first_columns($result);
        }
        if ($this->ids === false && (!($this->type & self::F_QUOTED) || $this->text === ""))
            $this->ids = $this->check_simple();
        if ($this->ids === false && !($this->type & self::F_QUOTED) && ($this->type & self::F_TAG))
            $this->ids = $this->check_pc_tag();
        if ($this->ids === false && !($this->type & self::F_NOUSER))
            $this->ids = $this->check_user();
    }
    static function make_pc($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG, $text, $user);
    }
    static function make_special($text, Contact $user) {
        return new ContactSearch(self::F_PC | self::F_TAG | self::F_NOUSER, $text, $user);
    }
    static function make_cset($text, Contact $user, $cset) {
        return new ContactSearch(0, $text, $user, $cset);
    }
    private function check_simple() {
        if ($this->text === ""
            || strcasecmp($this->text, "pc") == 0
            || (strcasecmp($this->text, "any") == 0 && ($this->type & self::F_PC)))
            return array_keys($this->conf->pc_members());
        else if (strcasecmp($this->text, "me") == 0
                 && (!($this->type & self::F_PC) || ($this->user->roles & Contact::ROLE_PC)))
            return array($this->user->contactId);
        else
            return false;
    }
    private function check_pc_tag() {
        $need = $neg = false;
        $x = strtolower($this->text);
        if (substr($x, 0, 1) === "-") {
            $need = $neg = true;
            $x = substr($x, 1);
        }
        if (substr($x, 0, 1) === "#") {
            $need = true;
            $x = substr($x, 1);
        }

        if ($this->conf->pc_tag_exists($x)) {
            $a = array();
            foreach ($this->conf->pc_members() as $cid => $pc)
                if ($pc->has_tag($x))
                    $a[] = $cid;
            if ($neg && ($this->type & self::F_PC))
                return array_diff(array_keys($this->conf->pc_members()), $a);
            else if (!$neg)
                return $a;
            else {
                $result = $this->conf->qe("select contactId from ContactInfo where contactId ?A", $a);
                return Dbl::fetch_first_columns($result);
            }
        } else if ($need) {
            $this->warn_html = "No such PC tag “" . htmlspecialchars($this->text) . "”.";
            return array();
        } else
            return false;
    }
    private function check_user() {
        if (strcasecmp($this->text, "anonymous") == 0
            && !$this->cset
            && !($this->type & self::F_PC)) {
            $result = $this->conf->qe_raw("select contactId from ContactInfo where email regexp '^anonymous[0-9]*\$'");
            return Dbl::fetch_first_columns($result);
        }

        // split name components
        list($f, $l, $e) = Text::split_name($this->text, true);
        if ($f === "" && $l === "" && strpos($e, "@") === false)
            $n = $e;
        else
            $n = trim($f . " " . $l);

        // generalize email
        $estar = $e && strpos($e, "*") !== false;
        if ($e && !$estar) {
            if (preg_match('/\A(.*)@(.*?)((?:[.](?:com|net|edu|org|us|uk|fr|be|jp|cn))?)\z/', $e, $m))
                $e = ($m[1] === "" ? "*" : $m[1]) . "@*" . $m[2] . ($m[3] ? : "*");
            else
                $e = "*$e*";
        }

        // contact database if not restricted to PC or cset
        $result = null;
        if ($this->cset)
            $cs = $this->cset;
        else if ($this->type & self::F_PC)
            $cs = $this->conf->pc_members();
        else {
            $q = array();
            if ($n !== "") {
                $x = sqlq_for_like(UnicodeHelper::deaccent($n));
                $q[] = "unaccentedName like '%" . preg_replace('/[\s*]+/', "%", $x) . "%'";
            }
            if ($e !== "") {
                $x = sqlq_for_like($e);
                $q[] = "email like '" . preg_replace('/[\s*]+/', "%", $x) . "'";
            }
            $result = $this->conf->qe_raw("select firstName, lastName, unaccentedName, email, contactId, roles from ContactInfo where " . join(" or ", $q));
            $cs = array();
            while ($result && ($row = Contact::fetch($result)))
                $cs[$row->contactId] = $row;
        }

        // filter results
        $nreg = $ereg = null;
        if ($n !== "")
            $nreg = Text::star_text_pregexes($n);
        if ($e !== "" && $estar)
            $ereg = '{\A' . str_replace('\*', '.*', preg_quote($e)) . '\z}i';
        else if ($e !== "") {
            $ereg = str_replace('@\*', '@(?:|.*[.])', preg_quote($e));
            $ereg = preg_replace('/\A\\\\\*/', '(?:.*[@.]|)', $ereg);
            $ereg = '{\A' . preg_replace('/\\\\\*$/', '(?:[@.].*|)', $ereg) . '\z}i';
        }

        $ids = array();
        foreach ($cs as $id => $acct)
            if ($ereg && preg_match($ereg, $acct->email)) {
                // exact email match trumps all else
                if (strcasecmp($e, $acct->email) == 0) {
                    $ids = array($id);
                    break;
                }
                $ids[] = $id;
            } else if ($nreg) {
                $n = $acct->firstName === "" || $acct->lastName === "" ? "" : " ";
                $n = $acct->firstName . $n . $acct->lastName;
                if (Text::match_pregexes($nreg, $n, $acct->unaccentedName))
                    $ids[] = $id;
            }

        Dbl::free($result);
        return $ids;
    }
    public function contacts() {
        global $Me;
        if ($this->contacts === false) {
            $this->contacts = array();
            $pcm = $this->conf->pc_members();
            foreach ($this->ids as $cid)
                if ($this->cset && ($p = get($this->cset, $cid)))
                    $this->contacts[] = $p;
                else if (($p = get($pcm, $cid)))
                    $this->contacts[] = $p;
                else if ($Me->contactId == $cid && $Me->conf === $this->conf)
                    $this->contacts[] = $Me;
                else
                    $this->contacts[] = $this->conf->user_by_id($cid);
        }
        return $this->contacts;
    }
    public function contact($i) {
        return get($this->contacts(), $i);
    }
}
