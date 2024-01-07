<?php
// pages/p_graph.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Graph_Page {
    /** @param bool $searchable
     * @param ?string $h2
     * @param array $attr */
    static function print_graph($searchable, $h2, $attr) {
        echo '<div class="has-hotgraph" style="max-width:960px;margin-bottom:4em">';
        if ($searchable) {
            echo Ht::entry("q", "", ["placeholder" => "Find on graph", "class" => "uii js-hotgraph-highlight papersearch float-right need-autogrow need-suggest", "spellcheck" => false]);
        }
        if ($h2) {
            echo "<h2>", $h2, "</h2>\n";
        }
        echo '<div';
        $attr = ["id" => "hotgraph", "class" => "hotgraph c"] + $attr;
        foreach ($attr as $k => $v) {
            if ($v === "") {
                echo ' ', $k;
            } else if ($v !== null) {
                echo ' ', $k, '="', htmlspecialchars($v), '"';
            }
        }
        echo "></div></div>\n";
    }

    static function gj_group($gj) {
        return substr($gj->name, 6);
    }

    /** @param ComponentSet $gx
     * @return false */
    static function go(Contact $user, Qrequest $qreq, $gx) {
        $gtypes = $gx->members("graph");
        if (empty($gtypes)) {
            Multiconference::fail($qreq, 403, ["title" => "Graph"], "<0>There are no graphs you can view");
            return false;
        }

        $gtype = $qreq->group ?? "";
        if ($gtype === "" && preg_match('/\A\/\w+\/*\z/', $qreq->path())) {
            $gtype = $qreq->path_component(0);
        }
        $gj = $gx->get("graph/{$gtype}");
        if ($gtype === "" && !empty($gtypes) && $qreq->is_get()) {
            $user->conf->redirect_self($qreq, ["group" => self::gj_group($gtypes[0])]);
            return false;
        } else if ($gj && $gj->name !== "graph/{$gtype}" && $qreq->is_get()) {
            $user->conf->redirect_self($qreq, ["group" => self::gj_group($gj)]);
            return false;
        }
        if (!$gj) {
            Multiconference::fail($qreq, 404, ["title" => "Graph"], "<0>Graph not found");
            return false;
        }

        // Header and body
        $qreq->print_header("Graph", "graphbody", ["subtitle" => $gj ? htmlspecialchars($gj->title) : null]);

        echo '<nav class="papmodes mb-5 clearfix"><ul>';
        foreach ($gtypes as $gjx) {
            echo '<li class="papmode', $gjx === $gj ? " active" : "", '">',
                Ht::link(htmlspecialchars($gjx->title),
                         $user->conf->hoturl("graph", ["group" => self::gj_group($gjx)])),
                '</li>';
        }
        echo '</ul></nav>';

        echo Ht::unstash(),
            $user->conf->make_script_file("scripts/d3-hotcrp.min.js", true),
            $user->conf->make_script_file("scripts/graph.js");
        $gx->print_body_members($gj->name);

        $qreq->print_footer();
        return false;
    }
}
