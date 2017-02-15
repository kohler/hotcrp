<?php
// sa/sa_tag.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Tag_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->can_change_some_tag();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if (!$user->isPC || Navigation::page() === "reviewprefs")
            return;

        // tagtype cell
        $tagopt = array("a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add to order", "aos" => "Add to gapless order", "so" => "Define order", "sos" => "Define gapless order", "sor" => "Define random order");
        $tagextra = array("id" => "placttagtype");
        if ($user->privChair) {
            $tagopt["xxxb"] = null;
            $tagopt["da"] = "Clear twiddle";
            $tagopt["cr"] = "Calculate rank";
            $tagextra["onchange"] = "plactions_dofold()";
            Ht::stash_script("plactions_dofold()", "plactions_dofold");
        }

        // tag name cell
        $t = "";
        if ($user->privChair) {
            $t .= '<span class="fx99"><a class="q" href="#" onclick="return fold(\'placttags\')">'
                . expander(null, 0) . "</a></span>";
        }
        $t .= 'tag<span class="fn99">(s)</span> &nbsp;'
            . Ht::entry("tag", $qreq->tag,
                        ["size" => 15, "onfocus" => "suggest(this,taghelp_tset);autosub('tag',this)", "class" => "want-focus"])
            . ' &nbsp;' . Ht::submit("fn", "Go", ["value" => "tag", "onclick" => "return plist_submit.call(this)"]);
        if ($user->privChair) {
            $t .= "<div class='fx'><div style='margin:2px 0'>"
                . Ht::checkbox("tagcr_gapless", 1, $qreq->tagcr_gapless, array("style" => "margin-left:0"))
                . "&nbsp;" . Ht::label("Gapless order") . "</div>"
                . "<div style='margin:2px 0'>Using: &nbsp;"
                . Ht::select("tagcr_method", PaperRank::methods(), $qreq->tagcr_method)
                . "</div>"
                . "<div style='margin:2px 0'>Source tag: &nbsp;~"
                . Ht::entry("tagcr_source", $qreq->tagcr_source, array("size" => 15))
                . "</div></div>";
        }

        $actions[] = [500, "tag", "Tag", "<b>:</b> &nbsp;"
            . Ht::select("tagfn", $tagopt, $qreq->tagfn, $tagextra) . " &nbsp;",
            ["id" => "foldplacttags", "class" => "foldc fold99c", "content" => $t]];
    }
    function run(Contact $user, $qreq, $ssel) {
        $papers = $ssel->selection();

        $act = $qreq->tagfn;
        $tagreq = trim(str_replace(",", " ", (string) $qreq->tag));
        $tags = preg_split('/\s+/', $tagreq);

        if ($act == "da") {
            $otags = $tags;
            foreach ($otags as $t)
                $tags[] = "all~" . preg_replace(',\A.*~([^~]+)\z', '$1', $t);
            $act = "d";
        } else if ($act == "sor")
            shuffle($papers);

        $x = array("action,paper,tag\n");
        if ($act == "s" || $act == "so" || $act == "sos" || $act == "sor")
            foreach ($tags as $t)
                $x[] = "cleartag,all," . TagInfo::base($t) . "\n";
        if ($act == "s" || $act == "a")
            $action = "tag";
        else if ($act == "d")
            $action = "cleartag";
        else if ($act == "so" || $act == "sor" || $act == "ao")
            $action = "nexttag";
        else if ($act == "sos" || $act == "aos")
            $action = "seqnexttag";
        else
            $action = null;

        $assignset = new AssignmentSet($user, $user->privChair);
        if (count($papers) && $action) {
            foreach ($papers as $p) {
                foreach ($tags as $t)
                    $x[] = "$action,$p,$t\n";
            }
            $assignset->parse(join("", $x));
        } else if (count($papers) && $act == "cr" && $user->privChair) {
            $source_tag = trim((string) $qreq->tagcr_source);
            if ($source_tag == "")
                $source_tag = (substr($tagreq, 0, 2) == "~~" ? substr($tagreq, 2) : $tagreq);
            $tagger = new Tagger($user);
            if ($tagger->check($tagreq, Tagger::NOPRIVATE | Tagger::NOVALUE)
                && $tagger->check($source_tag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $r = new PaperRank($source_tag, $tagreq, $papers, $qreq->tagcr_gapless,
                                   "Search", "search");
                $r->run($qreq->tagcr_method);
                $r->apply($assignset);
                $assignset->finish();
                if ($qreq->q === "")
                    $qreq->q = "order:$tagreq";
            } else
                $assignset->error($tagger->error_html);
        }
        if (($errors = join("<br />\n", $assignset->errors_html()))) {
            if ($assignset->has_assigners()) {
                Conf::msg_warning("Some tag assignments were ignored:<br />\n$errors");
                $assignset->clear_errors();
            } else
                Conf::msg_error($errors);
        }
        $success = $assignset->execute();

        assert(!$user->conf->headerPrinted);
        if (!$user->conf->headerPrinted && $qreq->ajax)
            $user->conf->ajaxExit(array("ok" => $success));
        else if (!$user->conf->headerPrinted && $success) {
            if (!$errors)
                $user->conf->confirmMsg("Tags saved.");
            $args = array("atab" => "tag");
            foreach (array("tag", "tagfn", "tagcr_method", "tagcr_source", "tagcr_gapless") as $arg)
                if (isset($qreq[$arg]))
                    $args[$arg] = $qreq[$arg];
            redirectSelf($args);
        }
    }
}

SearchAction::register("tag", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Tag_SearchAction);
