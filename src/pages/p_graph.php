<?php
// src/pages/p_graph.php -- HotCRP review preference graph drawing page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class Graph_Page {
    /** @param bool $searchable
     * @param ?string $h2
     * @param array $attr */
    static function echo_graph($searchable, $h2, $attr) {
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

    /** @param GroupedExtensions $gx */
    static function go(Contact $user, Qrequest $qreq, $gx) {
        $gtype = $qreq->group ?? "";
        if ($gtype === "" && preg_match('/\A\/\w+\/*\z/', $qreq->path())) {
            $gtype = $qreq->path_component(0);
        }
        $gtypes = $gx->members("graph");
        $gj = $gx->get("graph/{$gtype}");
        if ($gtype === "" && !empty($gtypes) && $qreq->is_get()) {
            $user->conf->redirect_self($qreq, ["group" => self::gj_group($gtypes[0])]);
            return false;
        } else if ($gj && $gj->name !== "graph/{$gtype}" && $qreq->is_get()) {
            $user->conf->redirect_self($qreq, ["group" => self::gj_group($gj)]);
            return false;
        }

        // Header and body
        $user->conf->header("Graph", "graphbody", ["subtitle" => $gj ? htmlspecialchars($gj->title) : null]);

        if (!empty($gtypes)) {
            echo '<nav class="papmodes mb-5 clearfix"><ul>';
            foreach ($gtypes as $gjx) {
                echo '<li class="papmode', $gjx === $gj ? " active" : "", '">',
                    Ht::link(htmlspecialchars($gjx->title),
                             $user->conf->hoturl("graph", ["group" => self::gj_group($gjx)])),
                    '</li>';
            }
            echo '</ul></nav>';
        }

        if (empty($gtypes)) {
            $user->conf->errorMsg("There are no graphs you can view.");
        } else if ($gj) {
            echo Ht::unstash(),
                $user->conf->make_script_file("scripts/d3-hotcrp.min.js", true),
                $user->conf->make_script_file("scripts/graph.js");
            $gx->set_section_class(false);
            $gx->render_group($gj->name, true);
        } else {
            $user->conf->errorMsg("No such graph.");
        }

        $user->conf->footer();
        return false;
    }
}
