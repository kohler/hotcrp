<?php
// hotcrpmailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class HotCRPMailPreparation extends MailPreparation {
    /** @var int */
    public $paperId = -1;
    /** @var bool */
    public $author_recipient = false;
    /** @var int */
    public $paper_expansions = 0;
    /** @var int */
    public $combination_type = 0;
    /** @var bool */
    public $fake = false;
    /** @var ?HotCRPMailPreparation */
    public $censored_preparation; // used in mail tool

    /** @param Conf $conf
     * @param ?Contact $recipient */
    function __construct($conf, $recipient) {
        parent::__construct($conf, $recipient);
    }
    /** @param MailPreparation $p
     * @return bool */
    function can_merge($p) {
        return parent::can_merge($p)
            && $p instanceof HotCRPMailPreparation
            && $this->combination_type === $p->combination_type
            && (($this->combination_type === 2
                 && !$this->paper_expansions
                 && !$p->paper_expansions)
                || ($this->author_recipient === $p->author_recipient
                    && $this->combination_type != 0
                    && $this->paperId === $p->paperId)
                || ($this->author_recipient === $p->author_recipient
                    && $this->has_same_recipients($p)));
    }
    function finalize() {
        parent::finalize();
        if (preg_match('/\ADear (author|reviewer)(?:|s|\(s\))(?=[,;!.\s])/', $this->body, $m)) {
            $pl = count($this->recipients()) === 1 ? "" : "s";
            $this->body = "Dear {$m[1]}{$pl}" . substr($this->body, strlen($m[0]));
        }
    }
}

class HotCRPMailer extends Mailer {
    /** @var array<string,Contact|Author> */
    protected $contacts = [];
    /** @var ?Contact */
    protected $permuser;

    /** @var ?PaperInfo */
    protected $row;
    /** @var ?ReviewInfo */
    protected $rrow;
    /** @var bool */
    protected $rrow_unsubmitted = false;
    /** @var ?CommentInfo */
    protected $comment_row;
    /** @var ?int */
    protected $newrev_since;
    /** @var bool */
    protected $no_send = false;
    /** @var int */
    public $combination_type = 0;
    /** @var ?string */
    protected $_unexpanded_paper_keyword;

    protected $_statistics = null;


    /** @param ?Contact $recipient
     * @param array{prow?:PaperInfo,rrow?:ReviewInfo,requester_contact?:Contact,reviewer_contact?:Contact} $rest */
    function __construct(Conf $conf, $recipient = null, $rest = []) {
        parent::__construct($conf);
        $this->reset($recipient, $rest);
        if (isset($rest["combination_type"])) {
            $this->combination_type = $rest["combination_type"];
        }
    }

    /** @param ?Contact $recipient */
    function reset($recipient = null, $rest = []) {
        parent::reset($recipient, $rest);
        if ($recipient) {
            assert($recipient instanceof Contact);
            assert(!($recipient->overrides() & Contact::OVERRIDE_CONFLICT));
        }
        foreach (["requester", "reviewer", "other"] as $k) {
            $this->contacts[$k] = $rest["{$k}_contact"] ?? null;
        }
        $this->row = $rest["prow"] ?? null;
        assert(!$this->row || $this->row->paperId > 0);
        $this->rrow = $rest["rrow"] ?? null;
        $this->comment_row = $rest["comment_row"] ?? null;
        $this->newrev_since = $rest["newrev_since"] ?? null;
        $this->rrow_unsubmitted = !!($rest["rrow_unsubmitted"] ?? false);
        $this->no_send = !!($rest["no_send"] ?? false);
        if (($rest["author_permission"] ?? false) && $this->row) {
            $this->permuser = $this->row->author_user();
        } else {
            $this->permuser = $this->recipient;
        }
        $this->_unexpanded_paper_keyword = null;
        // Infer reviewer contact from rrow/comment_row
        if (!$this->contacts["reviewer"]) {
            if ($this->rrow) {
                $this->contacts["reviewer"] = $this->rrow->reviewer();
            } else if ($this->comment_row) {
                $this->contacts["reviewer"] = $this->comment_row->commenter();
            }
        }
        // Do not put passwords in email that is cc'd elsewhere
        if ((($rest["cc"] ?? null) || ($rest["bcc"] ?? null))
            && (!$this->censor || $this->censor === self::CENSOR_DISPLAY)) {
            $this->censor = self::CENSOR_ALL;
        }
    }


    // expansion helpers
    private function _expand_reviewer($type, $isbool) {
        if (!($c = $this->contacts["reviewer"])) {
            return false;
        }
        if ($this->row
            && $this->rrow
            && $this->conf->is_review_blind((bool) $this->rrow->reviewBlind)
            && !$this->permuser->can_view_review_identity($this->row, $this->rrow)) {
            if ($isbool) {
                return false;
            } else if ($this->context == self::CONTEXT_EMAIL) {
                return "<hidden>";
            } else {
                return "Hidden for anonymous review";
            }
        }
        return $this->expand_user($c, $type);
    }

    /** @return Tagger */
    private function tagger()  {
        return new Tagger($this->recipient);
    }

    private function get_reviews() {
        $old_overrides = $this->permuser->overrides();
        if ($this->conf->_au_seerev === null) { /* assume sender wanted to override */
            $this->permuser->add_overrides(Contact::OVERRIDE_AU_SEEREV);
        }
        assert(($old_overrides & contact::OVERRIDE_CONFLICT) === 0);

        if ($this->rrow) {
            $rrows = [$this->rrow];
        } else {
            $this->row->ensure_full_reviews();
            $rrows = $this->row->reviews_as_display();
        }

        $text = "";
        $rf = $this->conf->review_form();
        foreach ($rrows as $rrow) {
            if (($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                 || ($rrow === $this->rrow && $this->rrow_unsubmitted))
                && $this->permuser->can_view_review($this->row, $rrow)) {
                if ($text !== "") {
                    $text .= "\n\n*" . str_repeat(" *", 37) . "\n\n\n";
                }
                $flags = ReviewForm::UNPARSE_NO_TITLE;
                if ($this->no_send) {
                    $flags |= ReviewForm::UNPARSE_NO_AUTHOR_SEEN;
                }
                $text .= $rf->unparse_text($this->row, $rrow, $this->permuser, $flags);
            }
        }

        $this->permuser->set_overrides($old_overrides);
        return $text;
    }

    private function get_comments($tag) {
        $old_overrides = $this->permuser->overrides();
        if ($this->conf->_au_seerev === null) { /* assume sender wanted to override */
            $this->permuser->add_overrides(Contact::OVERRIDE_AU_SEEREV);
        }
        assert(($old_overrides & Contact::OVERRIDE_CONFLICT) === 0);

        if ($this->comment_row) {
            $crows = [$this->comment_row];
        } else {
            $crows = $this->row->all_comments();
        }

        $crows = array_filter($crows, function ($crow) use ($tag) {
            return (!$tag || $crow->has_tag($tag))
                && $this->permuser->can_view_comment($this->row, $crow);
        });

        $flags = ReviewForm::UNPARSE_NO_TITLE | ReviewForm::UNPARSE_TRUNCATE;
        if ($this->flowed) {
            $flags |= ReviewForm::UNPARSE_FLOWED;
        }
        $text = "";
        if (count($crows) > 1) {
            $text .= "Comments\n" . str_repeat("=", 75) . "\n";
        }
        foreach ($crows as $crow) {
            if ($text !== "") {
                $text .= "\n";
            }
            $text .= $crow->unparse_text($this->permuser, $flags);
        }

        $this->permuser->set_overrides($old_overrides);
        return $text;
    }

    const GA_SINCE = 1;
    const GA_ROUND = 2;
    const GA_NEEDS_SUBMIT = 4;

    /** @param Contact $user
     * @param int $flags
     * @param ?int $review_round
     * @return string */
    private function get_assignments($user, $flags, $review_round) {
        $where = [
            "r.contactId={$user->contactId}",
            "p.timeSubmitted>0"
        ];
        if (($flags & self::GA_SINCE) !== 0) {
            $where[] = "r.timeRequested>r.timeRequestNotified";
            if ($this->newrev_since) {
                $where[] = "r.timeRequested>={$this->newrev_since}";
            }
        }
        if (($flags & self::GA_NEEDS_SUBMIT) !== 0) {
            $where[] = "r.reviewSubmitted is null";
            $where[] = "r.reviewNeedsSubmit!=0";
        }
        if (($flags & self::GA_ROUND) !== 0 && $review_round !== null) {
            $where[] = "r.reviewRound={$review_round}";
        }
        $result = $this->conf->qe("select r.paperId, p.title
                from PaperReview r join Paper p using (paperId)
                where " . join(" and ", $where) . " order by r.paperId");
        $text = "";
        while (($row = $result->fetch_row())) {
            $text .= ($text ? "\n#" : "#") . $row[0] . " " . $row[1];
        }
        Dbl::free($result);
        return $text;
    }


    function infer_user_name($r, $contact) {
        // If user hasn't entered a name, try to infer it from author records
        if ($this->row && $this->row->paperId > 0) {
            $e1 = $contact->email ?? "";
            $e2 = $contact->preferredEmail ?? "";
            foreach ($this->row->author_list() as $au) {
                if (($au->firstName !== "" || $au->lastName !== "")
                    && $au->email !== ""
                    && (strcasecmp($au->email, $e1) === 0
                        || strcasecmp($au->email, $e2) === 0)) {
                    $r->firstName = $au->firstName;
                    $r->lastName = $au->lastName;
                    return;
                }
            }
        }
    }

    private function guess_reviewdeadline() {
        if ($this->rrow) {
            return $this->rrow->deadline_name();
        }
        if ($this->row
            && ($rrows = $this->row->reviews_by_user($this->recipient))) {
            $rrow0 = $rrow1 = null;
            foreach ($rrows as $rrow) {
                if (($dl = $rrow->deadline())) {
                    if (!$rrow0 || $rrow0->deadline() > $dl) {
                        $rrow0 = $rrow;
                    }
                    if ($rrow->reviewStatus < ReviewInfo::RS_DELIVERED
                        && (!$rrow1 || $rrow1->deadline() > $dl)) {
                        $rrow1 = $rrow;
                    }
                }
            }
            if ($rrow0 || $rrow1) {
                return ($rrow1 ?? $rrow0)->deadline_name();
            }
        }
        if ($this->recipient && $this->recipient->isPC) {
            $bestdl = $bestdln = null;
            foreach ($this->conf->defined_rounds() as $i => $round_name) {
                $dln = "pcrev_soft" . ($i ? "_{$i}" : "");
                if (($dl = $this->conf->setting($dln))) {
                    if (!$bestdl
                        || ($bestdl < Conf::$now
                            ? $dl < $bestdl || $dl >= Conf::$now
                            : $dl >= Conf::$now && $dl < $bestdl)) {
                        $bestdl = $dl;
                        $bestdln = $dln;
                    }
                }
            }
            return $bestdln;
        } else {
            return null;
        }
    }

    function kw_deadline($args, $isbool, $uf) {
        if ($uf->is_review && $args) {
            $args .= "rev_soft";
        } else if ($uf->is_review) {
            $args = $this->guess_reviewdeadline();
        }
        if ($isbool) {
            return $args && $this->conf->setting($args) > 0;
        } else if ($args) {
            $t = $this->conf->setting($args) ?? 0;
            return $this->conf->unparse_time_long($t);
        } else {
            return null;
        }
    }

    function kw_statistic($args, $isbool, $uf) {
        if ($this->_statistics === null) {
            $this->_statistics = $this->conf->count_submitted_accepted();
        }
        return $this->_statistics[$uf->statindex];
    }
    function kw_reviewercontact($args, $isbool, $uf) {
        if ($uf->match_data[1] === "REVIEWER") {
            if (($x = $this->_expand_reviewer($uf->match_data[2], $isbool)) !== false) {
                return $x;
            }
        } else if (($u = $this->contacts[strtolower($uf->match_data[1])])) {
            return $this->expand_user($u, $uf->match_data[2]);
        }
        return $isbool ? false : null;
    }

    function kw_assignments($args, $isbool, $uf) {
        $flags = 0;
        $round = null;
        if ($args || isset($uf->match_data)) {
            $rname = trim(isset($uf->match_data) ? $uf->match_data[1] : $args);
            $round = $this->conf->round_number($rname);
            if ($round === null) {
                return $isbool ? false : null;
            }
            $flags |= self::GA_ROUND;
        }
        return $this->get_assignments($this->recipient, $flags, $round);
    }
    function kw_newassignments() {
        return $this->get_assignments($this->recipient, self::GA_SINCE | self::GA_NEEDS_SUBMIT, null);
    }
    function kw_haspaper($uf = null, $name = null) {
        if ($this->row && $this->row->paperId > 0) {
            if ($this->preparation
                && $this->preparation instanceof HotCRPMailPreparation) {
                ++$this->preparation->paper_expansions;
            }
            return true;
        } else {
            $this->_unexpanded_paper_keyword = $name;
            return false;
        }
    }
    function kw_hasreview() {
        return !!$this->rrow;
    }

    function kw_title() {
        return $this->row->title;
    }
    function kw_titlehint() {
        if (($tw = UnicodeHelper::utf8_abbreviate($this->row->title, 40))) {
            return "\"{$tw}\"";
        } else {
            return "";
        }
    }
    function kw_abstract() {
        return $this->row->abstract();
    }
    function kw_pid() {
        return $this->row->paperId;
    }
    function kw_authors($args, $isbool) {
        if (!$this->permuser->is_root_user()
            && !$this->permuser->can_view_authors($this->row)
            && !$this->permuser->act_author_view($this->row)) {
            return $isbool ? false : "Hidden for anonymous review";
        }
        $t = [];
        foreach ($this->row->author_list() as $au) {
            $t[] = $au->name(NAME_P|NAME_A);
        }
        if ($this->line_prefix !== ""
            && preg_match('/\A([ *]*)(Author(?:|s|\(s\)))(:\s*)\z/', $this->line_prefix, $m)) {
            $auo = $this->conf->option_by_id(PaperOption::AUTHORSID);
            $ti = $auo->title(new FmtArg("count", count($t)));
            if ($m[1] !== ""
                && ctype_space($m[1])
                && ($delta = strlen($ti) - strlen($m[2])) !== 0) {
                $m[1] = str_repeat(" ", max(strlen($m[1]) - $delta, 0));
            }
            $this->line_prefix = "{$m[1]}{$ti}{$m[3]}";
        }
        return join(";\n", $t);
    }
    function kw_authorviewcapability($args, $isbool) {
        $this->sensitive = true;
        if ($this->conf->opt("disableCapabilities")
            || $this->censor === self::CENSOR_ALL) {
            return "";
        }
        if ($this->row
            && isset($this->row->capVersion)
            && $this->row->has_author($this->recipient)) {
            if (!$this->censor) {
                return "cap=" . AuthorView_Capability::make($this->row);
            } else if ($this->censor === self::CENSOR_DISPLAY) {
                return "cap=HIDDEN";
            }
        }
        return null;
    }
    function kw_decision($args, $isbool) {
        if ($this->row->outcome === 0 && $isbool) {
            return false;
        } else {
            return $this->row->decision()->name;
        }
    }
    function kw_tagvalue($args, $isbool, $uf) {
        $tag = isset($uf->match_data) ? $uf->match_data[1] : $args;
        $tag = $this->tagger()->check($tag, Tagger::NOVALUE | Tagger::NOPRIVATE);
        if (!$tag) {
            return null;
        }
        $value = $this->row->tag_value($tag);
        if ($isbool) {
            return $value !== null;
        } else if ($value !== null) {
            return (string) $value;
        } else {
            $this->warning_at($uf->input_string ?? null, "<0>Submission #{$this->row->paperId} has no #{$tag} tag");
            return "(none)";
        }
    }
    function kw_is_paperfield($uf) {
        $uf->option = $this->conf->options()->find($uf->match_data[1]);
        return !!$uf->option && $uf->option->on_render_context(FieldRender::CFMAIL);
    }
    function kw_paperfield($args, $isbool, $uf) {
        if (!$this->permuser->can_view_option($this->row, $uf->option)
            || !($ov = $this->row->option($uf->option))) {
            return $isbool ? false : "";
        } else {
            $fr = new FieldRender(FieldRender::CFTEXT | FieldRender::CFMAIL, $this->permuser);
            $uf->option->render($fr, $ov);
            if ($isbool) {
                return ($fr->value ?? "") !== "";
            } else {
                return (string) $fr->value;
            }
        }
    }
    function kw_paperpc($args, $isbool, $uf) {
        $k = "{$uf->pctype}ContactId";
        $cid = $this->row->$k;
        if ($cid > 0 && ($u = $this->conf->user_by_id($cid, USER_SLICE))) {
            return $this->expand_user($u, $uf->userx);
        } else if ($isbool)  {
            return false;
        } else if ($this->context === self::CONTEXT_EMAIL
                   || $uf->userx === "EMAIL") {
            return "<none>";
        } else {
            return "(no $uf->pctype assigned)";
        }
    }
    function kw_reviewname($args) {
        $s = $args === "SUBJECT";
        if ($this->rrow && $this->rrow->reviewOrdinal) {
            return ($s ? "review #" : "Review #") . $this->row->paperId . unparse_latin_ordinal($this->rrow->reviewOrdinal);
        } else {
            return ($s ? "review" : "A review");
        }
    }
    function kw_reviewid($args, $isbool) {
        if ($isbool && !$this->rrow) {
            return false;
        } else {
            return $this->rrow ? $this->rrow->reviewId : "";
        }
    }
    function kw_reviewacceptor() {
        if (!$this->rrow || $this->censor === self::CENSOR_ALL) {
            return null;
        }
        $this->sensitive = true;
        if ($this->censor) {
            return "HIDDEN";
        } else if (($tok = ReviewAccept_Capability::make($this->rrow, true))) {
            return $tok->salt;
        } else {
            return null;
        }
    }
    function kw_reviews() {
        return $this->get_reviews();
    }
    function kw_comments($args, $isbool) {
        $tag = null;
        if ($args === ""
            || ($tag = $this->tagger()->check($args, Tagger::NOVALUE))) {
            return $this->get_comments($tag);
        } else {
            return null;
        }
    }


    function handle_unexpanded_keyword($kw, $xref) {
        if ($kw === $this->_unexpanded_paper_keyword) {
            return "<0>Keyword not expanded because this mail isn’t linked to submissions or reviews";
        } else if (preg_match('/\AAUTHORVIEWCAPABILITY/', $kw)) {
            return "<0>Keyword not expanded because this mail isn’t meant for submission authors";
        } else {
            return parent::handle_unexpanded_keyword($kw, $xref);
        }
    }

    /** @return HotCRPMailPreparation */
    function prepare($template, $rest = []) {
        assert($this->recipient && $this->recipient->email);
        $prep = new HotCRPMailPreparation($this->conf, $this->recipient);
        if ($this->row && ($this->row->paperId ?? 0) > 0) {
            $prep->paperId = $this->row->paperId;
            $prep->author_recipient = $this->row->has_author($this->recipient);
        }
        $prep->combination_type = $this->combination_type;
        $this->populate_preparation($prep, $template, $rest);
        return $prep;
    }


    /** @param Contact $recipient
     * @param PaperInfo $prow
     * @param ?ReviewInfo $rrow
     * @return bool */
    static function check_can_view_review($recipient, $prow, $rrow) {
        assert(!($recipient->overrides() & Contact::OVERRIDE_CONFLICT));
        return $recipient->can_view_review($prow, $rrow);
    }

    /** @param Contact $recipient
     * @return ?HotCRPMailPreparation */
    static function prepare_to($recipient, $template, $rest = []) {
        if ($recipient->is_dormant()) {
            return null;
        }
        $old_overrides = $recipient->remove_overrides(Contact::OVERRIDE_CONFLICT);
        $mailer = new HotCRPMailer($recipient->conf, $recipient, $rest);
        $answer = $mailer->prepare($template, $rest);
        $recipient->set_overrides($old_overrides);
        return $answer;
    }

    /** @param Contact $recipient
     * @return bool */
    static function send_to($recipient, $template, $rest = []) {
        if (($prep = self::prepare_to($recipient, $template, $rest))) {
            $prep->send();
        }
        return !!$prep;
    }

    /** @param string $template
     * @param PaperInfo $row
     * @return bool */
    static function send_contacts($template, $row, $rest = []) {
        $preps = $aunames = [];
        $rest["prow"] = $row;
        $rest["combination_type"] = 1;
        $rest["author_permission"] = true;
        foreach ($row->contact_followers() as $minic) {
            assert(empty($minic->review_tokens()));
            if (($p = self::prepare_to($minic, $template, $rest))) {
                $preps[] = $p;
                $aunames[] = $minic->name_h(NAME_EB);
            }
        }
        self::send_combined_preparations($preps);
        if (!empty($aunames) && ($user = $rest["confirm_message_for"] ?? null)) {
            '@phan-var-force Contact $user';
            if ($user->allow_view_authors($row)) {
                $m = $row->conf->_("<5>Notified {submission} contacts {:nblist}", $aunames);
            } else {
                $m = $row->conf->_("<0>Notified {submission} contact(s)");
            }
            $row->conf->success_msg($m);
        }
        return !empty($aunames);
    }

    /** @param PaperInfo $row */
    static function send_administrators($template, $row, $rest = []) {
        $preps = [];
        $rest["prow"] = $row;
        $rest["combination_type"] = 1;
        foreach ($row->administrators() as $u) {
            if (($p = self::prepare_to($u, $template, $rest))) {
                $preps[] = $p;
            }
        }
        self::send_combined_preparations($preps);
    }
}
