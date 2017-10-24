<?php
// src/help/h_keywords.php -- HotCRP help functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class HelpTopic_Keywords {
    static function render(Contact $user, $hth) {
        // how to report author searches?
        if ($user->conf->subBlindNever())
            $aunote = "";
        else if (!$user->conf->subBlindAlways())
            $aunote = "<br /><span class='hint'>Search uses fields visible to the searcher. For example, PC member searches do not examine anonymous authors.</span>";
        else
            $aunote = "<br /><span class='hint'>Search uses fields visible to the searcher. For example, PC member searches do not examine authors.</span>";

        // does a reviewer tag exist?
        $retag = meaningful_pc_tag($user) ? : "";

        echo $hth->table(true);
        echo $hth->tgroup("Basics");
        echo $hth->search_trow("", "all papers in the search category");
        echo $hth->search_trow("story", "“story” in title, abstract, authors$aunote");
        echo $hth->search_trow("119", "paper #119");
        echo $hth->search_trow("1 2 5 12-24 kernel", "papers in the numbered set with “kernel” in title, abstract, authors");
        echo $hth->search_trow("\"802\"", "“802” in title, abstract, authors (not paper #802)");
        echo $hth->search_trow("very new", "“very” <em>and</em> “new” in title, abstract, authors");
        echo $hth->search_trow("very AND new", "the same");
        echo $hth->search_trow("\"very new\"", "the phrase “very new” in title, abstract, authors");
        echo $hth->search_trow("very OR new", "<em>either</em> “very” <em>or</em> “new” in title, abstract, authors");
        echo $hth->search_trow("(very AND new) OR newest", "use parentheses to group");
        echo $hth->search_trow("very -new", "“very” <em>but not</em> “new” in title, abstract, authors");
        echo $hth->search_trow("very NOT new", "the same");
        echo $hth->search_trow("ve*", "words that <em>start with</em> “ve” in title, abstract, authors");
        echo $hth->search_trow("*me*", "words that <em>contain</em> “me” in title, abstract, authors");
        echo $hth->tgroup("Title");
        echo $hth->search_trow("ti:flexible", "title contains “flexible”");
        echo $hth->tgroup("Abstract");
        echo $hth->search_trow("ab:\"very novel\"", "abstract contains “very novel”");
        echo $hth->tgroup("Authors");
        echo $hth->search_trow("au:poletto", "author list contains “poletto”");
        if ($user->isPC)
            echo $hth->search_trow("au:pc", "one or more authors are PC members (author email matches PC email)");
        echo $hth->search_trow("au:>4", "more than four authors");
        echo $hth->tgroup("Collaborators");
        echo $hth->search_trow("co:liskov", "collaborators contains “liskov”");
        echo $hth->tgroup("Topics");
        echo $hth->search_trow("topic:link", "selected topics match “link”");

        $oex = array();
        foreach ($user->conf->paper_opts->option_list() as $o)
            $oex = array_merge($oex, $o->example_searches());
        if (!empty($oex)) {
            echo $hth->tgroup("Options");
            foreach ($oex as $extype => $oex) {
                if ($extype === "has") {
                    $desc = "paper has “" . htmlspecialchars($oex[1]->name) . "” submission option";
                    $oabbr = array();
                    foreach ($user->conf->paper_opts->option_list() as $ox)
                        if ($ox !== $oex[1])
                            $oabbr[] = "“has:" . htmlspecialchars($ox->search_keyword()) . "”";
                    if (count($oabbr))
                        $desc .= '<div class="hint">Other option ' . pluralx(count($oabbr), "search") . ': ' . commajoin($oabbr) . '</div>';
                } else if ($extype === "yes")
                    $desc = "same meaning; abbreviations also accepted";
                else if ($extype === "numeric")
                    $desc = "paper’s “" . htmlspecialchars($oex[1]->name) . "” option has value &gt; 100";
                else if ($extype === "selector")
                    $desc = "paper’s “" . htmlspecialchars($oex[1]->name) . "” option has value “" . htmlspecialchars($oex[1]->selector[1]) . "”";
                else if ($extype === "attachment-count")
                    $desc = "paper has more than 2 “" . htmlspecialchars($oex[1]->name) . "” attachments";
                else if ($extype === "attachment-filename")
                    $desc = "paper has an “" . htmlspecialchars($oex[1]->name) . "” attachment with a .gif extension";
                else
                    continue;
                echo $hth->search_trow($oex[0], $desc);
            }
        }

        echo $hth->tgroup("<a href=\"" . hoturl("help", "t=tags") . "\">Tags</a>");
        echo $hth->search_trow("#discuss", "tagged “discuss” (“tag:discuss” also works)");
        echo $hth->search_trow("-#discuss", "not tagged “discuss”");
        echo $hth->search_trow("order:discuss", "tagged “discuss”, sort by tag order (“rorder:” for reverse order)");
        echo $hth->search_trow("#disc*", "matches any tag that <em>starts with</em> “disc”");

        $cx = null;
        $cm = array();
        foreach ($user->conf->tags() as $t)
            foreach ($t->colors ? : array() as $c) {
                $cx = $cx ? : $c;
                if ($cx === $c)
                    $cm[] = "“{$t->tag}”";
            }
        if (!empty($cm)) {
            array_unshift($cm, "“{$cx}”");
            echo $hth->search_trow("style:$cx", "tagged to appear $cx (tagged " . commajoin($cm, "or") . ")");
        }

        $roundname = meaningful_round_name($user);

        echo $hth->tgroup("Reviews");
        echo $hth->search_trow("re:me", "you are a reviewer");
        echo $hth->search_trow("re:fdabek", "“fdabek” in reviewer name/email");
        if ($retag)
            echo $hth->search_trow("re:#$retag", "has a reviewer tagged “#" . $retag . "”");
        echo $hth->search_trow("re:4", "four reviewers (assigned and/or completed)");
        if ($retag)
            echo $hth->search_trow("re:#$retag>1", "at least two reviewers (assigned and/or completed) tagged “#" . $retag . "”");
        echo $hth->search_trow("re:complete<3", "less than three completed reviews<br /><span class=\"hint\">Use “cre:<3” for short.</span>");
        echo $hth->search_trow("re:incomplete>0", "at least one incomplete review");
        echo $hth->search_trow("re:inprogress", "at least one in-progress review (started, but not completed)");
        echo $hth->search_trow("re:primary>=2", "at least two primary reviewers");
        echo $hth->search_trow("re:secondary", "at least one secondary reviewer");
        echo $hth->search_trow("re:external", "at least one external reviewer");
        echo $hth->search_trow("re:primary:fdabek:complete", "“fdabek” has completed a primary review");
        if ($roundname)
            echo $hth->search_trow("re:$roundname", "review in round “" . htmlspecialchars($roundname) . "”");
        echo $hth->search_trow("re:auwords<100", "has a review with less than 100 words in author-visible fields");
        if ($user->conf->setting("rev_tokens"))
            echo $hth->search_trow("retoken:J88ADNAB", "has a review with token J88ADNAB");
        if ($user->conf->setting("rev_ratings") != REV_RATINGS_NONE)
            echo $hth->search_trow("rate:+", "review was rated positively (“rate:-” and “rate:boring” also work; can combine with “re:”)");

        echo $hth->tgroup("Comments");
        echo $hth->search_trow("has:cmt", "at least one visible reviewer comment (not including authors’ response)");
        echo $hth->search_trow("cmt:>=3", "at least <em>three</em> visible reviewer comments");
        echo $hth->search_trow("has:aucmt", "at least one reviewer comment visible to authors");
        echo $hth->search_trow("cmt:sylvia", "“sylvia” (in name/email) wrote at least one visible comment; can combine with counts, use reviewer tags");
        $rnames = $user->conf->resp_round_list();
        if (count($rnames) > 1) {
            echo $hth->search_trow("has:response", "has an author’s response");
            echo $hth->search_trow("has:{$rnames[1]}response", "has $rnames[1] response");
        } else
            echo $hth->search_trow("has:response", "has author’s response");
        echo $hth->search_trow("anycmt:>1", "at least two visible comments, possibly <em>including</em> author’s response");

        echo $hth->tgroup("Leads");
        echo $hth->search_trow("lead:fdabek", "“fdabek” (in name/email) is discussion lead");
        echo $hth->search_trow("lead:none", "no assigned discussion lead");
        echo $hth->search_trow("lead:any", "some assigned discussion lead");
        echo $hth->tgroup("Shepherds");
        echo $hth->search_trow("shep:fdabek", "“fdabek” (in name/email) is shepherd (“none” and “any” also work)");
        echo $hth->tgroup("Conflicts");
        echo $hth->search_trow("conflict:me", "you have a conflict with the paper");
        echo $hth->search_trow("conflict:fdabek", "“fdabek” (in name/email) has a conflict with the paper<br /><span class='hint'>This search is only available to chairs and to PC members who can see the paper’s author list.</span>");
        echo $hth->search_trow("conflict:pc", "some PC member has a conflict with the paper");
        echo $hth->search_trow("conflict:pc>2", "at least three PC members have conflicts with the paper");
        echo $hth->search_trow("reconflict:\"1 2 3\"", "a reviewer of paper 1, 2, or 3 has a conflict with the paper");
        echo $hth->tgroup("Preferences");
        echo $hth->search_trow("pref:3", "you have preference 3");
        echo $hth->search_trow("pref:pc:X", "a PC member’s preference has expertise “X” (expert)");
        echo $hth->search_trow("pref:fdabek>0", "“fdabek” (in name/email) has preference &gt;&nbsp;0<br /><span class=\"hint\">Administrators can search preferences by name; PC members can only search preferences for the PC as a whole.</span>");
        echo $hth->tgroup("Status");
        echo $hth->search_trow(["q" => "status:sub", "t" => "all"], "paper is submitted for review");
        echo $hth->search_trow(["q" => "status:unsub", "t" => "all"], "paper is neither submitted nor withdrawn");
        echo $hth->search_trow(["q" => "status:withdrawn", "t" => "all"], "paper has been withdrawn");
        echo $hth->search_trow("has:final", "final copy uploaded");

        foreach ($user->conf->decision_map() as $dnum => $dname)
            if ($dnum)
                break;
        $qdname = strtolower($dname);
        if (strpos($qdname, " ") !== false)
            $qdname = "\"$qdname\"";
        echo $hth->tgroup("Decisions");
        echo $hth->search_trow("dec:$qdname", "decision is “" . htmlspecialchars($dname) . "” (partial matches OK)");
        echo $hth->search_trow("dec:yes", "one of the accept decisions");
        echo $hth->search_trow("dec:no", "one of the reject decisions");
        echo $hth->search_trow("dec:any", "decision specified");
        echo $hth->search_trow("dec:none", "decision unspecified");

        // find names of review fields to demonstrate syntax
        $farr = array(array(), array());
        foreach ($user->conf->all_review_fields() as $f)
            $farr[$f->has_options ? 0 : 1][] = $f;
        if (!empty($farr[0]) || !empty($farr[1]))
            echo $hth->tgroup("Review fields");
        if (count($farr[0])) {
            $r = $farr[0][0];
            echo $hth->search_trow("{$r->abbreviation1()}:{$r->typical_score()}", "at least one completed review has $r->name_html score {$r->typical_score()}");
            echo $hth->search_trow("{$r->search_keyword()}:{$r->typical_score()}", "other abbreviations accepted");
            if (count($farr[0]) > 1) {
                $r2 = $farr[0][1];
                echo $hth->search_trow(strtolower($r2->search_keyword()) . ":{$r2->typical_score()}", "other fields accepted (here, $r2->name_html)");
            }
            if (($range = $r->typical_score_range())) {
                echo $hth->search_trow("{$r->search_keyword()}:{$range[0]}..{$range[1]}", "completed reviews’ $r->name_html scores are in the {$range[0]}&ndash;{$range[1]} range<br /><small>(all scores between {$range[0]} and {$range[1]})</small>");
                $rt = $range[0] . ($r->option_letter ? "" : "-") . $range[1];
                echo $hth->search_trow("{$r->search_keyword()}:$rt", "completed reviews’ $r->name_html scores <em>fill</em> the {$range[0]}&ndash;{$range[1]} range<br /><small>(all scores between {$range[0]} and {$range[1]}, with at least one {$range[0]} and at least one {$range[1]})</small>");
            }
            if (!$r->option_letter)
                list($greater, $less, $hint) = array("greater", "less", "");
            else {
                $hint = "<br /><small>(better scores are closer to A than Z)</small>";
                if (opt("smartScoreCompare"))
                    list($greater, $less) = array("better", "worse");
                else
                    list($greater, $less) = array("worse", "better");
            }
            echo $hth->search_trow("{$r->search_keyword()}:>{$r->typical_score()}", "at least one completed review has $r->name_html score $greater than {$r->typical_score()}" . $hint);
            echo $hth->search_trow("{$r->search_keyword()}:2<={$r->typical_score()}", "at least two completed reviews have $r->name_html score $less than or equal to {$r->typical_score()}");
            if ($roundname)
                echo $hth->search_trow("{$r->search_keyword()}:$roundname>{$r->typical_score()}", "at least one completed review in round " . htmlspecialchars($roundname) . " has $r->name_html score $greater than {$r->typical_score()}");
            echo $hth->search_trow("{$r->search_keyword()}:ext>{$r->typical_score()}", "at least one completed external review has $r->name_html score $greater than {$r->typical_score()}");
            echo $hth->search_trow("{$r->search_keyword()}:pc:2>{$r->typical_score()}", "at least two completed PC reviews have $r->name_html score $greater than {$r->typical_score()}");
            echo $hth->search_trow("{$r->search_keyword()}:sylvia={$r->typical_score()}", "“sylvia” (reviewer name/email) gave $r->name_html score {$r->typical_score()}");
            $t = "";
        }
        if (count($farr[1])) {
            $r = $farr[1][0];
            echo $hth->search_trow($r->abbreviation1() . ":finger", "at least one completed review has “finger” in the $r->name_html field");
            echo $hth->search_trow("{$r->search_keyword()}:finger", "other abbreviations accepted");
            echo $hth->search_trow("{$r->search_keyword()}:any", "at least one completed review has text in the $r->name_html field");
        }

        if (count($farr[0])) {
            $r = $farr[0][0];
            echo $hth->tgroup("<a href=\"" . hoturl("help", "t=formulas") . "\">Formulas</a>");
            echo $hth->search_trow("formula:all({$r->search_keyword()}={$r->typical_score()})",
                "all reviews have $r->name_html score {$r->typical_score()}<br />"
                . "<span class='hint'><a href=\"" . hoturl("help", "t=formulas") . "\">Formulas</a> can express complex numerical queries across review scores and preferences.</span>");
            echo $hth->search_trow("f:all({$r->search_keyword()}={$r->typical_score()})", "“f” is shorthand for “formula”");
            echo $hth->search_trow("formula:var({$r->search_keyword()})>0.5", "variance in {$r->search_keyword()} is above 0.5");
            echo $hth->search_trow("formula:any({$r->search_keyword()}={$r->typical_score()} && pref<0)", "at least one reviewer had $r->name_html score {$r->typical_score()} and review preference &lt; 0");
        }

        echo $hth->tgroup("Display");
        echo $hth->search_trow("show:tags show:conflicts", "show tags and PC conflicts in the results");
        echo $hth->search_trow("hide:title", "hide title in the results");
        if (count($farr[0])) {
            $r = $farr[0][0];
            echo $hth->search_trow("show:max({$r->search_keyword()})", "show a <a href=\"" . hoturl("help", "t=formulas") . "\">formula</a>");
            echo $hth->search_trow("sort:{$r->search_keyword()}", "sort by score");
            echo $hth->search_trow("sort:\"{$r->search_keyword()} variance\"", "sort by score variance");
        }
        echo $hth->search_trow("sort:-status", "sort by reverse status");
        echo $hth->search_trow("edit:#discuss", "edit the values for tag “#discuss”");
        echo $hth->search_trow("search1 THEN search2", "like “search1 OR search2”, but papers matching “search1” are grouped together and appear earlier in the sorting order");
        echo $hth->search_trow("1-5 THEN 6-10 show:compact", "display searches in compact columns");
        echo $hth->search_trow("search1 HIGHLIGHT search2", "search for “search1”, but <span class=\"taghl highlightmark\">highlight</span> papers in that list that match “search2” (also try HIGHLIGHT:pink, HIGHLIGHT:green, HIGHLIGHT:blue)");

        echo $hth->end_table();
    }
}
