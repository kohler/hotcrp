<?php
// formulas/f_tag.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class Tag_Fexpr extends Fexpr {
    /** @var string */
    private $tag;
    /** @var TagSearchMatcher */
    private $tsm;
    /** @var bool */
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
        }
        return false;
    }
    static function make(FormulaCall $ff) {
        if (!$ff->check_nargs_range(count($ff->rawargs), 1, 1)) {
            return null;
        }
        if (!preg_match('{\A\(?#?((?:|~~?|\S+~)' . TAG_REGEX_NOTWIDDLE . ')\)?\z}', $ff->rawargs[0], $m)) {
            $ff->lerror("<0>Invalid tag ‘{$ff->rawargs[0]}’");
            return null;
        }
        if (!$ff->user->can_view_tags()) {
            return Fexpr::cnever();
        }
        $tag = $m[1];
        $pc_indexed = str_starts_with($tag, "_~");
        $tsm = new TagSearchMatcher($ff->user);
        $tsm->add_check_tag($pc_indexed ? substr($tag, 1) : $tag, true);
        if (!$tsm->single_tag()
            && $ff->kwdef->is_value) {
            $ff->lerror("<0>Tag values are meaningful only for single tags");
            return null;
        }
        if (!$pc_indexed
            && $ff->conf->is_updating_automatic_tags()
            && ($stag = $tsm->single_tag())
            && ($ti = $ff->conf->tags()->find($stag))
            && $ti->is(TagInfo::TF_AUTOSEARCH)) {
            return self::make_expand_automatic($ff, $ti, $ff->kwdef->is_value);
        }
        return new Tag_Fexpr($tag, $tsm, $ff->kwdef->is_value);
    }
    static function make_expand_automatic(FormulaCall $ff, TagInfo $ti, $isvalue) {
        $recursion = FormulaParser::set_current_recursion($ff->parser->recursion + 1);
        $st = $ti->automatic_search_term();
        $parser = $ff->parser->make_nested($ti->automatic_formula_expression(), null, $ff->pos1, $ff->pos2);
        $vfe = $parser->parse();
        FormulaParser::set_current_recursion($recursion);

        if ($st->get_float("circular_reference")) {
            $ff->lerror("<0>Circular reference in automatic tag #{$ti->tag}");
            $ff->formula->lerrors[] = MessageItem::error_at("circular_reference");
            return Fexpr::cerror();
        }

        $sfe = new Search_Fexpr($ff->formula, $st);
        if (!$isvalue) {
            $vfe = new Or_Fexpr($vfe, Fexpr::ctrue());
        }
        return new And_Fexpr($sfe, $vfe);
    }
    static function tag_value($tags, $search, $isvalue) {
        $p = stripos($tags, $search);
        if ($p === false) {
            return false;
        }
        $value = (float) substr($tags, $p + strlen($search));
        return $isvalue || $value !== (float) 0 ? $value : true;
    }
    static function tag_regex_value($tags, $search, $isvalue) {
        $p = preg_matchpos($search, $tags);
        return $p !== false;
    }
    /** @return string */
    function tag() {
        return $this->tag;
    }
    function inferred_index() {
        if (str_starts_with($this->tag, "_~")) {
            return Fexpr::IDX_PC;
        }
        return 0;
    }
    function compile(FormulaCompiler $state) {
        $tag = $this->tsm->single_tag();
        if (!$tag) {
            return $this->_compile_complex($state);
        }
        if (str_starts_with($this->tag, "_~")) {
            $str = "\" \"." . $state->loop_cid() . "."
                . json_encode(substr($tag, strpos($tag, "~")) . "#");
        } else {
            $str = json_encode(" {$tag}#");
        }
        $tags = $state->_add_tags();
        $jvalue = json_encode($this->isvalue);
        return "Tag_Fexpr::tag_value({$tags},{$str},{$jvalue})";
    }
    function _compile_complex(FormulaCompiler $state) {
        error_log("Tag_Fexpr::_compile_complex deprecated at {$this->tag}");
        $regex = $this->tsm->regex();
        if (str_starts_with($this->tag, "_~")) {
            assert(strpos($regex, "|") === false
                   && str_starts_with($regex, "{ {$state->user->contactId}~"));
            $regex = "\"{ \"." . $state->loop_cid() . "."
                . json_encode(substr($regex, strlen((string) $state->user->contactId) + 2));
        } else {
            $regex = json_encode($regex);
        }
        $tags = $state->_add_tags();
        $jvalue = json_encode($this->isvalue);
        return "Tag_Fexpr::tag_regex_value({$tags},{$regex},{$jvalue})";
    }
    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return ["op" => "tag", "tag" => $this->tag];
    }
}
