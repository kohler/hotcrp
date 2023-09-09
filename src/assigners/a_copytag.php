<?php
// a_copytag.php -- HotCRP assignment helper classes
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class CopyTag_AssignmentParser extends UserlessAssignmentParser {
    /** @var bool */
    private $move;
    function __construct(Conf $conf, $aj) {
        parent::__construct("copytag");
        $this->move = $aj->move ?? false;
    }
    function load_state(AssignmentState $state) {
        Tag_Assignable::load($state);
        TagAnno_Assignable::load($state);
    }
    function paper_universe($req, AssignmentState $state) {
        return "reqpost";
    }
    function allow_paper(PaperInfo $prow, AssignmentState $state) {
        if ($prow->paperId > 0
            && ($whyNot = $state->user->perm_edit_some_tag($prow))) {
            return new AssignmentError($whyNot);
        } else {
            return true;
        }
    }
    private function cannot_view_error(PaperInfo $prow, $tag, AssignmentState $state) {
        if ($prow->has_conflict($state->user)) {
            $state->paper_error("<0>You have a conflict with #{$prow->paperId}");
        } else {
            $state->paper_error("<0>You can’t view that tag for #{$prow->paperId}");
        }
        return false;
    }
    function apply(PaperInfo $prow, Contact $contact, $req, AssignmentState $state) {
        // tag arguments
        $tagger = new Tagger($contact);
        $tag = $tagger->check($req["tag"] ?? "", Tagger::NOVALUE);
        if (!$tag) {
            $state->error($tagger->error_ftext(true));
            return false;
        }
        $ltag = strtolower($tag);
        $new_tag = $tagger->check($req["new_tag"] ?? "", Tagger::NOVALUE);
        if (!$new_tag) {
            if ($tagger->error_code() === Tagger::EEMPTY) {
                $state->error("<0>New tag required");
            } else {
                $state->error($tagger->error_ftext(true));
            }
            return false;
        }

        // if you can't view the tag, you can't copy or move the tag
        if ($prow->paperId > 0
            && !$state->user->can_view_tag($prow, $tag)) {
            return Tag_AssignmentParser::cannot_view_error($prow, $tag, $state);
        }

        // ignore attempts to change vote & automatic tags
        $tagmap = $state->conf->tags();
        if (!$state->conf->is_updating_automatic_tags()
            && $tagmap->is_automatic($new_tag)) {
            return true;
        } else if ($this->move && $tagmap->is_automatic($tag)) {
            $state->error("<0>Cannot rename automatic tags");
            return false;
        }

        // real paper: change/move tag
        if ($prow->paperId > 0) {
            $res = $state->query(new Tag_Assignable($prow->paperId, $ltag));
            if (!$res) {
                return true;
            }
            assert(count($res) === 1);

            $lnew_tag = strtolower($new_tag);
            $state->add(new Tag_Assignable($prow->paperId, $lnew_tag, $new_tag, $res[0]->_index));
            if ($this->move) {
                $state->remove($res[0]);
            }
        }

        // on placeholder: change/move tag annotations
        if ($prow->paperId < 0
            && $state->user->can_edit_tag_anno($tag)
            && $state->user->can_edit_tag_anno($new_tag)
            && ($ares = $state->query(new TagAnno_Assignable($tag, null)))) {
            if (!$state->query(new TagAnno_Assignable($new_tag, null))) {
                foreach ($ares as $taa) {
                    $state->add($taa->with_tag($new_tag));
                }
            }
            if ($this->move
                && !$state->query(new Tag_Assignable(null, $ltag))) {
                foreach ($ares as $taa) {
                    $x = $state->remove($taa);
                }
            }
        }

        return true;
    }
}
