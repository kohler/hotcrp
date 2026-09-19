<?php
// o_attachments.php -- HotCRP helper class for attachments options
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Attachments_PaperOption extends PaperOption {
    // bound on attachments one request may list for a field
    const MAX_PARSE_COUNT = 100;

    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args, "prefer-row");
    }

    function has_document() {
        return true;
    }
    function has_attachments() {
        return true;
    }
    function view_option_schema() {
        return ["format=type^"];
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
        }
        $values = $ov->value_list();
        $data = $ov->data_list();
        array_multisort($data, SORT_NUMERIC, $values);
        return $values;
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
            } else if (($doc = $ps->upload_document($dj, $this->id))) {
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

    /** Return the documents a form lists under `{$prefix}:N` given existing
     * documents `$dlist`, or null if they come to more than `$max` (or
     * clearly would, in which case no listed document is even looked up).
     * @param string $prefix
     * @param int $documentType
     * @param list<int|object> $dlist
     * @param int $max
     * @param MessageSet $ms
     * @return ?list<int|object> */
    static function parse_qreq_prefix(PaperInfo $prow, Qrequest $qreq,
                                      $prefix, $documentType, $dlist, $max, $ms) {
        // give up before any per-key work if the form has more keys than it
        // could need: it need only name each existing document once
        // (perhaps to delete it) besides new ones
        for ($ctr = 1; isset($qreq["{$prefix}:{$ctr}"]); ++$ctr) {
            if ($ctr > $max + count($dlist)) {
                return null;
            }
        }
        $dxlist = [];
        for ($ctr = 1; isset($qreq["{$prefix}:{$ctr}"]); ++$ctr) {
            $name = "{$prefix}:{$ctr}";
            $thisdoc = null;
            if (($did = stoi($qreq[$name])) > 0) {
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
            if (friendly_boolean($qreq["{$name}:delete"])) {
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
        $dlist = array_merge($dlist, $dxlist);
        return count($dlist) <= $max ? $dlist : null;
    }

    /** @param int $max
     * @return PaperValue */
    private function make_too_many_estop(PaperInfo $prow, $max) {
        return PaperValue::make_estop($prow, $this, $this->conf->_("<0>Too many attachments (at most {max})", new FmtArg("max", $max)));
    }

    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $oldov = $prow->option($this);
        $ov = PaperValue::make($prow, $this, -1);
        // reject overlong lists before the listed documents are stored
        // (but let a submission that somehow has more keep that many)
        $dids = $oldov ? $this->value_dids($oldov) : [];
        $max = max(self::MAX_PARSE_COUNT, count($dids));
        $docs = self::parse_qreq_prefix($prow, $qreq, $this->formid, $this->id,
                                        $dids, $max, $ov->message_set());
        if ($docs === null) {
            return $this->make_too_many_estop($prow, $max);
        }
        $ov->set_anno("documents", $docs);
        return $ov;
    }
    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if ($j === false) {
            return PaperValue::make($prow, $this);
        } else if ($j === null) {
            return null;
        }
        $ja = is_array($j) ? $j : [$j];
        // reject overlong lists as `parse_qreq` does -- but, as with upload
        // size limits, exempt a site administrator (say for a bulk import)
        if (!$user->privChair) {
            $oldov = $prow->option($this);
            $max = max(self::MAX_PARSE_COUNT, $oldov ? count($this->value_dids($oldov)) : 0);
            if (count($ja) > $max) {
                return $this->make_too_many_estop($prow, $max);
            }
        }
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
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        // XXX does not consider $reqov
        $max_size = $this->max_size ?? $this->conf->upload_max_filesize(true);
        $title = $this->title_html();
        if ($max_size > 0) {
            $title .= ' <span class="n">(max ' . unparse_byte_size($max_size) . ' per file)</span>';
        }
        $pt->print_editable_option_papt($this, $title, [
            "id" => $this->readable_formid(), "for" => false, "fieldset" => true
        ]);
        echo '<div class="papev has-editable-attachments" data-document-prefix="', $this->formid, '" data-dt="', $this->id, '" id="', $this->formid, ':attachments"';
        if ($this->max_size > 0) {
            echo ' data-document-max-size="', (int) $this->max_size, '"';
        }
        echo '>';
        // Need option title for accessibility
        $otitle = $this->edit_title($ov->prow);
        foreach ($ov->document_set() as $i => $doc) {
            $ctr = $i + 1;
            $oname = "{$this->formid}:{$ctr}";
            $dfn = $doc->member_filename();
            $aria_dfn = $dfn . ($doc->size() > 0 ? " (" . unparse_byte_size($doc->size()) . ")" : "") . " ({$otitle})";
            echo '<div class="has-document" data-dt="', $this->id,
                '" data-document-name="', $oname, '"><div class="document-file">',
                Ht::hidden($oname, $doc->paperStorageId),
                $doc->link_html(htmlspecialchars($dfn), 0, null, ["aria-label" => $aria_dfn]),
                '</div><div class="document-stamps">';
            if (($stamps = PaperTable::pdf_stamps_html($doc))) {
                echo $stamps;
            }
            echo '</div><div class="document-actions">',
                Ht::button("Delete", [
                    "class" => "link ui js-remove-document",
                    "aria-label" => "Delete {$aria_dfn}"
                ]), '</div></div>';
        }
        echo '</div><div class="mt-2">',
            Ht::button("Add attachment", [
                "class" => "ui js-add-attachment",
                "data-editable-attachments" => "{$this->formid}:attachments",
                "aria-label" => "Add attachment ({$otitle})",
                "aria-describedby" => "sf-{$this->formid}:d"
            ]),
            "</div></fieldset>\n\n";
    }
    function print_web_edit_hidden(PaperTable $pt, $ov) {
        echo '<fieldset name="', $this->formid, '" role="none" hidden>';
        foreach ($ov->document_set() as $i => $doc) {
            $ctr = $i + 1;
            $oname = "{$this->formid}:{$ctr}";
            echo '<div class="has-document" data-document-name="', $oname, '">',
                Ht::hidden($oname, $doc->paperStorageId, ["disabled" => true]),
                '</div>';
        }
        echo '</fieldset>';
    }

    function render(FieldRender $fr, PaperValue $ov) {
        $ts = [];
        foreach ($ov->document_set() as $d) {
            $ts[] = Document_PaperOption::render_document($fr, $this, $d);
        }
        if (empty($ts)) {
            if ($fr->verbose()) {
                $fr->set_text("None");
            }
            return;
        }
        if ($fr->want(FieldRender::CFTEXT)) {
            $fr->set_text(join("; ", $ts));
        } else if ($fr->want_all(FieldRender::CFLIST | FieldRender::CFROW)) {
            $fr->set_html('<ul class="semi"><li>' . join("</li><li>", $ts) . '</li></ul>');
        } else {
            $fr->set_html('<ul class="x"><li class="od">' . join('</li><li class="od">', $ts) . '</li></ul>');
        }
        if ($fr->want(FieldRender::CFPAGE) && $this->display() === PaperOption::DISP_TOP) {
            $fr->title = false;
            $v = '';
            if ($fr->table && $fr->user->view_option_state($ov->prow, $this) === 1) {
                $v = ' fx8';
            }
            $fr->value = "<div class=\"pgsm{$v}\">{$fr->value}</div>";
        }
    }

    function search_examples(Contact $viewer, $venue) {
        return [
            $this->has_search_example(),
            $this->make_search_example(
                $this->search_keyword() . ":{comparator}",
                "<0>submission has three or more {title} attachments",
                new FmtArg("comparator", ">2", 0)
            ),
            $this->make_search_example(
                $this->search_keyword() . ":{filename}",
                "<0>submission has {title} attachment matching ‘{filename}’",
                new FmtArg("filename", "*.gif", 0)
            )
        ];
    }
    function parse_search(SearchWord $sword, PaperSearch $srch) {
        if (preg_match('/\A[-+]?\d+\z/', $sword->cword)) {
            return new DocumentCount_SearchTerm($srch->user, $this, $sword->compar, (int) $sword->cword);
        } else if ($sword->compar === "" || $sword->compar === "!=") {
            return new DocumentName_SearchTerm($srch->user, $this, $sword->compar !== "!=", $sword->cword);
        }
        return null;
    }
    function present_script_expression() {
        return ["type" => "document_count", "fieldset" => $this->formid];
    }
}
