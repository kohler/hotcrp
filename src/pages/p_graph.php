<?php
// pages/p_graph.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Graph_Page {
    /** @param bool $searchable
     * @param ?string $h2
     * @param array $attr */
    static function print_graph($searchable, $h2, $attr) {
        // The head is a flex row so the controls have a box of their own,
        // whether or not there is a title: floated beside an absent <h2> they
        // would fall inside #hotgraph, and the graph sizes itself from that
        // element's top.
        echo '<div class="has-hotgraph" style="margin-bottom:4em">',
            '<div class="hotgraph-head">';
        if ($h2) {
            echo "<h2>", $h2, "</h2>";
        }
        Icons::stash_defs("zoom_in", "zoom_out");
        echo '<div class="hotgraph-head-controls">';
        echo '<div class="hotgraph-zoom btnbox">',
            Ht::button(Icons::ui_use("zoom_in"), ["class" => "btn-licon ui js-hotgraph-zoom zoom-in need-tooltip", "aria-label" => "Zoom in"]),
            Ht::button(Icons::ui_use("zoom_out"), ["class" => "btn-licon ui js-hotgraph-zoom zoom-out need-tooltip", "aria-label" => "Zoom out"]),
            "</div>";
        if ($searchable) {
            echo Ht::entry("q", "", ["placeholder" => "Find on graph", "class" => "uii js-hotgraph-highlight papersearch need-autogrow need-suggest", "spellcheck" => false]);
        }
        echo "</div></div>\n",
            '<div', Ht::extra(["id" => "hotgraph"] + $attr),
            "></div></div>\n";
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
        if ($gtype === "" && !empty($gtypes) && $qreq->is_getlike()) {
            $qreq->redirect_self(["group" => self::gj_group($gtypes[0])]);
            return false;
        } else if ($gj && $gj->name !== "graph/{$gtype}" && $qreq->is_getlike()) {
            $qreq->redirect_self(["group" => self::gj_group($gj)]);
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
                $user->conf->hotlink(htmlspecialchars($gjx->title), "graph", ["group" => self::gj_group($gjx)]),
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
