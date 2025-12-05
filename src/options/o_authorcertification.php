<?php
// o_authorcertification.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

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

    /** @param list<Author> $authors
     * @return list<AuthorCertification_Entry> */
    static function make_author_list(Conf $conf, $authors) {
        foreach ($authors as $auth) {
            $conf->prefetch_user_by_email($auth->email);
        }
        $lemap = [];
        $entries = [];
        foreach ($authors as $auth) {
            $entries[] = $e = new AuthorCertification_Entry($auth->email);
            $e->value = false;
            $e->author = $auth;
            if ($auth->email === "") {
                $e->austatus = $auth->is_empty() ? self::AUS_EMPTY : self::AUS_NOEMAIL;
                continue;
            }
            $lemail = strtolower($e->email);
            if (isset($lemap[$lemail])) {
                $e->austatus = self::AUS_DUPLICATE;
                continue;
            }
            $lemap[$lemail] = true;
            $e->user = $conf->user_by_email($auth->email, USER_SLICE);
            if (!$e->user) {
                $e->austatus = self::AUS_NOACCOUNT;
                continue;
            }
            $e->austatus = self::AUS_OK;
            $e->uid = $e->user->contactId;
        }
        return $entries;
    }

    /** @param int $uid
     * @param list<AuthorCertification_Entry> $entries
     * @return ?AuthorCertification_Entry */
    static function find_by_id($uid, $entries) {
        foreach ($entries as $e) {
            if ($e->uid === $uid)
                return $e;
        }
        return null;
    }

    /** @param string $email
     * @param list<AuthorCertification_Entry> $entries
     * @return ?AuthorCertification_Entry */
    static function find_by_email($email, $entries) {
        foreach ($entries as $e) {
            if ($e->user && strcasecmp($e->user->email, $email) === 0)
                return $e;
        }
        return null;
    }

    /** @param Contact $u
     * @param list<AuthorCertification_Entry> $entries
     * @return ?AuthorCertification_Entry */
    static function find_by_user($u, $entries) {
        $uid1 = $u->contactId;
        $uid2 = $u->primaryContactId ? : $u->contactId;
        foreach ($entries as $e) {
            if ($e->uid === $uid1
                || $e->uid === $uid2
                || ($e->user && $e->user->primaryContactId === $uid2))
                return $e;
        }
        return null;
    }
}

use AuthorCertification_Entry as ACEntry;

class AuthorCertification_PaperOption extends PaperOption {
    /** @var int */
    private $max_submissions;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->max_submissions = $args->max_submissions ?? 0;
        if ($this->required === self::REQ_REGISTER) {
            $this->set_required(self::REQ_SUBMIT);
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
     * @return list<AuthorCertification_Entry> */
    static function entries($ov) {
        self::prefetch_entries($ov);
        $conf = $ov->prow->conf;
        $entries = [];
        foreach ($ov->value_list() as $i => $v) {
            if ($v <= 0) {
                continue;
            }
            $e = new AuthorCertification_Entry;
            $e->uid = $v;
            $e->user = $conf->user_by_id($v, USER_SLICE);
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
            $entries[] = $e;
        }
        return $entries;
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
        if ($user->can_administer($prow)
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
            return true;
        }
        $status = $ov->prow->want_submitted() ? MessageSet::ERROR : MessageSet::WARNING;
        $m = $this->conf->_("<0>Every author must certify to allow review");
        $ov->append_item(new MessageItem($status, $this->formid, $m));
        return false;
    }


    /** @return list<AuthorCertification_Entry> */
    static private function author_option_entries(PaperInfo $prow) {
        $auov = $prow->force_option(PaperOption::AUTHORSID);
        $aulist = Authors_PaperOption::author_list($auov);
        return ACEntry::make_author_list($prow->conf, $aulist);
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
                       && !ACEntry::find_by_user($e->user, $entries)
                       && ACEntry::find_by_user($e->user, $base_entries)) {
                $base_entries = null;
            }
        }
        if ($base_entries !== null) {
            // contains no decertification: we can make this change
            $ov->append_item(MessageItem::success(null));
        }
    }

    /** @return bool */
    private function entries_complete(PaperInfo $prow, $entries) {
        if (empty($entries)) {
            return false;
        }
        // retrieve author emails; missing, duplicate, none => no certification
        $has_author = false;
        foreach (self::author_option_entries($prow) as $e) {
            if ($e->austatus === ACEntry::AUS_OK
                && ACEntry::find_by_user($e->user, $entries)) {
                $has_author = true;
            } else if ($e->austatus !== ACEntry::AUS_EMPTY) {
                return false;
            }
        }
        return $has_author;
    }

    private function author_change_error($auov, $base_auov, $entries, $ps) {
        $conf = $auov->prow->conf;
        $base_aulist = Authors_PaperOption::author_list($base_auov);
        $aulist = Authors_PaperOption::author_list($auov);
        foreach (ACEntry::make_author_list($conf, $aulist) as $e) {
            if ($e->email !== ""
                && !Author::find_by_email($e->email, $base_aulist)
                && (!$e->user || !ACEntry::find_by_user($e->user, $entries))) {
                $ps->append_item(MessageItem::estop_at("authors", $this->conf->_("<0>You can’t add {} to the author list at this time", $e->author->name(NAME_P | NAME_A))));
                $ps->append_item(MessageItem::inform_at("authors", $this->conf->_("<0>{} hasn’t certified the {} field, and certification is required for submission. You can add authors by first converting this {submission} to a draft.", $e->email, $this->edit_title())));
                $ps->append_item(MessageItem::error_at("authors:{$e->author->author_index}"));
            }
        }
    }

    function value_reconcile(PaperValue $ov, PaperStatus $ps) {
        $entries = self::entries($ov);
        $want_complete = self::entries_complete($ov->prow, $entries);
        $have_complete = self::is_complete($ov);
        // check for illegal change to authors
        if (!$have_complete && $this->required > 0) {
            $auov = $ov->prow->option(PaperOption::AUTHORSID);
            $base_auov = $ov->prow->base_option(PaperOption::AUTHORSID);
            if (!$auov->equals($base_auov)
                && self::is_complete($ov->prow->base_option($this->id))
                && $ov->prow->want_submitted()) {
                $this->author_change_error($auov, $base_auov, $entries, $ps);
            }
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

    /** @param list<AuthorCertification_Entry> $entries
     * @return array<int,int> */
    private function load_submission_counts(PaperInfo $prow, $entries) {
        $assignments = $counts = [];
        foreach ($entries as $e) {
            if (!$e->user || isset($counts[$e->uid])) {
                continue;
            }
            $counts[$e->uid] = 0;
            if ($e->user->primaryContactId <= 0
                && ($e->user->cflags & Contact::CF_PRIMARY) === 0) {
                $assignments[$e->uid] = [$e->uid];
                continue;
            }
            foreach ($prow->conf->linked_user_ids($e->uid) as $xuid) {
                if (!in_array($e->uid, $assignments[$xuid] ?? [], true)) {
                    $assignments[$xuid][] = $e->uid;
                }
            }
        }

        $result = $prow->conf->qe("select paperId, value from PaperOption where paperId!=? and optionId=? and value?a", $prow->paperId, $this->id, array_keys($assignments));
        $pids = [];
        while (($row = $result->fetch_row())) {
            $pid = (int) $row[0];
            $uid = (int) $row[1];
            foreach ($assignments[$uid] as $xuid) {
                if (!in_array($pid, $pids[$xuid] ?? [], true)) {
                    $pids[$xuid][] = $pid;
                    $counts[$xuid] = ($counts[$xuid] ?? 0) + 1;
                }
            }
        }
        $result->close();
        return $counts;
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @param list<AuthorCertification_Entry> $entries
     * @param array<int,int> &$submission_counts
     * @param list<MessageItem> &$msgs
     * @return bool */
    private function resolve_max_submissions($user, $prow, $entries, &$submission_counts, &$msgs) {
        if ($this->max_submissions <= 0) {
            return true;
        }
        $submission_counts = $submission_counts
            ?? $this->load_submission_counts($prow, $entries);
        if ($submission_counts[$user->contactId] < $this->max_submissions) {
            return true;
        }
        $msgs[] = MessageItem::error_at($this->field_key(), $this->conf->_("<0>{} cannot certify this {submission}, because each author can certify at most {} of their {submissions}", $user->email, $this->max_submissions));
        return false;
    }

    /** @param list<AuthorCertification_Entry> $entries
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

        $xentries = [];
        $baseov = $prow->base_option($this);
        $base_entries = self::entries($baseov);
        $subcounts = null;
        foreach ($base_entries as $basee) {
            $ne = ACEntry::find_by_id($basee->uid, $entries);
            if ($ne
                && $basee->value !== $ne->value
                && self::user_can_change($user, $prow, $ne->user->email)) {
                if ($ne->value
                    && $this->resolve_max_submissions($ne->user, $prow, $entries, $subcounts, $msgs)) {
                    $xentries[] = $ne;
                }
            } else if ($basee->value) {
                $xentries[] = $basee;
            }
        }
        foreach ($entries as $ne) {
            if ($ne->user
                && $ne->value
                && !ACEntry::find_by_id($ne->uid, $base_entries)
                && self::user_can_change($user, $prow, $ne->user->email)
                && $this->resolve_max_submissions($ne->user, $prow, $entries, $subcounts, $msgs)) {
                $xentries[] = $ne;
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
        return $ov;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $okey = $this->field_key();
        $entries = [];
        $admin = $qreq->user()->can_administer($prow);
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
            $entries[] = ACEntry::make_email_by(
                $email, $v, $admin, $qreq->user()
            );
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

        $entries = $msgs = [];
        $admin = $user->can_administer($prow);
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
            $entries[] = $e = ACEntry::make_email_by(
                $ej->email, $ej->value ?? true, $ej->admin ?? $admin, $user
            );
            if (isset($ej->at) && is_int($ej->at)) {
                $e->at = $ej->at;
            }
            if (isset($ej->by) && is_int($ej->by)) {
                $e->by = $ej->by;
            }
        }
        return $this->resolve_parse($prow, $entries, $user, $msgs);
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $entries = self::entries($ov);
        $reqentries = self::entries($reqov);
        $okey = $this->field_key();

        $ready = [[], []];
        $n = 1;
        foreach ($pt->prow->author_list() as $auth) {
            if (!$auth->email) {
                continue;
            }
            $oe = ACEntry::find_by_email($auth->email, $entries);
            $oval = $oe && $oe->value;
            if (!self::user_can_change($pt->user, $pt->prow, $auth->email)) {
                $name = $auth->name_h(NAME_E | NAME_A);
                $check = $oval ? "success-mark" : "warning-mark";
                $ready[(int) $oval][] = "<li class=\"odname\"><div class=\"checki\"><span class=\"checkc\"><span class=\"{$check}\">"
                    . Ht::checkbox("", 1, $oval, ["hidden" => true, "disabled" => true])
                    . "</span></span>{$name}</div></li>";
                continue;
            }
            $reqe = ACEntry::find_by_email($auth->email, $reqentries);
            $reqoval = $reqe && $reqe->value;
            $ready[(int) $oval][] = "<li class=\"odname\"><label class=\"checki\"><span class=\"checkc\">"
                . Ht::checkbox("{$okey}:{$n}:value", 1, $reqoval, [
                    "class" => "checkc", "data-default-checked" => $oval
                ])
                . "</span>" . $auth->name_h(NAME_E | NAME_A) . "</label>"
                . Ht::hidden("{$okey}:{$n}:email", $auth->email)
                . Ht::hidden("has_{$okey}:{$n}:value", 1)
                . "</li>";
            ++$n;
        }

        $pt->print_editable_option_papt($this, null, ["for" => false, "id" => $this->readable_formid()]);
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid, '">';
        if (!empty($ready[1])) {
            $mb = empty($ready[0]) ? " mb-0" : "";
            echo "<ul class=\"x{$mb}\">", join("", $ready[1]), "</ul>";
        }
        if (!empty($ready[0])) {
            echo '<h4 class="mb-0 font-italic">Incomplete</h4><ul class="x mb-0">', join("", $ready[0]), '</ul>';
        }
        if (empty($ready[0]) && empty($ready[1])) {
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
                $oe = ACEntry::find_by_email($auth->email, $entries);
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
        $ready = [[], []];
        foreach ($ov->prow->author_list() as $auth) {
            if (!$auth->email) {
                continue;
            }
            $oe = ACEntry::find_by_email($auth->email, $entries);
            $oval = $oe && $oe->value;
            $ready[(int) $oval][] = '<li class="odname">' . $auth->name_h(NAME_E | NAME_A) . "</li>";
        }
        $t = "";
        if (!empty($ready[1])) {
            $mb = empty($ready[0]) ? " mb-0" : "";
            $t .= '<h4 class="mb-0 font-italic">Complete</h4><ul class="x' . $mb . '">' . join("", $ready[1]) . '</ul>';
        }
        if (!empty($ready[0])) {
            $t .= '<h4 class="mb-0 font-italic">Incomplete</h4><ul class="x mb-0">' . join("", $ready[0]) . '</ul>';
        }
        if ($t === "") {
            $t .= '<h4 class="mb-0 font-italic">No authors yet</h4>';
        }
        $fr->value = $t;
        $fr->value_format = 5;
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
