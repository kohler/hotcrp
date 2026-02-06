<?php
// quicklinksrenderer.php -- HotCRP functions for rendering quicksearch/quicklinks
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class QuicklinksRenderer {
    /** @param Qrequest $qreq
     * @return string */
    static private function one_quicklink($qreq, $id, $baseUrl, $urlrest, $listtype, $isprev) {
        if ($listtype == "u") {
            $result = $qreq->conf()->ql("select email from ContactInfo where contactId=?", $id);
            $row = $result->fetch_row();
            Dbl::free($result);
            $paperText = htmlspecialchars($row ? $row[0] : $id);
            $urlrest["u"] = (string) $id;
        } else {
            $paperText = "#{$id}";
            $urlrest["p"] = $id;
        }
        $url = $qreq->conf()->hoturl($baseUrl, $urlrest);
        $icon = Icons::ui_linkarrow($isprev ? 3 : 1);
        if ($isprev) {
            return "<a id=\"n-prev\" class=\"ulh pnum\" href=\"{$url}\">{$icon}{$paperText}</a>";
        }
        return "<a id=\"n-next\" class=\"ulh pnum\" href=\"{$url}\">{$paperText}{$icon}</a>";
    }

    /** @param Qrequest $qreq
     * @return string */
    static private function quicksearch_form($qreq, $baseUrl = null, $args = []) {
        if ($qreq->user()->is_empty()) {
            return "";
        }
        $tabindex = $qreq->page() === "search" ? -1 : null;
        $x = Ht::form($qreq->conf()->hoturl($baseUrl ?? "paper"), ["method" => "get", "role" => "search"]);
        if ($baseUrl === "profile") {
            $x .= Ht::entry("u", "", ["id" => "n-search", "size" => 15, "placeholder" => "User search", "aria-label" => "User search", "class" => "usersearch need-autogrow", "spellcheck" => false, "autocomplete" => "off", "tabindex" => $tabindex]);
        } else {
            $x .= Ht::entry("q", "", ["id" => "n-search", "size" => 10, "placeholder" => "(All)", "aria-label" => "Search", "class" => "papersearch need-suggest need-autogrow", "spellcheck" => false, "autocomplete" => "off", "tabindex" => $tabindex]);
        }
        foreach ($args as $k => $v) {
            $x .= Ht::hidden($k, $v);
        }
        return $x . Ht::submit("Search", ["class" => "ml-2"]) . "</form>";
    }

    /** @param null|'paper'|'review'|'assign'|'edit'|'account' $mode
     * @return string */
    static function make(Qrequest $qreq, $mode = null) {
        $user = $qreq->user();
        if ($user->is_disabled()) {
            return "";
        }

        $xmode = [];
        $listtype = "p";

        $goBase = "paper";
        if ($mode === "assign") {
            $goBase = "assign";
        } else if ($mode === "re") {
            $goBase = "review";
        } else if ($mode === "account") {
            $listtype = "u";
            if ($user->privChair) {
                $goBase = "profile";
                $xmode["search"] = 1;
            }
        } else if ($mode === "edit") {
            $xmode["m"] = "edit";
        } else if ($qreq && ($qreq->m || $qreq->mode)) {
            $xmode["m"] = $qreq->m ? : $qreq->mode;
        }

        // quicklinks
        $x = "";
        if (($list = $qreq->active_list())) {
            $x .= '<div class="quicklink-item quicklinks"';
            if ($xmode || $goBase !== "paper") {
                $x .= ' data-link-params="' . htmlspecialchars(json_encode_browser(["page" => $goBase] + $xmode)) . '"';
            }
            $x .= '>';
            if (($prev = $list->neighbor_id(-1)) !== false) {
                $x .= self::one_quicklink($qreq, $prev, $goBase, $xmode, $listtype, true) . " ";
            }
            if ($list->description) {
                $d = htmlspecialchars($list->description);
                $url = $list->full_site_relative_url($user);
                if ($url) {
                    $x .= '<a id="n-list" class="ulh" href="' . htmlspecialchars($qreq->navigation()->siteurl() . $url) . "\">{$d}</a>";
                } else {
                    $x .= "<span id=\"n-list\">{$d}</span>";
                }
            }
            if (($next = $list->neighbor_id(1)) !== false) {
                $x .= " " . self::one_quicklink($qreq, $next, $goBase, $xmode, $listtype, false);
            }
            $x .= '</div>';

            if ($user->is_track_manager() && $listtype === "p") {
                $x .= '<div class="quicklink-item no-print"><button type="button" id="tracker-connect-btn" class="ui js-tracker tbtn need-tooltip" aria-label="Start meeting tracker">&#9759;</button></div>';
            }
        }

        // paper search form
        if ($user->isPC || $user->is_reviewer() || $user->is_author()) {
            $x .= '<div class="quicklink-item no-print">' . self::quicksearch_form($qreq, $goBase, $xmode) . '</div>';
        }

        $navname = $listtype === "p" ? "Submission search" : "User search";
        return "<nav id=\"p-quicklinks\" aria-label=\"{$navname}\">{$x}</nav>";
    }
}
