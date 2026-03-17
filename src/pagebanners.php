<?php
// pagebanners.php -- HotCRP configurable page banners
// Copyright (c) 2009-2023 Eddie Kohler; see LICENSE.

class PageBanners {
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
        if (!$this->user->isPC
            || (!$this->user->privChair
                && ($bannerj->visibility ?? "admin") === "admin")) {
            return;
        }

        if ($this->try_mcache($bannerj->id)) {
            return;
        }
        $this->session_ok = false;

        $searches = [];
        $sqns = [];
        foreach ($bannerj->params ?? [] as $pj) {
            if (!isset($pj->name) || !isset($pj->q) || !isset($pj->type)
                || $pj->type !== "count") {
                continue;
            }
            $searches[] = new PaperSearch($this->user, ["q" => $pj->q, "t" => $pj->t ?? "default"]);
            $sqns[] = $pj->name;
        }

        $params = [];
        if (count($searches) === 1) {
            $params[] = new FmtArg($sqns[0], count($searches[0]->paper_ids()), 0);
        } else if (!empty($searches)) {
            $qs = [];
            $t = $searches[0]->limit();
            foreach ($searches as $srch) {
                $qs[] = SearchParser::safe_parenthesize($srch->q);
                if ($t !== "viewable" && $t !== $srch->limit()) {
                    $t = "viewable";
                }
            }
            $csrch = new PaperSearch($this->user, ["q" => join(" OR ", $qs), "t" => $t]);
            $prows = PaperInfoSet::make_search($csrch);
            foreach ($searches as $i => $srch) {
                $params[] = new FmtArg($sqns[$i], count($prows->filter([$srch, "test"])), 0);
            }
        }

        $html = $this->conf->_5($bannerj->ftext, ...$params);
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
                . json_encode_browser("p-ubanner-" . $this->bs[$i])
                . ', "ubanner").innerHTML = '
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
