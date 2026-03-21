<?php
// custombanners.php -- HotCRP configurable page banners
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class CustomBannerParam {
    /** @var string */
    public $name;
    /** @var ?PaperSearch */
    public $srch;
    /** @var ?Formula */
    public $formula;

    /** @param array<string,object> $by_name
     * @return ?CustomBannerParam */
    static function make(Contact $user, $pj, $by_name = []) {
        if (!isset($pj->name)) {
            return null;
        }
        $type = $pj->type ?? "count";

        if ($type === "calc") {
            if (!isset($pj->value)) {
                return null;
            }
            $config = Formula::make_config()->set_deferred(true);
            $cbp = new CustomBannerParam;
            $cbp->name = $pj->name;
            $cbp->formula = Formula::make($user, $pj->value, $config);
            return $cbp;
        }

        $formula = null;
        if ($type === "formula" && isset($pj->value)) {
            $formula = Formula::make($user, $pj->value);
        }
        if (!isset($pj->q)
            || ($type === "formula" && !$formula)
            || ($type !== "count" && $type !== "formula")) {
            return null;
        }
        $cbp = new CustomBannerParam;
        $cbp->name = $pj->name;
        $cbp->srch = new PaperSearch($user, ["q" => $pj->q, "t" => $pj->t ?? "default"]);
        if (($cbp->formula = $formula)) {
            $formula->prepare_extractor()->prepare_combiner();
        }
        return $cbp;
    }

    function eval(?PaperInfoSet $prows, ?CustomBannerParamSet $paramset) {
        if (!$this->srch) {
            $v = $this->formula->bind_all(...$paramset->values)
                ->eval($this->formula->placeholder_prow(), null);
            return new FmtArg($this->name, $v, 0);
        }
        if (!$prows) {
            if (!$this->formula) {
                return new FmtArg($this->name, count($this->srch->paper_ids()), 0);
            }
            $prows = PaperInfoSet::make_search($this->srch);
        }
        if (!$this->formula) {
            return new FmtArg($this->name, count($prows->filter([$this->srch, "test"])), 0);
        }
        $values = [];
        foreach ($prows as $prow) {
            if (!$this->srch->test($prow)) {
                continue;
            }
            $values[] = $this->formula->eval_extractor($prow, null);
        }
        return new FmtArg($this->name, $this->formula->eval_combiner($values), 0);
    }
}

class CustomBannerParamSet {
    /** @var Contact */
    public $user;
    /** @var list<CustomBannerParam> */
    public $params = [];
    /** @var list<CustomBannerParam> */
    public $bad_params = [];
    /** @var list<FmtArg> */
    public $values = [];

    function __construct(Contact $user, $paramsj) {
        $this->user = $user;

        $by_name = [];
        foreach ($paramsj ?? [] as $pj) {
            if (isset($pj->name)) {
                $by_name[$pj->name] = $pj;
            }
        }

        $calcs = [];
        foreach ($by_name as $pj) {
            if (($p = CustomBannerParam::make($user, $pj, $by_name))) {
                if ($p->srch === null) {
                    $calcs[$p->name] = $p;
                } else if (!$p->formula
                           || $p->formula->support_combiner()) {
                    $this->params[] = $p;
                } else {
                    $this->bad_params[] = $p;
                }
            }
        }

        if ($calcs) {
            $this->resolve_calc($calcs);
        }
    }

    private function resolve_calc($calcs) {
        $nparams = [];
        foreach ($this->params as $p) {
            $nparams[$p->name] = $p;
        }
        $deps = [];
        foreach ($calcs as $p) {
            $deps[$p->name] = $p->formula->param_names();
        }

        foreach (Toposort::sort($deps) as $name) {
            $p = $calcs[$name];
            unset($calcs[$name]);
            $f = $p->formula;
            foreach ($f->param_names() as $depn) {
                if (($depp = $nparams[$depn] ?? null)) {
                    $f->set_param_format($depn,
                        $depp->formula ? $depp->formula->format() : Fexpr::FNUMERIC,
                        $depp->formula ? $depp->formula->format_detail() : null);
                }
            }
            $f->finalize()->prepare();
            if ($f->ok()) {
                $this->params[] = $nparams[$name] = $p;
            } else {
                $this->bad_params[] = $p;
            }
        }

        foreach ($calcs as $p) {
            $p->formula->lerrors[] = new MessageItem(2, null, "<0>Circular reference in banner parameter");
            $this->bad_params[] = $p;
        }
    }

    /** @param Contact $user
     * @return list<FmtArg> */
    function eval() {
        $prows = null;
        if (count($this->params) > 1 && $this->params[1]->srch) {
            $qs = [];
            $t = "";
            foreach ($this->params as $i => $p) {
                if (!$p->srch) {
                    break;
                }
                $qs[] = SearchParser::safe_parenthesize($p->srch->q);
                if ($i === 0) {
                    $t = $p->srch->limit();
                } else if ($t !== "viewable" && $t !== $p->srch->limit()) {
                    $t = "viewable";
                }
            }
            $csrch = new PaperSearch($this->user, ["q" => join(" OR ", $qs), "t" => $t]);
            $prows = PaperInfoSet::make_search($csrch);
        }

        // Evaluate all params in topo order
        $this->values = [];
        foreach ($this->params as $p) {
            $this->values[] = $p->eval($prows, $this);
        }
        return $this->values;
    }
}

class CustomBanners {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ?Qrequest */
    public $qreq;
    /** @var array<string,string> */
    private $active_bs;
    /** @var int */
    private $mcacheid = -1;
    /** @var ?array<string,string> */
    private $session_bs;
    /** @var int */
    private $session_deadline = -1;
    /** @var bool */
    private $session_invalid = false;

    const SVERSION = 3;
    const CACHEABLE = true;

    function __construct(Conf $conf, Contact $user, ?Qrequest $qreq = null) {
        $this->conf = $conf;
        $this->user = $user;
        $this->qreq = $qreq;
        if ($this->qreq) {
            $this->prepare_session_bs();
        }
    }

    private function prepare_session_bs() {
        $this->mcacheid = $this->conf->request_mcache();
        if ($this->mcacheid > 0
            && ($sval = $this->qreq ? $this->qreq->csession("banners") : null)
            && is_object($sval)
            && ($sval->v ?? null) === self::SVERSION
            && $sval->mcid === $this->mcacheid
            && (($sval->dl ?? 0) === 0 || $sval->dl > Conf::$now)) {
            $this->session_bs = $sval->bs ?? [];
            $this->session_deadline = $sval->dl ?? 0;
        }
    }

    /** @return ?string */
    function token() {
        if ($this->active_bs === null) {
            $this->active();
        }
        if ($this->session_deadline >= 0) {
            return $this->mcacheid . "." . $this->session_deadline;
        }
        return null;
    }

    /** @return bool */
    function check_token($token) {
        return $this->mcacheid > 0
            && is_string($token)
            && ($dot = strpos($token, ".")) !== false
            && substr($token, 0, $dot) === (string) $this->mcacheid
            && ctype_digit(substr($token, $dot + 1))
            && (($dl = (int) substr($token, $dot + 1)) === 0 || $dl > Conf::$now);
    }

    /** @param string $id
     * @param string $html */
    private function record($id, $html) {
        $this->bs[$id] = $html;
    }

    /** @return int */
    private function next_deadline() {
        $next = PHP_INT_MAX;
        foreach ($this->conf->submission_round_list() as $sr) {
            $next = min($next, $sr->closest_deadline_after(Conf::$now));
        }
        if (($t = $this->conf->setting("rev_open") ?? 0) >= Conf::$now) {
            $next = min($next, $t);
        }
        if ($t > 0) {
            foreach ($this->conf->round_list() as $i => $rname) {
                $suf = $i ? "_{$i}" : "";
                foreach (Conf::$review_deadlines as $dl) {
                    if (($t = $this->conf->setting("{$dl}{$suf}")) >= Conf::$now)
                        $next = min($next, $t);
                }
            }
        }
        foreach ($this->conf->response_round_list() as $rrd) {
            $next = min($next, $rrd->closest_deadline_after(Conf::$now));
        }
        return $next < PHP_INT_MAX ? $next : 0;
    }

    /** @return ?array{string,string} */
    private function try_mcache($id) {
        if (($html = $this->session_bs[$id] ?? null) !== null) {
            return [$id, $html];
        }
        return null;
    }

    /** @param bool $allow_cache
     * @return ?array{string,string} */
    private function check($bannerj, $allow_cache) {
        $vis = $bannerj->visibility ?? "admin";
        if ($vis === "none"
            || !$this->user->isPC
            || ($vis === "admin" && !$this->user->privChair)) {
            return null;
        }

        if ($allow_cache
            && !$this->user->is_actas_user()
            && ($mc = $this->try_mcache($bannerj->id))) {
            return $mc;
        } else if ($allow_cache) {
            $this->session_invalid = true;
        }

        if (isset($bannerj->params)) {
            $paramset = new CustomBannerParamSet($this->user, $bannerj->params);
            $values = $paramset->eval();
        } else {
            $values = [];
        }

        $in = Ftext::ensure($bannerj->ftext, 0);
        $out = $this->conf->_5($in, ...$values);
        if (str_starts_with($in, "<5>")) {
            $out = CleanHTML::basic_clean($out);
        }
        if (($out ?? "") === "") {
            return null;
        }
        return [$bannerj->id, $out];
    }

    /** @return ?object */
    function unparse_json($bannerj) {
        if (($bi = $this->check($bannerj, false))) {
            return (object) ["id" => $bi[0], "html" => $bi[1]];
        }
        return null;
    }

    /** @return array<string,string> */
    function active() {
        if ($this->active_bs !== null) {
            return $this->active_bs;
        }
        $this->active_bs = [];
        $bannerlist = $this->conf->setting_json("banners");
        if (!is_array($bannerlist)) {
            return [];
        }
        foreach ($bannerlist as $bannerj) {
            if (($bi = $this->check($bannerj, self::CACHEABLE))) {
                $this->active_bs[$bi[0]] = $bi[1];
            }
        }
        if ($this->mcacheid > 0 && $this->session_invalid && $this->qreq) {
            $this->session_deadline = $this->next_deadline();
            $this->qreq->set_csession("banners", (object) [
                "v" => self::SVERSION, "mcid" => $this->mcacheid,
                "bs" => $this->active_bs, "dl" => $this->session_deadline
            ]);
        }
        return $this->active_bs;
    }

    /** @return list<object> */
    function active_json() {
        $jl = [];
        foreach ($this->active() as $id => $html) {
            $jl[] = (object) ["id" => $id, "html" => $html];
        }
        return $jl;
    }

    /** @return bool */
    function used_session_cache() {
        return $this->active_bs !== null && !$this->session_invalid;
    }
}
