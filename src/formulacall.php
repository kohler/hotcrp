<?php
// formulacall.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2025 Eddie Kohler; see LICENSE.

class FormulaCall {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var FormulaParser
     * @readonly */
    public $parser;
    /** @var Formula
     * @readonly */
    public $formula;
    /** @var string */
    public $name;
    /** @var string */
    public $text;
    /** @var list<Fexpr> */
    public $args = [];
    /** @var list<string> */
    public $rawargs = [];
    /** @var ?int */
    public $index_type;
    /** @var ?mixed */
    public $modifier;
    /** @var object */
    public $kwdef;
    /** @var int */
    public $pos1;
    /** @var int */
    public $pos2;

    function __construct(FormulaParser $parser, $kwdef, $name) {
        $this->conf = $parser->formula->conf;
        $this->user = $parser->formula->user;
        $this->parser = $parser;
        $this->formula = $parser->formula;
        $this->name = $kwdef ? $kwdef->name : "";
        $this->text = $name;
        $this->kwdef = $kwdef;
    }

    /** @param list<Fexpr> $args
     * @return FormulaCall */
    static function make_args(FormulaParser $parser, $name, $args) {
        $fc = new FormulaCall($parser, null, $name);
        $fc->args = $args;
        foreach ($args as $a) {
            $fc->pos1 = min($fc->pos1 ?? $a->pos1, $a->pos1);
            $fc->pos2 = max($fc->pos2 ?? $a->pos2, $a->pos2);
        }
        return $fc;
    }

    /** @param string $message
     * @return MessageItem */
    function lerror($message) {
        return $this->parser->lerror($this->pos1, $this->pos2, $message);
    }

    /** @param mixed $args
     * @return bool */
    function check_nargs($args) {
        foreach ($this->args as $a) {
            if ($a->format() === Fexpr::FERROR)
                return false;
        }
        if (is_int($args)) {
            $args = [$args, $args];
        }
        if (!is_array($args)) {
            return true;
        }
        return $this->check_nargs_range(count($this->args), $args[0], $args[1]);
    }

    /** @param int $nargs
     * @param int $min
     * @param int $max
     * @return bool */
    function check_nargs_range($nargs, $min, $max) {
        if ($nargs < $min) {
            $note = $min < $max ? "at least " : "";
            $this->lerror("<0>Too few arguments to function ‘{$this->name}’ (expected {$note}{$min})");
            return false;
        } else if ($nargs > $max) {
            $note = $min < $max ? "{$min}–{$max}" : $max;
            $this->lerror("<0>Too many arguments to function ‘{$this->name}’ (expected {$note})");
            return false;
        }
        return true;
    }
}
