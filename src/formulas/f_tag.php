<?php
// formulas/f_tag.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2022 Eddie Kohler; see LICENSE.

class Tag_Fexpr extends Fexpr {
    private $tag;
    private $tsm;
    private $isvalue;
    function __construct($tag, TagSearchMatcher $tsm, $isvalue) {
        parent::__construct("tag");
        $this->tag = $tag;
        $this->tsm = $tsm;
        $this->isvalue = $isvalue;
        $this->set_format($isvalue ? Fexpr::FNUMERIC : Fexpr::FTAGVALUE);
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!$ff->rawargs && $arg[0] !== ".") {
            $ff->rawargs[] = substr($arg, 1);
            return true;
        } else if (count($ff->rawargs) === 1 && $arg[0] === ":") {
            $ff->rawargs[0] .= $arg;
            return true;
        } else {
            return false;
        }
    }
    static function make(FormulaCall $ff) {
        if (count($ff->rawargs) === 1
            && preg_match('{\A#?(?:|~~?|\S+~)' . TAG_REGEX_NOTWIDDLE . '\z}', $ff->rawargs[0])) {
            $tag = $ff->rawargs[0];
            $tsm = new TagSearchMatcher($ff->formula->user);
            $tsm->add_check_tag(str_starts_with($tag, "_~") ? substr($tag, 1) : $tag, true);
            return new Tag_Fexpr($tag, $tsm, $ff->kwdef->is_value);
        } else {
            $ff->lerror("<0>Invalid tag ‘{$ff->rawargs[0]}’");
            return null;
        }
    }
    static function tag_value($tags, $search, $isvalue) {
        $p = stripos($tags, $search);
        if ($p === false) {
            return false;
        } else {
            $value = (float) substr($tags, $p + strlen($search));
            return $isvalue || $value !== (float) 0 ? $value : true;
        }
    }
    static function tag_regex_value($tags, $search, $isvalue) {
        $p = preg_matchpos($search, $tags);
        if ($p === false) {
            return false;
        } else {
            $hash = strpos($tags, "#", $p);
            $value = (float) substr($tags, $hash);
            return $isvalue || $value !== (float) 0 ? $value : true;
        }
    }
    function inferred_index() {
        if (str_starts_with($this->tag, "_~")) {
            return Fexpr::IDX_PC;
        } else {
            return 0;
        }
    }
    function viewable_by(Contact $user) {
        return $user->isPC;
    }
    function compile(FormulaCompiler $state) {
        $tags = $state->_add_tags();
        $jvalue = json_encode($this->isvalue);
        if (($tag = $this->tsm->single_tag())) {
            if (str_starts_with($this->tag, "_~")) {
                $str = "\" \"." . $state->loop_cid() . "."
                    . json_encode(substr($tag, strpos($tag, "~")) . "#");
            } else {
                $str = json_encode(" {$tag}#");
            }
            return "Tag_Fexpr::tag_value($tags,$str,$jvalue)";
        } else {
            $regex = $this->tsm->regex();
            if (str_starts_with($this->tag, "_~")) {
                assert(strpos($regex, "|") === false
                       && str_starts_with($regex, "{ {$state->user->contactId}~"));
                $regex = "\"{ \"." . $state->loop_cid() . "."
                    . json_encode(substr($regex, strlen((string) $state->user->contactId) + 2));
            } else {
                $regex = json_encode($regex);
            }
            return "Tag_Fexpr::tag_regex_value($tags,$regex,$jvalue)";
        }
    }
}
