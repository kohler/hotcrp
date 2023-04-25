<?php
// o_attachments.php -- HotCRP helper class for attachments options
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Attachments_PaperOption extends PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "prefer-row");
    }

    function has_document() {
        return true;
    }
    function has_attachments() {
        return true;
    }

    function attachment(PaperValue $ov, $name) {
        return $ov->document_set()->document_by_filename($name);
    }

    function value_compare($av, $bv) {
        return ($av && $av->value_count() ? 1 : 0) <=> ($bv && $bv->value_count() ? 1 : 0);
    }
    function value_dids(PaperValue $ov) {
        $j = null;
        foreach ($ov->data_list() as $d) {
            if ($d !== null && str_starts_with($d, "{"))
                $j = json_decode($d);
        }
        if ($j && isset($j->all_dids)) {
            return $j->all_dids;
        } else {
            $values = $ov->value_list();
            $data = $ov->data_list();
            array_multisort($data, SORT_NUMERIC, $values);
            return $values;
        }
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $attachments = [];
        foreach ($ov->documents() as $doc) {
            if (($dj = $pex->document_json($doc)))
                $attachments[] = $dj;
        }
        return empty($attachments) ? null : $attachments;
    }
    function value_store(PaperValue $ov, PaperStatus $ps) {
        $docs = $ov->anno("documents") ?? [];
        $dids = [];
        foreach ($docs as $dj) {
            if (is_int($dj)) {
                $dids[] = $dj;
            } else if (($doc = $ps->upload_document($dj, $this))) {
                $dids[] = $doc->paperStorageId;
            }
        }
        if (empty($dids)) {
            $ov->set_value_data([], []);
        } else if (count($dids) == 1) {
            $ov->set_value_data([$dids[0]], [null]);
        } else {
            // Put the ordered document IDs in the first option’s sort data.
            // This is so (1) the link from option -> PaperStorage is visible
            // directly via PaperOption.value, (2) we can still support
            // duplicate uploads.
            $uniqdids = array_values(array_unique($dids, SORT_NUMERIC));
            $datas = array_fill(0, count($uniqdids), null);
            $datas[0] = json_encode(["all_dids" => $dids]);
            $ov->set_value_data($uniqdids, $datas);
        }
    }

    /** @param string $prefix
     * @param int $documentType
     * @param list<int|object> $dlist
     * @param MessageSet $ms
     * @return list<int|object> */
    static function parse_qreq_prefix(PaperInfo $prow, Qrequest $qreq,
                                      $prefix, $documentType, $dlist, $ms) {
        $dxlist = [];
        for ($ctr = 1; isset($qreq["{$prefix}:{$ctr}"]); ++$ctr) {
            $name = "{$prefix}:{$ctr}";
            $did = $qreq[$name];
            $thisdoc = null;
            if (ctype_digit($did)) {
                $did = intval($did);
                for ($idx = 0; $idx !== count($dlist); ++$idx) {
                    $d = $dlist[$idx];
                    if ($d === $did
                        || (is_object($d) && $d->paperStorageId === $did)) {
                        $thisdoc = $d;
                        array_splice($dlist, $idx, 1);
                        break;
                    }
                }
            }
            if ($qreq["{$name}:remove"]) {
                continue;
            }
            if (DocumentInfo::has_request_for($qreq, $name)) {
                $thisdoc = DocumentInfo::make_request($qreq, $name, $prow->paperId, $documentType, $prow->conf);
                if ($thisdoc && $thisdoc->has_error()) {
                    foreach ($thisdoc->message_list() as $mi) {
                        $ms->append_item($mi->with_landmark($thisdoc->error_filename()));
                    }
                }
            }
            if ($thisdoc) {
                $dxlist[] = $thisdoc;
            }
        }
        return $dxlist;
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $ov = PaperValue::make($prow, $this, -1);
        $docs = self::parse_qreq_prefix($prow, $qreq, $this->formid, $this->id,
                                        $this->value_dids($prow->force_option($this)),
                                        $ov->message_set());
        $ov->set_anno("documents", $docs);
        return $ov;
    }
    function parse_json(PaperInfo $prow, $j) {
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if ($j === null) {
            return null;
        } else {
            $ja = is_array($j) ? $j : [$j];
            $ov = PaperValue::make($prow, $this, -1);
            $ov->set_anno("documents", $ja);
            foreach ($ja as $docj) {
                if (is_object($docj) && isset($docj->error_html)) {
                    $ov->error("<5>" . $docj->error_html);
                } else if (!DocumentInfo::check_json_upload($docj)) {
                    $ov->estop("<0>Format error");
                }
            }
            return $ov;
        }
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        // XXX does not consider $reqov
        $max_size = $this->max_size ?? $this->conf->upload_max_filesize(true);
        $title = $this->title_html();
        if ($max_size > 0) {
            $title .= ' <span class="n">(max ' . unparse_byte_size($max_size) . ' per file)</span>';
        }
        $pt->print_editable_option_papt($this, $title, ["id" => $this->readable_formid(), "for" => false]);
        echo '<div class="papev has-editable-attachments" data-document-prefix="', $this->formid, '" data-dtype="', $this->id, '" id="', $this->formid, ':attachments"';
        if ($this->max_size > 0) {
            echo ' data-document-max-size="', (int) $this->max_size, '"';
        }
        echo '>';
        $readonly = !$this->test_editable($ov->prow);
        foreach ($ov->document_set() as $i => $doc) {
            $ctr = $i + 1;
            $oname = "{$this->formid}:{$ctr}";
            echo '<div class="has-document" data-dtype="', $this->id,
                '" data-document-name="', $oname, '"><div class="document-file">',
                Ht::hidden($oname, $doc->paperStorageId),
                $doc->link_html(htmlspecialchars($doc->member_filename())),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc))) {
                echo $stamps;
            }
            echo '</div>';
            if (!$readonly) {
                echo '<div class="document-actions">', Ht::button("Delete", ["class" => "btn-link ui js-remove-document"]), '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        if (!$readonly) {
            echo Ht::button("Add attachment", ["class" => "ui js-add-attachment", "data-editable-attachments" => "{$this->formid}:attachments"]);
        } else if ($ov->document_set()->is_empty()) {
            echo '<p>(No uploads)</p>';
        }
        echo "</div>\n\n";
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $ts = [];
        foreach ($ov->document_set() as $d) {
            if ($fr->want_text()) {
                $ts[] = $d->member_filename();
            } else {
                $linkname = htmlspecialchars($d->member_filename());
                if ($fr->want_list()) {
                    $dif = DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE;
                } else if ($this->page_order() >= 2000) {
                    $dif = DocumentInfo::L_SMALL;
                } else {
                    $dif = 0;
                    $linkname = '<span class="pavfn">' . $this->title_html() . '</span>/' . $linkname;
                }
                $t = $d->link_html($linkname, $dif);
                if ($d->is_archive()) {
                    $t = '<span class="archive foldc"><a href="" class="ui js-expand-archive q">' . expander(null, 0) . '</a> ' . $t . '</span>';
                }
                $ts[] = $t;
            }
        }
        if (!empty($ts)) {
            if ($fr->want_text()) {
                $fr->set_text(join("; ", $ts));
            } else if ($fr->want_list_row()) {
                $fr->set_html(join("; ", $ts));
            } else {
                $fr->set_html('<ul class="x"><li class="od">' . join('</li><li class="od">', $ts) . '</li></ul>');
            }
            if ($fr->for_page() && $this->page_order() < 2000) {
                $fr->title = false;
                $v = '';
                if ($fr->table && $fr->user->view_option_state($ov->prow, $this) === 1) {
                    $v = ' fx8';
                }
                $fr->value = "<div class=\"pgsm{$v}\">{$fr->value}</div>";
            }
        } else if ($fr->verbose()) {
            $fr->set_text("None");
        }
    }

    function search_examples(Contact $viewer, $context) {
        return [
            $this->has_search_example(),
            new SearchExample(
                $this, $this->search_keyword() . ":{comparator}",
                "<0>submission has three or more {title} attachments",
                new FmtArg("comparator", ">2")
            ),
            new SearchExample(
                $this, $this->search_keyword() . ":\"{filename}\"",
                "<0>submission has {title} attachment matching “{filename}”",
                new FmtArg("filename", "*.gif")
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (preg_match('/\A[-+]?\d+\z/', $sword->cword)) {
            return new DocumentCount_SearchTerm($srch->user, $this, $sword->compar, (int) $sword->cword);
        } else if ($sword->compar === "" || $sword->compar === "!=") {
            return new DocumentName_SearchTerm($srch->user, $this, $sword->compar !== "!=", $sword->cword);
        } else {
            return null;
        }
    }
    function present_script_expression() {
        return ["type" => "document_count", "formid" => $this->formid, "dtype" => $this->id];
    }
}
