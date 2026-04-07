<?php
// rendercapture.php -- HotCRP page render capture
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class RenderCapture {
    /** @var int */
    public $status;
    /** @var list<string> */
    public $headers;
    /** @var string */
    public $content;

    /** @return RenderCapture */
    static function make(Qrequest $qreq) {
        $conf = $qreq->conf();
        $user = $qreq->user();

        Qrequest::set_main_request($qreq);
        Contact::set_main_user($user);
        Navigation::headers_reset();
        $conf->_header_printed = false;

        ob_start();
        try {
            $pc = $conf->page_components($user, $qreq);
            $pagej = $pc->get($qreq->page());
            if (!$pagej || str_starts_with($pagej->name, "__")) {
                Multiconference::fail($qreq, 404, ["link" => true], "<0>Page not found");
            } else if ($user->is_disabled() && !($pagej->allow_disabled ?? false)) {
                Multiconference::fail_user_disabled($user, $qreq);
            }
            $pc->set_root($pagej->group);
            if (!isset($pagej->request_function)
                || $pc->call_function($pagej, $pagej->request_function, $pagej) !== false) {
                foreach ($pc->members($pagej->group, "request_function") as $gj) {
                    if ($pc->call_function($gj, $gj->request_function, $gj) === false) {
                        break;
                    }
                }
            }
            $pc->print_body_members($pagej->group);
        } catch (Redirection $redir) {
            $conf->saved_messages_commit($qreq);
            Navigation::http_response_code($redir->status);
            Navigation::header("Location: " . $qreq->navigation()->resolve($redir->url));
        } catch (JsonCompletion $jc) {
            $jc->result->emit($qreq);
        } catch (PageCompletion $unused) {
        }

        $rc = new RenderCapture;
        $rc->status = Navigation::http_response_code();
        $rc->headers = Navigation::headers_list();
        $rc->content = ob_get_clean();
        return $rc;
    }

    /** @param string $name
     * @return ?string */
    function header($name) {
        $value = null;
        foreach ($this->headers as $h) {
            if (strlen($h) <= strlen($name)
                || $h[strlen($name)] !== ":"
                || substr_compare($h, $name, 0, strlen($name), true) !== 0) {
                continue;
            }
            $vx = trim(substr($h, strlen($name) + 1));
            if ($value === null) {
                $value = $vx;
            } else {
                $value .= ", " . $vx;
            }
        }
        return $value;
    }

    /** @return bool */
    function ok() {
        return $this->status >= 200 && $this->status < 300;
    }
}
