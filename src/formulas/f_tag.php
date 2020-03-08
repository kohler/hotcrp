<?php
// formulas/f_tag.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2020 Eddie Kohler; see LICENSE.

class Tag_Fexpr extends Sub_Fexpr {
    private $tag;
    private $isvalue;
    function __construct($tag, $isvalue) {
        $this->tag = $tag;
        $this->isvalue = $isvalue;
        $this->format_ = $isvalue ? null : self::FBOOL;
    }
    static function parse_modifier(FormulaCall $ff, $arg) {
        if (!$ff->args && $arg[0] !== ".") {
            $ff->args[] = substr($arg, 1);
            return true;
        } else if (count($ff->args) === 1 && $arg[0] === ":") {
            $ff->args[0] .= $arg;
            return true;
        } else {
            return false;
        }
    }
    static function make(FormulaCall $ff) {
        if (count($ff->args) === 1
            && preg_match('/\A' . TAG_REGEX . '\z/', $ff->args[0])) {
            return new Tag_Fexpr($ff->args[0], $ff->kwdef->is_value);
        } else {
            return $ff->lerror("Invalid tag.");
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
    function view_score(Contact $user) {
        $tagger = new Tagger($user);
        $e_tag = $tagger->check($this->tag, Tagger::ALLOWSTAR);
        return $tagger->view_score($e_tag, $user);
    }
    function tag() {
        return $this->tag;
    }
    function compile(FormulaCompiler $state) {
        $e_tag = $state->tagger->check($this->tag, Tagger::ALLOWSTAR);
        if ($e_tag === false) {
            return "false";
        } else if (strpos($this->tag, "*") === false) {
            return "Tag_Fexpr::tag_value(" . $state->_add_tags()
                . "," . json_encode(" $this->tag#")
                . "," . json_encode($this->isvalue) . ")";
        } else {
            $re = "{ " . str_replace('\*', '[^#\s]*', preg_quote($this->tag)) . '#}i';
            return "Tag_Fexpr::tag_regex_value(" . $state->_add_tags()
                . "," . json_encode($re)
                . "," . json_encode($this->isvalue) . ")";
        }
    }
}
