<?php
// formulas/f_reviewermatch.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class ReviewerMatch_Fexpr extends Fexpr {
    /** @var Contact */
    private $user;
    /** @var string */
    private $arg;
    /** @var int */
    private $flags;
    /** @var bool */
    private $istag;
    /** @var ContactSearch */
    private $csearch;
    function __construct(Contact $user, $arg) {
        parent::__construct("reviewermatch");
        $this->user = $user;
        $this->set_format(Fexpr::FBOOL);
        $this->arg = $arg;
        $this->istag = $arg[0] === "#" || ($arg[0] !== "\"" && $user->conf->pc_tag_exists($arg));
        $flags = ContactSearch::F_USER;
        if ($user->can_view_user_tags()) {
            $flags |= ContactSearch::F_TAG;
        }
        if ($arg[0] === "\"") {
            $flags |= ContactSearch::F_QUOTED;
            $arg = str_replace("\"", "", $arg);
        }
        $this->csearch = new ContactSearch($flags, $arg, $user);
    }
    function inferred_index() {
        return self::IDX_REVIEW;
    }
    function viewable_by(Contact $user) {
        return $user->can_view_some_review_identity();
    }
    function compile(FormulaCompiler $state) {
        assert($state->user === $this->user);
        // NB the following case also catches attempts to view a non-viewable
        // user tag (the csearch will return nothing).
        if ($this->csearch->is_empty()) {
            return "null";
        }
        $state->queryOptions["reviewSignatures"] = true;
        return '(' . $state->_prow() . '->can_view_review_identity_of(' . $state->loop_cid() . ', $contact) ? array_search(' . $state->loop_cid() . ", [" . join(", ", $this->csearch->user_ids()) . "]) !== false : null)";
    }
    function matches_at_most_once() {
        return count($this->csearch->user_ids()) <= 1;
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $x = parent::jsonSerialize();
        $x["match"] = $this->arg;
        if ($this->csearch->is_empty()) {
            $x["empty"] = true;
        }
        return $x;
    }
}
