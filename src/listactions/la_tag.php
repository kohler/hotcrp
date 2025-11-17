<?php
// listactions/la_tag.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Tag_ListAction extends ListAction {
    /** @var ?string */
    private $tagfn;

    function __construct(Conf $conf, $uf) {
        if (($slash = strpos($uf->name, "/")) > 0) {
            $this->tagfn = substr($uf->name, $slash + 1);
        }
    }

    static function render(PaperList $pl, Qrequest $qreq, $plft) {
        // tagtype cell
        $tagopt = ["a" => "Add", "d" => "Remove", "s" => "Define", "xxxa" => null, "ao" => "Add to order", "aos" => "Add to gapless order", "so" => "Define order", "sos" => "Define gapless order", "sor" => "Define random order"];
        $tagextra = ["class" => "js-submit-action-info-tag"];
        if ($pl->user->privChair) {
            $tagopt["xxxb"] = null;
            $tagopt["cr"] = "Calculate rank";
        }

        // tag name cell
        $t = "";
        if ($pl->user->privChair) {
            $t .= '<span class="fx98"><button type="button" class="q ui js-foldup" data-fold-target="99">'
                . expander(null, 99) . "</button></span>";
        }
        $t .= '<span class="px-1">tag<span class="fn98">(s)</span></span> '
            . Ht::entry("tag", $qreq->tag,
                        ["size" => 15, "class" => "want-focus js-autosubmit js-submit-action-info-tag need-suggest tags", "data-submit-fn" => "tag"])
            . $pl->action_submit("tag");
        if ($pl->user->privChair) {
            $t .= '<div class="fx99"><div style="margin:2px 0">'
                . Ht::checkbox("tagcr_gapless", 1, !!$qreq->tagcr_gapless, ["class" => "ml-0"])
                . "&nbsp;" . Ht::label("Gapless order") . "</div>"
                . '<div style="margin:2px 0">Using: &nbsp;'
                . Ht::select("tagcr_method", PaperRank::default_method_selector(), $qreq->tagcr_method)
                . "</div>"
                . '<div style="margin:2px 0">Source tag: &nbsp;~'
                . Ht::entry("tagcr_source", $qreq->tagcr_source, ["size" => 15])
                . "</div></div>";
        }

        $plft->tab_attr["class"] = "has-fold fold98c fold99c ui-fold js-tag-list-action";
        $plft->content = Ht::select("tagfn", $tagopt, $qreq->tagfn, $tagextra) . " " . $t;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_edit_some_tag();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $papers = $ssel->selection();
        $gapless = friendly_boolean($qreq->gapless);

        $act = $this->tagfn ?? $qreq->tagfn;
        if ($act === "s" || $act === "define") {
            $act = "s";
        } else if ($act === "d" || $act === "remove" || $act === "clear") {
            $act = "d";
        } else if ($act === "a" || $act === "add") {
            $act = "a";
        } else if ($act === "so" || $act === "define_order") {
            if (friendly_boolean($qreq->random)) {
                $act = $gapless ? "sosr" : "sor";
            } else {
                $act = $gapless ? "sos" : "so";
            }
        } else if ($act === "sos" || $act === "define_gapless_order") {
            $act = "sos";
        } else if ($act === "sor" || $act === "define_random_order") {
            $act = "sor";
        } else if ($act === "ao" || $act === "add_order") {
            $act = $gapless ? "aos" : "ao";
        } else if ($act === "aos" || $act === "add_gapless_order") {
            $act = "aos";
        } else if ($act === "cr" || $act === "calculate_rank") {
            $act = "cr";
            $gapless = $gapless ?? friendly_boolean($qreq->tagcr_gapless);
        } else {
            $act = "";
        }

        $tagreq = trim(str_replace(",", " ", (string) $qreq->tag));
        $tags = preg_split('/\s+/', $tagreq);

        if ($act === "da") {
            $otags = $tags;
            foreach ($otags as $t) {
                $tags[] = "all~" . preg_replace('/\A.*~([^~]+)\z/', '$1', $t);
            }
            $act = "d";
        } else if ($act === "sor" || $act === "sosr") {
            shuffle($papers);
        }

        $x = ["action,paper,tag\n"];
        if (str_starts_with($act, "s")) {
            foreach ($tags as $t) {
                $x[] = "cleartag,all," . Tagger::tv_tag($t) . "\n";
            }
        }
        if ($act === "s" || $act === "a") {
            $action = "tag";
        } else if ($act === "d") {
            $action = "cleartag";
        } else if ($act === "so" || $act === "sor" || $act === "ao") {
            $action = "nexttag";
        } else if ($act === "sos" || $act === "sosr" || $act === "aos") {
            $action = "seqnexttag";
        } else {
            $action = null;
        }

        $assignset = new AssignmentSet($user);
        $assignset->set_overrides(Contact::OVERRIDE_CONFLICT); // i.e., not other overrides
        if ($tagreq === "" || $act === "") {
            if ($act === "") {
                $assignset->message_set()->append_item(MessageItem::error_at("tagfn", isset($qreq->tagfn) ? "<0>Parameter error" : "<0>Parameter missing"));
            }
            if ($tagreq === "") {
                $assignset->message_set()->append_item(MessageItem::error_at("tag", "<0>Tags required"));
            }
        } else if (!empty($papers) && $action) {
            foreach ($papers as $p) {
                foreach ($tags as $t) {
                    $x[] = "{$action},{$p},{$t}\n";
                }
            }
            $assignset->parse(join("", $x));
        } else if (!empty($papers) && $act === "cr" && $user->privChair) {
            $source_tag = $qreq->tagcr_source ?? $qreq->source_tag;
            $source_tag = trim((string) $source_tag);
            if ($source_tag === "") {
                $source_tag = (substr($tagreq, 0, 2) === "~~" ? substr($tagreq, 2) : $tagreq);
            }
            $tagger = new Tagger($user);
            if ($tagger->check($tagreq, Tagger::NOPRIVATE | Tagger::NOVALUE)
                && $tagger->check($source_tag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
                $r = new PaperRank($papers);
                $r->load_user_tag_ranks($user->conf, $source_tag);
                $r->set_gapless($gapless);
                $r->set_printable_header($qreq, "Search", "search");
                $r->run($qreq->tagcr_method ?? $qreq->rank_method);
                $assignset->set_overrides(Contact::OVERRIDE_CONFLICT | Contact::OVERRIDE_TAG_CHECKS);
                $assignset->parse($r->unparse_tag_assignment($tagreq));
                if ($qreq->q === "") {
                    $qreq->q = "order:{$tagreq}";
                }
            } else {
                $assignset->error($tagger->error_ftext());
            }
        }

        if ($qreq->page() === "api") {
            return Assign_API::complete($assignset, $qreq);
        }

        $assignset->execute();
        $assignset->feedback_msg(AssignmentSet::FEEDBACK_CHANGE);
        $args = ["atab" => "tag"] + $qreq->subset_as_array("tag", "tagfn", "tagcr_method", "tagcr_source", "tagcr_gapless");
        return new Redirection($user->conf->selfurl($qreq, $args, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
    }
}
