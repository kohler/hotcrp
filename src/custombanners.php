<?php
// custombanners.php -- HotCRP configurable page banners
// Copyright (c) 2009-2023 Eddie Kohler; see LICENSE.

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
    /** @var Qrequest */
    public $qreq;
    /** @var list<string> */
    private $bs = [];
    /** @var int */
    private $mcacheid = -1;
    /** @var bool */
    private $session_ok = true;
    /** @var ?list<string> */
    private $session_bs;

    const SVERSION = 1;
    const CACHEABLE = true;

    function __construct(Conf $conf, Contact $user, Qrequest $qreq) {
        $this->conf = $conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }

    private function record($id, $html) {
        array_push($this->bs, $id, $html);
    }

    private function try_mcache($id) {
        if ($this->mcacheid < 0) {
            $this->mcacheid = $this->conf->request_mcache();
        }
        if ($this->mcacheid === 0) {
            return false;
        }
        if ($this->session_bs === null) {
            $this->session_bs = [];
            $sval = $this->qreq->csession("banners");
            if (is_object($sval)
                && ($sval->v ?? null) === self::SVERSION
                && $sval->mcid === $this->mcacheid) {
                $this->session_bs = $sval->bs ?? [];
            }
        }
        for ($i = 0; $i !== count($this->session_bs); $i += 2) {
            if ($this->session_bs[$i] === $id) {
                $this->record($id, $this->session_bs[$i + 1]);
                return true;
            }
        }
        return false;
    }

    function print($bannerj) {
        $vis = $bannerj->visibility ?? "admin";
        if ($vis === "none"
            || !$this->user->isPC
            || ($vis === "admin" && !$this->user->privChair)) {
            return;
        }

        if ($this->try_mcache($bannerj->id) && self::CACHEABLE) {
            return;
        }
        $this->session_ok = false;

        $params = [];
        foreach ($bannerj->params ?? [] as $pj) {
            if (($p = CustomBannerParam::make($this, $pj)))
                $params[] = $p;
        }

        $pvalues = [];
        if (count($params) === 1) {
            $pvalues[] = $params[0]->eval(null);
        } else if (!empty($searches)) {
            $qs = [];
            $t = $searches[0]->limit();
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

        $html = $this->conf->_5($bannerj->ftext, ...$pvalues);
        $this->record($bannerj->id, $html);
    }

    function run() {
        $bannerlist = $this->conf->setting_json("banners");
        if (!is_array($bannerlist)) {
            return;
        }
        foreach ($bannerlist as $bannerj) {
            $this->print($bannerj);
        }
        if (empty($this->bs)) {
            return;
        }
        $x = [];
        for ($i = 0; $i !== count($this->bs); $i += 2) {
            $x[] = 'hotcrp.banner.add('
                . json_encode_browser("p-cbanner-" . $this->bs[$i])
                . ', "cbanner").innerHTML = '
                . json_encode_browser($this->bs[$i + 1]);
        }
        $x[] = "hotcrp.banner.resize()";
        $x[] = '$(hotcrp.banner.resize)';
        echo Ht::unstash_script(join(";", $x));
        if ($this->mcacheid > 0 && !$this->session_ok) {
            $this->qreq->set_csession("banners", (object) [
                "v" => self::SVERSION, "mcid" => $this->mcacheid, "bs" => $this->bs
            ]);
        }
    }
}
