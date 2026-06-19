<?php
// listactioncall.php -- HotCRP helper class for paper search actions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ListActionCall {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var 0|1 */
    public $flags;
    /** @var ComponentSet */
    private $cs;
    /** @var Qrequest */
    private $qreq;
    /** @var JsonResult|Downloader|Redirection|CsvGenerator|DocumentInfo|DocumentInfoSet */
    private $result;
    /** @var JsonResult|Downloader|Redirection */
    private $resolved_result;

    /** @param 0|1 $flags */
    function __construct(Contact $user, $flags = 0) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->flags = $flags;
    }

    /** @return ComponentSet */
    function cs() {
        if ($this->cs) {
            return $this->cs;
        }
        $this->cs = new ComponentSet($this->user, ["etc/listactions.json"],
                                     $this->conf->opt("listActions"));
        $this->cs->add_xt_checker(function ($e, $xt, $xtp) {
            if ($e === "api") {
                return ($this->flags & ListAction::F_API) !== 0;
            }
            return null;
        });
        foreach ($this->cs->members("__expand") as $gj) {
            if (isset($gj->allow_if)
                && !$this->cs->allowed($gj->allow_if, $gj)) {
                continue;
            }
            Conf::xt_resolve_require($gj);
            call_user_func($gj->expand_function, $this->cs, $gj);
        }
        return $this->cs;
    }

    /** @param string $name
     * @return $this */
    function call($name, Qrequest $qreq, SearchSelection $selection) {
        $this->qreq = $qreq;
        if ($qreq->method() !== "GET"
            && $qreq->method() !== "HEAD"
            && !$qreq->valid_token()) {
            $this->result = JsonResult::make_error(403, "<0>Missing credentials");
            return $this;
        }
        $wantapi = ($this->flags & ListAction::F_API) !== 0;
        $cs = $this->cs();
        $slash = strpos($name, "/");
        $namepfx = $slash > 0 ? substr($name, 0, $slash) : null;
        $cs->xtp->set_require_key_for_method($qreq->method());
        $uf = $cs->get($name) ?? ($namepfx ? $cs->get($namepfx) : null);
        if (!$uf) {
            $cs->reset_context();
            $cs->xtp->set_require_key_for_method(null);
            $uf1 = $cs->get($name) ?? ($namepfx ? $cs->get($namepfx) : null);
            if ($uf1) {
                $this->result = JsonResult::make_error(405, "<0>Method not supported");
                return $this;
            }
        }
        if (!$uf
            || !Conf::xt_resolve_require($uf)
            || (isset($uf->allow_if) && !$cs->allowed($uf->allow_if, $uf))
            || ($wantapi && ($uf->api ?? null) === false)
            || !is_string($uf->function)) {
            $this->result = JsonResult::make_error(404, "<0>Action not found");
            return $this;
        } else if (($uf->paper ?? false) && $selection->is_empty()) {
            $this->result = JsonResult::make_error(400, "<0>Empty selection");
            return $this;
        }
        if ($uf->function[0] === "+") {
            $class = substr($uf->function, 1);
            /** @phan-suppress-next-line PhanTypeExpectedObjectOrClassName */
            $action = new $class($this->conf, $uf);
        } else {
            $action = call_user_func($uf->function, $this->conf, $uf);
        }
        if (!$action || !$action->allow($this->user, $qreq)) {
            $this->result = JsonResult::make_permission_error();
        } else {
            $this->result = $action->run($this->user, $qreq, $selection)
                ?? JsonResult::make_error(500, "<0>Action execution error");
        }
        return $this;
    }

    /** @return JsonResult|Downloader|Redirection|CsvGenerator|DocumentInfo|DocumentInfoSet */
    function result() {
        assert($this->result !== null);
        return $this->result;
    }

    /** @return JsonResult|Downloader|Redirection */
    function resolved_result() {
        assert($this->result !== null);
        if ($this->resolved_result) {
            return $this->resolved_result;
        }
        if ($this->result instanceof DocumentInfo
            || $this->result instanceof DocumentInfoSet
            || $this->result instanceof CsvGenerator) {
            $dopt = new Downloader;
            $dopt->parse_qreq($this->qreq);
            $dopt->set_attachment(true);
            $dopt->set_log_user($this->user);
            if ($this->result->prepare_download($dopt)) {
                $this->resolved_result = $dopt;
            } else {
                $this->resolved_result = JsonResult::make_message_list(400, $this->result->message_list());
            }
        } else {
            $this->resolved_result = $this->result;
        }
        return $this->resolved_result;
    }

    function emit() {
        $result = $this->resolved_result();
        if ($result instanceof JsonResult) {
            if ($this->flags & ListAction::F_API) {
                $result->complete();
            }
            if (isset($result->content["message_list"])) {
                $this->conf->feedback_msg($result->content["message_list"]);
            }
        } else if ($result instanceof Redirection) {
            $this->qreq->redirect($result->url, $result->status);
        } else {
            $result->emit();
            Navigation::complete();
        }
    }
}
