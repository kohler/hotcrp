<?php
// sa/sa_tag.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Tag_SearchAction extends SearchAction {
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!$user->isPC)
            return self::EPERM;
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
            $tagger = new Tagger;
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

        if (!$Conf->headerPrinted && $qreq->ajax)
            $Conf->ajaxExit(array("ok" => $success));
        else if (!$Conf->headerPrinted && $success) {
            if (!$errors)
                $Conf->confirmMsg("Tags saved.");
            $args = array("atab" => "tag");
            foreach (array("tag", "tagfn", "tagcr_method", "tagcr_source", "tagcr_gapless") as $arg)
                if (isset($qreq[$arg]))
                    $args[$arg] = $qreq[$arg];
            redirectSelf($args);
        }
    }
}

SearchActions::register("tag", null, SiteLoader::API_POST | SiteLoader::API_PAPER, new Tag_SearchAction);
