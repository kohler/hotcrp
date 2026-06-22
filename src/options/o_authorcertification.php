<?php
// o_authorcertification.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class AuthorCertification_Entry {
    /** @var string */
    public $email;
    /** @var int */
    public $uid;
    /** @var ?Contact */
    public $user;
    /** @var bool */
    public $value = true;
    /** @var ?int */
    public $at;
    /** @var bool */
    public $admin = false;
    /** @var int */
    public $by;
    /** @var ?Author */
    public $author;
    /** @var int */
    public $austatus;

    const AUS_OK = 0;
    const AUS_EMPTY = 1;
    const AUS_NOEMAIL = 2;
    const AUS_NOACCOUNT = 3;
    const AUS_DUPLICATE = 4;

    /** @param string $email */
    function __construct($email) {
        $this->email = $email;
    }

    /** @return AuthorCertification_Entry */
    static function make_email_by($email, $value, $admin, $viewer) {
        $e = new AuthorCertification_Entry($email);
        $e->value = $value;
        $e->at = Conf::$now;
        $e->admin = $admin;
        $e->by = $viewer->contactId;
        return $e;
    }

    /** @return ?object */
    function data() {
        $d = [];
        if (isset($this->at)) {
            $d["at"] = $this->at;
        }
        if ($this->admin) {
            $d["admin"] = true;
        }
        if ($this->by > 0 && $this->by !== $this->uid) {
            $d["by"] = $this->by;
        }
        return empty($d) ? null : (object) $d;
    }
}

use AuthorCertification_Entry as ACEntry;


class AuthorCertification_EntryList implements IteratorAggregate {
    /** @var list<AuthorCertification_Entry> */
    public $es = [];
    /** @var PaperOption */
    private $option;
    /** @var int */
    private $skip_pid = -1;
    /** @var ?array<int,int> */
    private $counts;

    /** @param list<Author> $authors
     * @return AuthorCertification_EntryList */
    static function make_author_list(Conf $conf, $authors) {
        foreach ($authors as $auth) {
            $conf->prefetch_user_by_email($auth->email);
        }
        $lemap = [];
        $elist = new AuthorCertification_EntryList;
        foreach ($authors as $auth) {
            $e = new AuthorCertification_Entry($auth->email);
            $elist->append($e);
            $e->value = false;
            $e->author = $auth;
            if ($auth->email === "") {
                $e->austatus = $auth->is_empty() ? ACEntry::AUS_EMPTY : ACEntry::AUS_NOEMAIL;
                continue;
            }
            $lemail = strtolower($e->email);
            if (isset($lemap[$lemail])) {
                $e->austatus = ACEntry::AUS_DUPLICATE;
                continue;
            }
            $lemap[$lemail] = true;
            $e->user = $conf->user_by_email($auth->email, USER_SLICE);
            if (!$e->user) {
                $e->austatus = ACEntry::AUS_NOACCOUNT;
                continue;
            }
            $e->austatus = ACEntry::AUS_OK;
            $e->uid = $e->user->contactId;
        }
        return $elist;
    }

    /** @return bool */
    function is_empty() {
        return empty($this->es);
    }

    /** @param AuthorCertification_Entry $e */
    function append($e) {
        $this->es[] = $e;
        $this->counts = null;
    }

    #[\ReturnTypeWillChange]
    function getIterator() {
        return new ArrayIterator($this->es);
    }

    /** @param int $uid
     * @return ?AuthorCertification_Entry */
    function find_by_id($uid) {
        foreach ($this->es as $e) {
            if ($e->uid === $uid)
                return $e;
        }
        return null;
    }

    /** @param string $email
     * @return ?AuthorCertification_Entry */
    function find_by_email($email) {
        foreach ($this->es as $e) {
            if ($e->user && strcasecmp($e->user->email, $email) === 0)
                return $e;
        }
        return null;
    }

    /** @param ?Contact $u
     * @return ?AuthorCertification_Entry */
    function find_by_user($u) {
        if (!$u) {
            return null;
        }
        $uid1 = $u->contactId;
        $uid2 = $u->primaryContactId ? : $u->contactId;
        foreach ($this->es as $e) {
            if ($e->uid === $uid1
                || $e->uid === $uid2
                || ($e->user && $e->user->primaryContactId === $uid2))
                return $e;
        }
        return null;
    }


    function set_option(PaperOption $option, $skip_pid) {
        $this->option = $option;
        $this->skip_pid = $skip_pid;
    }

    /** @return array<int,int> */
    private function submission_counts() {
        if ($this->counts !== null) {
            return $this->counts;
        }
        $this->counts = [];
        $conf = $this->option->conf;

        $assignments = [];
        foreach ($this->es as $e) {
            if (!$e->user || isset($this->counts[$e->uid])) {
                continue;
            }
            $this->counts[$e->uid] = 0;
            if ($e->user->primaryContactId <= 0
                && ($e->user->cflags & Contact::CF_PRIMARY) === 0) {
                $assignments[$e->uid] = [$e->uid];
                continue;
            }
            foreach ($conf->linked_user_ids($e->uid) as $xuid) {
                if (!in_array($e->uid, $assignments[$xuid] ?? [], true)) {
                    $assignments[$xuid][] = $e->uid;
                }
            }
        }

        $result = $conf->qe("select Paper.paperId, value
            from PaperOption join Paper using (paperId)
            where paperId!=? and optionId=? and value?a and timeWithdrawn<=0",
            $this->skip_pid, $this->option->id, array_keys($assignments));
        $pids = [];
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $uid = (int) $row[1];
            foreach ($assignments[$uid] as $xuid) {
                if (!in_array($pid, $pids[$xuid] ?? [], true)) {
                    $pids[$xuid][] = $pid;
                    $this->counts[$xuid] = ($this->counts[$xuid] ?? 0) + 1;
                }
            }
        }
        $result->close();

        return $this->counts;
    }

    /** @param Contact $user
     * @return int */
    function submission_count(Contact $user) {
        $counts = $this->submission_counts();
        return $counts[$user->contactId];
    }
}

use AuthorCertification_EntryList as ACEntryList;


class AuthorCertification_PaperOption extends PaperOption {
    /** @var int */
    private $max_submissions;
    /** @var list<string> */
    private $max_submissions_problems;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->max_submissions = $args->max_submissions ?? 0;
        if ($this->required === self::REQ_REGISTER) {
            $this->set_required(self::REQ_SUBMIT);
        }
        if ($this->visibility() === self::VIS_SUB) {
            $this->set_visibility(self::VIS_AUTHOR);
        }
    }

    /** @param PaperValue $ov
     * @return bool */
    static function is_complete($ov) {
        return $ov->value_list_contains(-1);
    }

    /** @param PaperValue $ov */
    static function prefetch_entries($ov) {
        $ov->prow->conf->prefetch_users_by_id($ov->value_list());
    }

    /** @param PaperValue $ov
     * @return AuthorCertification_EntryList */
    static function entries($ov) {
        self::prefetch_entries($ov);
        $conf = $ov->prow->conf;
        $elist = new AuthorCertification_EntryList;
        foreach ($ov->value_list() as $i => $v) {
            $u = $v > 0 ? $conf->user_by_id($v, USER_SLICE) : null;
            if (!$u) {
                continue;
            }
            $e = new AuthorCertification_Entry($u->email);
            $e->uid = $v;
            $e->user = $u;
            if (($j = json_decode_object($ov->data_by_index($i)))) {
                if (isset($j->value)) {
                    $e->value = $j->value;
                }
                if (isset($j->at)) {
                    $e->at = $j->at;
                }
                if (isset($j->admin)) {
                    $e->admin = $j->admin;
                }
                $e->by = $j->by ?? $v;
            }
            $elist->append($e);
        }
        return $elist;
    }

    function value_export_json(PaperValue $ov, PaperExport $pex) {
        if ($ov->value_count() === 0) {
            return false;
        }
        $oj = (object) ["complete" => self::is_complete($ov), "entries" => []];
        foreach (self::entries($ov) as $e) {
            if (!$e->user || !$e->value) {
                continue;
            }
            $oej = (object) ["email" => $e->user->email];
            if (isset($e->at)) {
                $oej->at = $e->at;
            }
            if ($e->admin) {
                $oej->admin = true;
            }
            $oj->entries[] = $oej;
        }
        return $oj;
    }

    /** @param string $email
     * @return bool */
    static function user_can_change(Contact $user, PaperInfo $prow, $email) {
        if ($user->can_manage($prow)
            || strcasecmp($user->email, $email) === 0) {
            return true;
        }
        $resolved = $prow->conf->resolve_primary_emails([$user->email, $email]);
        return strcasecmp($resolved[0], $resolved[1]) === 0;
    }

    function is_value_present_trivial() {
        return false;
    }

    function value_present(PaperValue $ov) {
        return self::is_complete($ov);
    }

    function value_check_required(PaperValue $ov) {
        if (!$this->test_required($ov->prow)
            || $this->value_present($ov)
            || $ov->prow->allow_absent()) {
            if ($this->max_submissions > 0) {
                return $this->_value_check_max_submissions($ov);
            }
            return true;
        }
        $status = $ov->prow->want_submitted() ? MessageSet::ERROR : MessageSet::WARNING;
        $m = $this->conf->_("<0>Every author must certify to allow review");
        $ov->append_item(new MessageItem($status, $this->formid, $m));
        return false;
    }

    private function max_submissions_error_for($email) {
        return $this->conf->_("<5>{email} has reached the certification limit",
            new FmtArg("email", $email, 0),
            new FmtArg("max_submissions", $this->max_submissions, 0));
    }

    private function max_submissions_inform_for($email, $action) {
        return $this->conf->_("<5>Each author may certify up to {max_submissions} {submissions}. To certify this {submission}, first decertify or withdraw another one (<a href=\"{url}\">view list</a>).",
            new FmtArg("action", $action, 0),
            new FmtArg("max_submissions", $this->max_submissions, 0),
            new FmtArg("url", $this->conf->hoturl_raw("search", ["t" => "act", "q" => $this->search_keyword() . ":" . $email]), 0));
    }

    private function _value_check_max_submissions(PaperValue $ov) {
        if ($ov->prow->timeSubmitted > 0 || !$ov->prow->want_submitted()) {
            return true;
        }
        $entries = self::entries($ov);
        $entries->set_option($this, -1);
        $ok = true;
        foreach ($entries as $e) {
            if ($entries->submission_count($e->user) > $this->max_submissions) {
                $ov->error($this->max_submissions_error_for($e->email));
                $ov->inform($this->max_submissions_inform_for($e->email, "submit"));
                $ok = false;
            }
        }
        return $ok;
    }


    /** @return AuthorCertification_EntryList */
    static private function author_option_entries(PaperInfo $prow) {
        $auov = $prow->force_option(PaperOption::AUTHORSID);
        $aulist = Authors_PaperOption::author_list($auov);
        return ACEntryList::make_author_list($prow->conf, $aulist);
    }

    function value_check(PaperValue $ov, Contact $user) {
        // NB assume that parent::value_check === value_check_required.
        // $want_success/$base_entries checks whether this modification can be
        // saved despite error
        $want_success = !$ov->has_error();
        if ($this->value_check_required($ov)) {
            $want_success = false;
        }
        if (self::is_complete($ov)) {
            return;
        }
        $entries = $base_entries = null;
        if ($want_success) {
            $entries = self::entries($ov);
            $base_entries = self::entries($ov->prow->base_option($this->id));
        }
        foreach (self::author_option_entries($ov->prow) as $e) {
            if ($e->austatus === ACEntry::AUS_NOEMAIL) {
                $ov->warning($this->conf->_("<0>Author #{} ({}) has no email address, so cannot complete this certification", $e->author->author_index, $e->author->name(NAME_P | NAME_A)));
            } else if ($e->austatus === ACEntry::AUS_DUPLICATE) {
                $ov->warning($this->conf->_("<0>Author #{}’s email address {} is also used for another author. All authors must have different emails to complete this certification", $e->author->author_index, $e->author->email));
            } else if ($base_entries !== null
                       && $e->austatus === ACEntry::AUS_OK
                       && !$entries->find_by_user($e->user)
                       && $base_entries->find_by_user($e->user)) {
                $base_entries = null;
            }
        }
        if ($base_entries !== null) {
            // contains no decertification: we can make this change
            $ov->append_item(MessageItem::success(null));
        }
    }

    /** @param AuthorCertification_EntryList $entries
     * @return bool */
    private function entries_complete(PaperInfo $prow, $entries) {
        if ($entries->is_empty()) {
            return false;
        }
        // retrieve author emails; missing, duplicate, none => no certification
        $has_author = false;
        foreach (self::author_option_entries($prow) as $e) {
            if ($e->austatus === ACEntry::AUS_OK
                && $entries->find_by_user($e->user)) {
                $has_author = true;
            } else if ($e->austatus !== ACEntry::AUS_EMPTY) {
                return false;
            }
        }
        return $has_author;
    }

    /** @param PaperValue $auov
     * @param PaperValue $base_auov
     * @param AuthorCertification_EntryList $entries
     * @param PaperStatus $ps
     * @param bool $prevent_add */
    private function check_author_change($auov, $base_auov, $entries, $ps, $prevent_add) {
        $conf = $auov->prow->conf;
        $base_aulist = Authors_PaperOption::author_list($base_auov);
        $aulist = Authors_PaperOption::author_list($auov);
        if ($prevent_add) {
            foreach (ACEntryList::make_author_list($conf, $aulist) as $e) {
                if ($e->email === ""
                    || Author::find_by_email($e->email, $base_aulist)
                    || $entries->find_by_user($e->user)) {
                    continue;
                }
                $ps->append_item(MessageItem::estop_at("authors", $this->conf->_("<0>{} cannot be added to the author list at this time", $e->author->name(NAME_P | NAME_A))));
                $ps->append_item(MessageItem::inform_at("authors", $this->conf->_("<0>{} hasn’t certified the {} field, and certification is required for submission. You can add authors by first converting this {submission} to a draft.", $e->email, $this->edit_title())));
                $ps->append_item(MessageItem::error_at("authors:{$e->author->author_index}"));
            }
        }
        foreach (ACEntryList::make_author_list($conf, $base_aulist) as $e) {
            if ($e->email === ""
                || Author::find_by_email($e->email, $aulist)
                || !$entries->find_by_user($e->user)) {
                continue;
            }
            $ps->append_item(MessageItem::estop_at("authors", $this->conf->_("<0>Certified author {} cannot be removed from the author list", $e->author->name(NAME_P | NAME_A))));
            $ps->append_item(MessageItem::inform_at("authors", $this->conf->_("<0>{} has certified the {} field. They must revoke that certification before being removed from the author list.", $e->email, $this->edit_title())));
        }
    }

    function value_reconcile(PaperValue $ov, PaperStatus $ps) {
        $entries = self::entries($ov);
        $want_complete = self::entries_complete($ov->prow, $entries);
        $have_complete = self::is_complete($ov);
        // check for illegal change to authors
        $auov = $ov->prow->force_option(PaperOption::AUTHORSID);
        $base_auov = $ov->prow->base_option(PaperOption::AUTHORSID);
        if (!$auov->equals($base_auov)) {
            $prevent_add = !$have_complete
                && $this->required > 0
                && self::is_complete($ov->prow->base_option($this->id))
                && $ov->prow->want_submitted();
            $this->check_author_change($auov, $base_auov, $entries, $ps, $prevent_add);
        }
        if ($want_complete === $have_complete) {
            return null;
        }
        $values = $ov->value_list();
        $datas = $ov->data_list();
        if ($want_complete) {
            $values[] = -1;
            $datas[] = null;
        } else {
            $cidx = array_search(-1, $values, true);
            array_splice($values, $cidx, 1);
            array_splice($datas, $cidx, 1);
        }
        return PaperValue::make_multi($ov->prow, $this, $values, $datas);
    }

    /** @param Contact $user
     * @param AuthorCertification_EntryList $entries
     * @param list<MessageItem> &$msgs
     * @return bool */
    private function resolve_max_submissions($user, $entries, &$msgs) {
        if ($this->max_submissions <= 0
            || $entries->submission_count($user) < $this->max_submissions) {
            return true;
        }
        $this->max_submissions_problems[] = $user->email;
        return false;
    }

    /** @param AuthorCertification_EntryList $entries
     * @param list<MessageItem> $msgs
     * @return PaperValue */
    private function resolve_parse(PaperInfo $prow, $entries, Contact $user, $msgs = []) {
        foreach ($entries as $ne) {
            $prow->conf->prefetch_user_by_email($ne->email);
        }
        foreach ($entries as $ne) {
            $ne->user = $prow->conf->user_by_email($ne->email, USER_SLICE);
            $ne->uid = $ne->user ? $ne->user->contactId : 0;
        }
        $entries->set_option($this, $prow->paperId);
        $this->max_submissions_problems = [];

        $xentries = new AuthorCertification_EntryList;
        $baseov = $prow->base_option($this);
        $base_entries = self::entries($baseov);
        foreach ($base_entries as $basee) {
            $ne = $entries->find_by_id($basee->uid);
            if ($ne
                && $basee->value !== $ne->value
                && self::user_can_change($user, $prow, $ne->user->email)) {
                if ($ne->value
                    && $this->resolve_max_submissions($ne->user, $entries, $msgs)) {
                    $xentries->append($ne);
                }
            } else if ($basee->value) {
                $xentries->append($basee);
            }
        }
        foreach ($entries as $ne) {
            if ($ne->user
                && $ne->value
                && !$base_entries->find_by_id($ne->uid)
                && self::user_can_change($user, $prow, $ne->user->email)
                && $this->resolve_max_submissions($ne->user, $entries, $msgs)) {
                $xentries->append($ne);
            }
        }

        $values = $datas = [];
        foreach ($xentries as $xe) {
            $values[] = $xe->uid;
            $datas[] = json_encode_db($xe->data());
        }
        if (self::entries_complete($prow, $xentries)) {
            $values[] = -1;
            $datas[] = null;
        }
        $ov = PaperValue::make_multi($prow, $this, $values, $datas);
        if (!empty($msgs)) {
            $ov->message_set()->append_list($msgs);
        }
        foreach ($this->max_submissions_problems as $i => $email) {
            $ov->error($this->max_submissions_error_for($email));
            $ov->inform($this->max_submissions_inform_for($email, "certify"));
        }
        $this->max_submissions_problems = null;
        return $ov;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $okey = $this->field_key();
        $entries = new AuthorCertification_EntryList;
        $admin = $qreq->user()->can_manage($prow);
        for ($n = 1; true; ++$n) {
            $ekey = "{$okey}:{$n}:email";
            $email = $qreq[$ekey];
            if (!isset($email)) {
                break;
            }
            $vstr = $qreq["{$okey}:{$n}:value"]
                ?? (friendly_boolean($qreq["has_{$okey}:{$n}:value"]) ? "0" : "1");
            if (($v = friendly_boolean($vstr)) === null) {
                continue;
            }
            $entries->append(ACEntry::make_email_by($email, $v, $admin, $qreq->user()));
        }
        return $this->resolve_parse($prow, $entries, $qreq->user());
    }

    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if (is_object($j) && isset($j->entries)) {
            $j = $j->entries;
        }
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if (!is_array($j) || !array_is_list($j)) {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }

        $entries = new AuthorCertification_EntryList;
        $msgs = [];
        $admin = $user->can_manage($prow);
        foreach ($j as $i => $ej) {
            if (is_array($ej) && !array_is_list($ej)) {
                $ej = (object) $ej;
            } else if (is_string($ej)) {
                $ej = (object) ["email" => $ej];
            } else if (!is_object($ej) || !is_string($ej->email ?? null)) {
                return PaperValue::make_estop($prow, $this, "<0>Validation error on entry #" . ($i + 1));
            }
            if (!validate_email($ej->email)) {
                $x = $ej->email === "" ? "<empty>" : $ej->email;
                $msgs[] = MessageItem::error_at($this->formid, "<0>Invalid author email ‘{$x}’");
                continue;
            }
            $e = ACEntry::make_email_by(
                $ej->email, $ej->value ?? true, $ej->admin ?? $admin, $user
            );
            if (isset($ej->at) && is_int($ej->at)) {
                $e->at = $ej->at;
            }
            if (isset($ej->by) && is_int($ej->by)) {
                $e->by = $ej->by;
            }
            $entries->append($e);
        }
        return $this->resolve_parse($prow, $entries, $user, $msgs);
    }

    /** @param Author|Contact $auth
     * @param int &$n
     * @return string */
    private function web_edit_one(PaperTable $pt, $auth, ?ACEntry $oe,
                                  ACEntryList $reqentries, &$n) {
        $checked = $oe && $oe->value;
        if (!self::user_can_change($pt->user, $pt->prow, $auth->email)) {
            $name = $auth->name_h(NAME_E | NAME_A);
            if ($checked) {
                $class = $oe->author ? "success-mark" : "error-mark";
            } else {
                $class = "warning-mark";
            }
            return "<li class=\"odname\"><div class=\"checki\"><span class=\"checkc\"><span class=\"{$class}\">"
                . Ht::checkbox("", 1, $checked, ["hidden" => true, "disabled" => true])
                . "</span></span>{$name}</div></li>";
        }
        $reqe = $reqentries->find_by_email($auth->email);
        $reqchecked = $reqe && $reqe->value;
        $okey = $this->field_key();
        ++$n;
        return "<li class=\"odname\"><label class=\"checki\"><span class=\"checkc\">"
            . Ht::checkbox("{$okey}:{$n}:value", 1, $reqchecked, [
                "class" => "checkc", "data-default-checked" => $checked
            ])
            . "</span>" . $auth->name_h(NAME_E | NAME_A) . "</label>"
            . Ht::hidden("{$okey}:{$n}:email", $auth->email)
            . Ht::hidden("has_{$okey}:{$n}:value", 1)
            . "</li>";
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $entries = self::entries($ov);
        $reqentries = self::entries($reqov);
        $okey = $this->field_key();

        $ready = [[], [], []];
        $n = 0;
        foreach ($pt->prow->author_list() as $auth) {
            if (!$auth->email) {
                continue;
            }
            if (($oe = $entries->find_by_email($auth->email))) {
                $oe->author = $auth;
            }
            $ready[$oe && $oe->value ? 1 : 0][] =
                $this->web_edit_one($pt, $auth, $oe, $reqentries, $n);
        }
        foreach ($entries as $oe) {
            if ($oe->author || !$oe->value) {
                continue;
            }
            $ready[2][] = $this->web_edit_one($pt, $oe->user, $oe, $reqentries, $n);
        }

        $pt->print_editable_option_papt($this, null, ["for" => false, "id" => $this->readable_formid()]);
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid, '">';
        if (!empty($ready[1])) {
            $mb = empty($ready[0]) && empty($ready[2]) ? " mb-0" : "";
            echo "<ul class=\"x{$mb}\">", join("", $ready[1]), "</ul>";
        }
        if (!empty($ready[0])) {
            $mb = empty($ready[2]) ? " mb-0" : "";
            echo "<h4 class=\"mb-0 font-italic\">Incomplete</h4><ul class=\"x{$mb}\">", join("", $ready[0]), '</ul>';
        }
        if (!empty($ready[2])) {
            echo "<h4 class=\"mb-0 font-italic\">Obsolete</h4>",
                MessageSet::feedback_html([MessageItem::warning("<0>These author certifications are associated with authors who have been removed from the paper. Only administrators can remove these certifications.")]),
                "<ul class=\"x mb-0\">", join("", $ready[2]), '</ul>';
        }
        if (empty($ready[0]) && empty($ready[1]) && empty($ready[2])) {
            echo '<h4 class="mb-0 font-italic">No authors yet</h4>',
                Ht::checkbox("", 1, false, ["hidden" => true, "disabled" => true]);
        }
        echo "</fieldset></div>\n\n";
    }

    function print_web_edit_hidden(PaperTable $pt, $ov) {
        echo '<fieldset name="', $this->formid, '" role="none" hidden>';
        $entries = self::entries($ov);
        $okey = $this->field_key();
        $n = 0;
        foreach ($pt->prow->author_list() as $auth) {
            if ($auth->email) {
                $oe = $entries->find_by_email($auth->email);
                $oval = $oe && $oe->value;
                echo Ht::checkbox("{$okey}:{$n}:value", 1, $oval, ["disabled" => true]);
                ++$n;
            }
        }
        if ($n === 0) {
            echo Ht::checkbox("", 1, false, ["hidden" => true, "disabled" => true]);
        }
        echo '</fieldset>';
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if (($fr->context & FieldRender::CFFORM) === 0) {
            return;
        }
        $entries = self::entries($ov);
        $ready = [[], [], []];
        foreach ($ov->prow->author_list() as $auth) {
            if (!$auth->email) {
                continue;
            }
            if (($oe = $entries->find_by_email($auth->email))) {
                $oe->author = $auth;
            }
            $oval = $oe && $oe->value;
            $ready[(int) $oval][] = '<li class="odname">' . $auth->name_h(NAME_E | NAME_A) . "</li>";
        }
        foreach ($entries as $oe) {
            if ($oe->author || !$oe->value) {
                continue;
            }
            $ready[2][] = '<li class="odname">' . $oe->user->name_h(NAME_E | NAME_A) . "</li>";
        }
        $t = "";
        if (!empty($ready[1])) {
            $mb = empty($ready[0]) && empty($ready[2]) ? " mb-0" : "";
            $t .= '<h4 class="mb-0 font-italic">Complete</h4><ul class="x' . $mb . '">' . join("", $ready[1]) . '</ul>';
        }
        if (!empty($ready[0])) {
            $mb = empty($ready[2]) ? " mb-0" : "";
            $t .= '<h4 class="mb-0 font-italic">Incomplete</h4><ul class="x' . $mb . '">' . join("", $ready[0]) . '</ul>';
        }
        if (!empty($ready[2])) {
            $t .= '<h4 class="mb-0 font-italic">Obsolete</h4>'
                . MessageSet::feedback_html([MessageItem::warning("<0>These author certifications are associated with authors who have been removed. Only administrators can remove these certifications.")])
                . '<ul class="x mb-0">' . join("", $ready[2]) . '</ul>';
        }
        if ($t === "") {
            $t .= '<h4 class="mb-0 font-italic">No authors yet</h4>';
        }
        $fr->value = $t;
        $fr->value_format = 5;
    }

    function search_examples(Contact $viewer, $venue) {
        return [
            $this->has_search_example(),
            $this->make_search_example(
                $this->search_keyword() . ":{email}",
                "<0>submission’s {title} field has been certified by {email}",
                new FmtArg("email", "anne@dudfield.org", 0)
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        $w = $sword->word;
        if (strcasecmp($w, "me") === 0) {
            $w = $srch->user->email;
        }
        if (strpos($w, "@") !== false) {
            $u = $srch->conf->user_by_email($w);
            // XXX warn if no user
            if (!$u) {
                return new False_SearchTerm;
            }
            return new OptionValueIn_SearchTerm($srch->user, $this, $srch->conf->linked_user_ids($u->contactId));
        }
        return null;
    }
    function present_script_expression() {
        return ["type" => "all_checkboxes", "formid" => $this->formid];
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->max_submissions > 0) {
            $j->max_submissions = $this->max_submissions;
        }
        return $j;
    }

    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->max_submissions = $this->max_submissions;
        return $sfs;
    }
}
