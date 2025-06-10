<?php
// a_copytag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class CopyTag_AssignmentParser extends UserlessAssignmentParser {
    /** @var bool */
    private $move;
    /** @var string */
    private $tag;
    /** @var string */
    private $new_tag;
    /** @var ?string */
    private $pattern;
    /** @var int|false */
    private $new_star;
    /** @var string */
    private $value;
    /** @var Tagger */
    private $tagger;

    function __construct(Conf $conf, $aj) {
        parent::__construct("copytag");
        $this->move = $aj->move ?? false;
    }
    function load_state(AssignmentState $state) {
        Tag_Assignable::load($state);
        TagAnno_Assignable::load($state);
    }
    function paper_universe($req, AssignmentState $state) {
        $anno = $req["tag_anno"] ?? null;
        if (friendly_boolean($anno) === false) {
            return "req";
        }
        return "reqpost";
    }
    function set_req($req, AssignmentState $state) {
        // parse tags
        $this->tagger = new Tagger($state->user);
        $this->tag = $this->tagger->check($req["tag"] ?? "", Tagger::NOVALUE | Tagger::ALLOWSTAR | Tagger::ALLOWCONTACTID);
        if (!$this->tag) {
            $state->error($this->tagger->error_ftext(true));
            return false;
        }

        $this->new_tag = $this->tagger->check($req["new_tag"] ?? "", Tagger::NOVALUE | Tagger::ALLOWSTAR | Tagger::ALLOWCONTACTID);
        if (!$this->new_tag) {
            if ($this->tagger->error_code() === Tagger::EEMPTY) {
                $state->error("<0>New tag required");
            } else {
                $state->error($this->tagger->error_ftext(true));
            }
            return false;
        }

        // check pattern
        $star = strpos($this->tag, "*");
        if ($star !== false && strpos($this->tag, "*", $star + 1) !== false) {
            $state->error("<0>‘{$this->tag}’: At most one wildcard allowed");
            return false;
        }
        $new_star = strpos($this->new_tag, "*");
        if ($new_star !== false && strpos($this->new_tag, "*", $new_star + 1) !== false) {
            $state->error("<0>‘{$this->new_tag}’: At most one wildcard allowed");
            return false;
        }
        if (($star === false) !== ($new_star === false)) {
            $state->error("<0>Wildcards in tag and new tag must match");
            return false;
        }
        if ($star === false) {
            $this->pattern = null;
        } else if ($star === 0) {
            $this->pattern = "{\\A((?![~\\d]).*)"
                . preg_quote(substr($this->tag, 1)) . "\\z}i";
        } else {
            $this->pattern = "{\\A" . preg_quote(substr($this->tag, 0, $star))
                . "(.*)" . preg_quote(substr($this->tag, $star + 1)) . "\\z}i";
        }
        $this->new_star = $new_star;

        // check value
        $value = trim((string) $req["tag_value"]);
        if ($value === "" || $value === "old") {
            $this->value = "old";
        } else if (in_array($value, ["new", "min", "max", "sum"], true)) {
            $this->value = $value;
        } else {
            $state->error("<0>Tag value should be ‘old’, ‘new’, ‘min’, ‘max’, or ‘sum’");
            return false;
        }

        // ignore attempts to change automatic tags
        if ($this->pattern === null) {
            $tagmap = $state->conf->tags();
            if ($this->move && $tagmap->is_automatic($this->tag)) {
                $state->error("<0>‘{$this->tag}’: Cannot modify automatic tag");
                return false;
            }
            if ($tagmap->is_automatic($this->new_tag)) {
                $state->error("<0>‘{$this->new_tag}’: Cannot modify automatic tag");
                return false;
            }
        }

        return true;
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($prow->paperId > 0
            && ($whyNot = $state->user->perm_edit_some_tag($prow))) {
            return new AssignmentError($whyNot);
        }
        return true;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        if ($this->pattern === null) {
            if ($prow->paperId < 0) {
                return $this->apply_tag_anno($this->tag, $this->new_tag, $state);
            }
            return $this->apply_tag($this->tag, $this->new_tag, $prow, $state);
        }
        $ok = true;
        if ($prow->paperId < 0) {
            $res = $state->query(new TagAnno_Assignable(null, null));
        } else {
            $res = $state->query(new Tag_Assignable($prow->paperId, null));
        }
        $tagmap = $state->conf->tags();
        foreach ($res as $x) {
            if (!preg_match($this->pattern, $x->ltag, $m)) {
                continue;
            }
            $new_tag = substr_replace($this->new_tag, $m[1], $this->new_star, 1);
            if ($tagmap->is_automatic($new_tag)) {
                continue;
            }
            if (!$this->tagger->check($new_tag, Tagger::NOVALUE | Tagger::ALLOWCONTACTID)) {
                $state->error($this->tagger->error_ftext(true));
                $ok = false;
                continue;
            }
            if ($prow->paperId < 0) {
                $ok = $this->apply_tag_anno($x->_tag, $new_tag, $state) && $ok;
            } else {
                $ok = $this->apply_tag($x->_tag, $new_tag, $prow, $state) && $ok;
            }
        }
        return $ok;
    }
    private function apply_tag($tag, $new_tag, PaperInfo $prow, AssignmentState $state) {
        if (!$state->user->can_view_tag($prow, $tag)) {
            return Tag_AssignmentParser::cannot_view_error($prow, $tag, $state);
        }
        if (strcasecmp($tag, $new_tag) === 0) {
            return true;
        }

        $res = $state->query(new Tag_Assignable($prow->paperId, strtolower($tag)));
        assert(count($res) <= 1);
        $value = empty($res) ? null : $res[0]->_index;
        if ($value === null) {
            return true;
        }

        $new_ltag = strtolower($new_tag);
        if ($this->value !== "old") {
            $new_res = $state->query(new Tag_Assignable($prow->paperId, $new_ltag));
            $new_value = empty($new_res) ? null : $new_res[0]->_index;
            if ($new_value !== null) {
                if ($this->value === "new") {
                    $value = $new_value;
                } else if ($this->value === "min") {
                    $value = min($value, $new_value);
                } else if ($this->value === "max") {
                    $value = max($value, $new_value);
                } else {
                    $value = min(max($value + $new_value, -TAG_INDEXBOUND + 1), TAG_INDEXBOUND - 1);
                }
            }
        }

        $state->add(new Tag_Assignable($prow->paperId, $new_ltag, $new_tag, $value));
        if ($this->move
            && ($this->pattern === null
                || !$state->conf->tags()->is_automatic($res[0]->_tag))) {
            $state->remove($res[0]);
        }
        return true;
    }
    private function apply_tag_anno($tag, $new_tag, AssignmentState $state) {
        if (!$state->user->can_edit_tag_anno($tag)
            || !$state->user->can_edit_tag_anno($new_tag)
            || strcasecmp($tag, $new_tag) === 0) {
            return true;
        }
        $ares = $state->query(new TagAnno_Assignable($tag, null));
        if (empty($ares)) {
            return true;
        }
        if (!$state->query(new TagAnno_Assignable($new_tag, null))) {
            foreach ($ares as $taa) {
                $state->add($taa->with_tag($new_tag));
            }
        }
        if ($this->move
            && ($this->pattern === null
                || !$state->conf->tags()->is_automatic($tag))) {
            foreach ($ares as $taa) {
                $x = $state->remove($taa);
            }
        }
        return true;
    }
}
