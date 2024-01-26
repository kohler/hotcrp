<?php
// formulamachine.php -- HotCRP helper class for formula expressions
// Copyright (c) 2009-2024 Eddie Kohler; see LICENSE.

class FormulaMachineAnalyzeScope {
    /** @var int */
    public $blocktype;
    /** @var int */
    public $blockid;
    /** @var list */
    public $fixes = [];
    /** @var ?int */
    public $elsefix;
    /** @var ?int */
    public $brpos;
}

class FormulaMachine {
    /** @var list */
    public $insn;

    const IBLOCK = 100;
    const ILOOP = 101;
    const IIF = 102;
    const IELSE = 103;
    const IEND = 104;

    const IOVER = 110;
    const IENDOVER = 111;

    const IBR = 120;
    const IBR_IF = 121;
    const IBR_IFND = 122;


    const ICONST = 0;
    const IVALUE = 2;
    const ICMP = 4;
    const IDROP = 6;
    const IBIN = 12;
    const INEXT = 16;

    const IXBR = 20;
    const IXBR_IF = 21;
    const IXBR_IFND = 22;
    const IXBR_IFNOT = 23;

    static public $opname = [
        self::ICONST => "const", self::IVALUE => "value", self::ICMP => "cmp",
        self::IDROP => "drop", self::IBIN => "bin", self::INEXT => "next",
        self::IOVER => "over" , self::IENDOVER => "endover",
        self::IXBR => "xbr", self::IXBR_IF => "xbr_if",
        self::IXBR_IFND => "xbr_ifnd", self::IXBR_IFNOT => "xbr_ifnot"
    ];

    // (#r2.manual or (count(OveMer>=0)<3) or (count(OveMer>=3)>=2) or (count(OveMer>=4)>=1) )

    static function make_formula(FormulaState $fst) {
        $tag = $fst->add(new SingleTag_FormulaValue("r2.manual", false));
        $ovemer = $fst->ensure_rf($fst->user->conf->find_review_field("OveMer"));

        $fm = new FormulaMachine;

        $fm->insn = [
            self::IBLOCK, 0,
            self::IVALUE, $tag,
            self::IBR_IFND, 0,
            self::IDROP, null,

            self::ICONST, 0,
            self::IOVER, 1,
            self::INEXT, 0,
            self::IIF, 2,
            self::IVALUE, $ovemer,
            self::ICONST, 0,
            self::ICMP, 6,
            self::IIF, 3,
            self::ICONST, 1,
            self::IBIN, 0, // +
            self::IEND, 3,
            self::IBR, 1,
            self::IEND, 2,
            self::IEND, 1,

            self::ICONST, 3,
            self::ICMP, 1, // <
            self::IBR_IFND, 0,
            self::IDROP, null,

            self::ICONST, 0,
            self::IOVER, 4,
            self::INEXT, 0,
            self::IIF, 5,
            self::IVALUE, $ovemer,
            self::ICONST, 3,
            self::ICMP, 6,
            self::IIF, 6,
            self::ICONST, 1,
            self::IBIN, 0, // +
            self::IEND, 6,
            self::IBR, 1,
            self::IEND, 5,
            self::IEND, 4,

            self::ICONST, 2,
            self::ICMP, 6,
            self::IBR_IFND, 0,
            self::IDROP, null,

            self::ICONST, 0,
            self::IOVER, 7,
            self::INEXT, 0,
            self::IIF, 8,
            self::IVALUE, $ovemer,
            self::ICONST, 4,
            self::ICMP, 6,
            self::IIF, 9,
            self::ICONST, 1,
            self::IBIN, 0, // +
            self::IEND, 9,
            self::IBR, 1,
            self::IEND, 8,
            self::IEND, 7,

            self::ICONST, 1,
            self::ICMP, 6,
            self::IEND, 0
        ];

        $fm->analyze();

        return $fm;
    }

    function analyze() {
        $insn2 = [];
        $blocks = [];
        $stack = [];

        $n = count($this->insn);
        for ($i = 0; $i !== $n; $i += 2) {
            $op = $this->insn[$i];
            $v = $this->insn[$i + 1];
            if ($op === self::IEND) {
                $scope = array_shift($stack);
                if (!$scope) {
                    throw new ErrorException("END without open scope");
                } else if ($scope->blockid !== $v) {
                    throw new ErrorException("END wrong scope (got {$v}, expected {$scope->blockid})");
                }
                foreach ($scope->fixes as $pos) {
                    $insn2[$pos] = count($insn2);
                }
                if ($scope->elsefix !== null) {
                    $insn2[$scope->elsefix] = count($insn2);
                }
                if ($scope->blocktype === self::IOVER) {
                    $insn2[] = self::IENDOVER;
                    $insn2[] = null;
                }

            } else if ($op === self::IBLOCK
                       || $op === self::ILOOP
                       || $op === self::IOVER
                       || $op === self::IIF) {
                if (isset($blocks[$v])) {
                    throw new ErrorException("scope ID reused ({$v})");
                }
                $scope = new FormulaMachineAnalyzeScope;
                $scope->blocktype = $op;
                $scope->blockid = $v;
                array_unshift($stack, $scope);
                $blocks[$v] = 1;
                if ($op === self::IOVER) {
                    $insn2[] = self::IOVER;
                    $insn2[] = null;
                }
                if ($op === self::ILOOP || $op === self::IOVER) {
                    $scope->brpos = count($insn2);
                } else if ($op === self::IIF) {
                    $insn2[] = self::IXBR_IFNOT;
                    $insn2[] = null;
                    $scope->elsefix = count($insn2) - 1;
                }

            } else if ($op === self::IELSE) {
                $scope = $stack[0] ?? null;
                if (!$scope || $scope->blockid !== $v || $scope->elsefix === null) {
                    throw new ErrorException("unexpected ELSE");
                }
                $insn2[] = self::IXBR;
                $insn2[] = null;
                $scope->fixes[] = count($insn2) - 1;
                $insn2[$scope->elsefix] = count($insn2);
                $scope->elsefix = null;

            } else if ($op === self::IBR
                       || $op === self::IBR_IFND
                       || $op === self::IBR_IF) {
                $scope = $stack[$v] ?? null;
                if (!$scope) {
                    throw new ErrorException("bad BR @{$i}");
                }
                $insn2[] = $op - 100;
                $insn2[] = $scope->brpos;
                if ($scope->brpos === null) {
                    $scope->fixes[] = count($insn2) - 1;
                }

            } else {
                $insn2[] = $op;
                $insn2[] = $v;
            }
        }

        if (!empty($stack)) {
            throw new ErrorException("nonempty stack");
        }
        $this->insn = $insn2;
    }

    function execute(FormulaState $fst, PaperInfo $prow) {
        $fst->reset($prow);
        $insn = $this->insn;
        $ninsn = count($insn);
        $s = [];
        $ns = 0;
        for ($i = 0; $i !== $ninsn; $i += 2) {
            $op = $insn[$i];
            $v = $insn[$i + 1];
            //error_log("! @{$i}/" . (self::$opname[$op] ?? $op) . "." . json_encode($v) . " : " . json_encode(array_slice($s, 0, $ns)));
            if ($op === self::ICONST) {
                $s[$ns++] = $v;
            } else if ($op === self::IVALUE) {
                $s[$ns++] = $v->value($fst);
            } else if ($op === self::ICMP) {
                --$ns;
                $d = $s[$ns - 1] - $s[$ns];
                if ($d >= 0.000001) {
                    $dx = 4;
                } else if ($d >= -0.000001) {
                    $dx = 2;
                } else {
                    $dx = 1;
                }
                $s[$ns - 1] = ($dx & $v) !== 0;
            } else if ($op === self::IDROP) {
                --$ns;
            } else if ($op === self::IBIN) {
                --$ns;
                $s[$ns - 1] = $s[$ns - 1] + $s[$ns];
            } else if ($op === self::INEXT) {
                $s[$ns++] = $fst->each();
            } else if ($op === self::IXBR) {
                $i = $v - 2;
            } else if ($op === self::IXBR_IF) {
                --$ns;
                if ($s[$ns]) {
                    $i = $v - 2;
                }
            } else if ($op === self::IXBR_IFND) {
                if ($s[$ns - 1]) {
                    $i = $v - 2;
                }
            } else if ($op === self::IXBR_IFNOT) {
                --$ns;
                if (!$s[$ns]) {
                    $i = $v - 2;
                }
            } else if ($op === self::IOVER) {
                $fst->push_loop(Fexpr::IDX_REVIEW);
            } else if ($op === self::IENDOVER) {
                $fst->pop_loop(Fexpr::IDX_REVIEW);
            } else {
                throw new ErrorException("");
            }
        }
        //error_log("! @{$i}/COMPLETE : " . json_encode(array_slice($s, 0, $ns)) . "\n");
        return $s[$ns - 1];
    }
}
