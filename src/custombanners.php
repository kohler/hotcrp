<?php
// custombanners.php -- HotCRP configurable page banners
// Copyright (c) 2009-2026 Eddie Kohler; see LICENSE.

class CustomBannerParam {
    /** @var string */
    public $name;
    /** @var PaperSearch */
    public $srch;
    /** @var ?Formula */
    public $formula;

    /** @return ?CustomBannerParam */
    static function make(CustomBanners $cb, $pj) {
        if (!isset($pj->name) || !isset($pj->q)) {
            return null;
        }
        $type = $pj->type ?? "count";
        $formula = null;
        if ($type === "formula" && isset($pj->value)) {
            $formula = Formula::make($cb->user, $pj->value);
        }
        if ($type !== "count"
            && ($type !== "formula" || !$formula || !$formula->ok() || !$formula->support_combiner())) {
            return null;
        }
        $cbp = new CustomBannerParam;
        $cbp->name = $pj->name;
        $cbp->srch = new PaperSearch($cb->user, ["q" => $pj->q, "t" => $pj->t ?? "default"]);
        if (($cbp->formula = $formula)) {
            $formula->prepare_extractor()->prepare_combiner();
        }
        return $cbp;
    }

    function eval(?PaperInfoSet $prows) {
        if ($prows === null) {
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

        $params = [];
        foreach ($bannerj->params ?? [] as $pj) {
            if (($p = CustomBannerParam::make($this, $pj)))
                $params[] = $p;
        }

        $pvalues = [];
        if (count($params) === 1) {
            $pvalues[] = $params[0]->eval(null);
        } else if (!empty($params)) {
            $qs = [];
            $t = $params[0]->srch->limit();
            foreach ($params as $param) {
                $qs[] = SearchParser::safe_parenthesize($param->srch->q);
                if ($t !== "viewable" && $t !== $param->srch->limit()) {
                    $t = "viewable";
                }
            }
            $csrch = new PaperSearch($this->user, ["q" => join(" OR ", $qs), "t" => $t]);
            $prows = PaperInfoSet::make_search($csrch);
            foreach ($params as $param) {
                $pvalues[] = $param->eval($prows);
            }
        }

        $in = Ftext::ensure($bannerj->ftext, 0);
        $out = $this->conf->_5($in, ...$pvalues);
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
