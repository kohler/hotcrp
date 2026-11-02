<?php
// t_placeholderdocuments.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class PlaceholderTest_PaperOption extends Document_PaperOption {
    /** @var array<int,?string> */
    static public $content = [];
    /** @var int */
    static public $nfetch = 0;
    /** @var ?callable(DocumentInfo):void */
    static public $during_fetch;
    /** @var ?array{bool,int,string|false} */
    static public $validated;

    function fetch_document(DocumentInfo $doc) {
        ++self::$nfetch;
        if (($f = self::$during_fetch)) {
            self::$during_fetch = null;
            call_user_func($f, $doc);
        }
        $c = self::$content[$doc->paperId] ?? null;
        if ($c === null) {
            $doc->error("<0>Remote document unavailable");
            return false;
        }
        $doc->set_simple_content($c);
        return true;
    }

    function validate_document(DocumentInfo $doc) {
        self::$validated = [$doc->is_placeholder(), $doc->size(), $doc->binary_hash()];
        return parent::validate_document($doc);
    }

    function parse_json_user(PaperInfo $prow, $j, Contact $user) {
        if ($j instanceof DocumentInfo) {
            $ov = PaperValue::make($prow, $this, PaperValue::NEWDOC_VALUE);
            $ov->set_anno("document", $j);
            return $ov;
        }
        return parent::parse_json_user($prow, $j, $user);
    }

    function value_store(PaperValue $ov, PaperStatus $ps) {
        // a new placeholder belongs to this field and submission
        if ($ov->value === PaperValue::NEWDOC_VALUE
            && ($doc = $ov->anno("document")) instanceof DocumentInfo
            && $doc->paperStorageId === 0) {
            $doc->set_paper($ov->prow)->set_document_type($this->id);
        }
        parent::value_store($ov, $ps);
    }
}

class PlaceholderDocuments_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var mixed */
    private $old_fixed_options;
    /** @var mixed */
    private $old_intrinsic_options;
    /** @var ?int */
    private $new_pid;

    const OPTID = 9001;
    const PID = 1;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->old_fixed_options = $conf->opt("fixedOptions");
        $this->old_intrinsic_options = $conf->opt("intrinsicOptions");
        $conf->set_opt("fixedOptions", [(object) [
            "id" => self::OPTID, "name" => "Remote document",
            "type" => "document", "json_key" => "remote_document",
            "function" => "+PlaceholderTest_PaperOption", "order" => 9001
        ]]);
        $conf->invalidate_caches("options");
        $conf->qe("delete from PaperOption where optionId=?", self::OPTID);
        $conf->qe("delete from PaperStorage where documentType=?", self::OPTID);
    }

    /** @return int */
    private function make_linked_placeholder() {
        $prow = $this->conf->checked_paper_by_id(self::PID);
        $doc = DocumentInfo::make_placeholder($this->conf, "text/plain")
            ->set_paper($prow)
            ->set_document_type(self::OPTID)
            ->set_filename("remote.txt");
        xassert($doc->save());
        xassert($doc->paperStorageId > 1);
        xassert($doc->is_placeholder());
        $this->conf->qe("delete from PaperOption where paperId=? and optionId=?", self::PID, self::OPTID);
        $this->conf->qe("insert into PaperOption (paperId, optionId, value, data) values (?, ?, ?, null)", self::PID, self::OPTID, $doc->paperStorageId);
        return $doc->paperStorageId;
    }

    /** @return DocumentInfo */
    private function load_document() {
        $prow = $this->conf->checked_paper_by_id(self::PID);
        $doc = $prow->force_option(self::OPTID)->document(0);
        xassert(!!$doc);
        return $doc;
    }

    /** @return array{string,int,?string} */
    private function storage_row($docid) {
        $row = $this->conf->fetch_first_row("select sha1, size, crc32 from PaperStorage where paperStorageId=?", $docid);
        return [$row[0], (int) $row[1], $row[2]];
    }

    function test_save_placeholder() {
        $docid = $this->make_linked_placeholder();
        xassert_eqq($this->storage_row($docid), ["", DocumentInfo::SIZE_PLACEHOLDER, null]);
    }

    function test_placeholder_does_not_fetch() {
        $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Placeholder content\n";

        $doc = $this->load_document();
        xassert($doc->is_placeholder());
        xassert(!$doc->has_hash());
        xassert_eqq($doc->binary_hash(), false);
        xassert_eqq($doc->text_hash(), false);
        xassert_eqq($doc->size(), DocumentInfo::SIZE_PLACEHOLDER);
        xassert_eqq($doc->crc32(), false);
        xassert_eqq($doc->npages(), null);
        xassert_not_str_contains($doc->link_html("Remote"), "dlsize");

        $pex = new PaperExport($this->u_chair);
        $dj = $pex->document_json($doc);
        xassert_eqq($dj->placeholder ?? null, true);
        xassert(!isset($dj->hash));
        xassert(!isset($dj->size));

        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 0);
        xassert($doc->is_placeholder());
    }

    function test_unsaved_placeholder_does_not_fetch() {
        $prow = $this->conf->checked_paper_by_id(self::PID);
        $nrows = $this->conf->fetch_ivalue("select count(*) from PaperStorage where documentType=?", self::OPTID);
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Unsaved content\n";

        $doc = DocumentInfo::make_placeholder($this->conf, "text/plain")
            ->set_paper($prow)
            ->set_document_type(self::OPTID);
        xassert(!$doc->ensure_content());
        xassert($doc->has_error());
        xassert($doc->is_placeholder());
        xassert_eqq($doc->paperStorageId, 0);
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 0);
        xassert_eqq($this->conf->fetch_ivalue("select count(*) from PaperStorage where documentType=?", self::OPTID), $nrows);
    }

    function test_completion_preserves_concurrent_changes() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$content[self::PID] = "Concurrent content\n";

        // while `$doc` is in memory, another request changes columns that
        // completion should not overwrite
        $doc = $this->load_document();
        $this->conf->qe("update PaperStorage set inactive=1, timeReferenced=12345 where paperStorageId=?", $docid);
        xassert($doc->ensure_content());
        xassert(!$doc->is_placeholder());
        $row = $this->conf->fetch_first_row("select inactive, timeReferenced, paperId, size from PaperStorage where paperStorageId=?", $docid);
        xassert_eqq((int) $row[0], 1);
        xassert_eqq((int) $row[1], 12345);
        xassert_eqq((int) $row[2], self::PID);
        xassert_eqq((int) $row[3], strlen("Concurrent content\n"));
        $this->conf->qe("update PaperStorage set inactive=0 where paperStorageId=?", $docid);
    }

    function test_unhashed_document_is_not_placeholder() {
        // a document with no hash and unknown size, but no placeholder
        // marker, is not fetched from its field
        $docid = $this->make_linked_placeholder();
        $this->conf->qe("update PaperStorage set size=-1 where paperStorageId=?", $docid);
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Unmarked content\n";

        $doc = $this->load_document();
        xassert(!$doc->is_placeholder());
        xassert(!$doc->ensure_content());
        xassert_eqq($doc->size(), -1);
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 0);
        xassert_eqq($this->storage_row($docid), ["", -1, null]);
    }

    function test_fetch_failure() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = null;

        $doc = $this->load_document();
        xassert(!$doc->ensure_content());
        xassert($doc->has_error());
        xassert_str_contains($doc->message_set()->full_feedback_text(), "Remote document unavailable");
        xassert($doc->is_placeholder());
        xassert_eqq($doc->size(), DocumentInfo::SIZE_PLACEHOLDER);
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
        xassert_eqq($this->storage_row($docid), ["", DocumentInfo::SIZE_PLACEHOLDER, null]);
    }

    function test_fetch_completes_placeholder() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        $content = "Placeholder content\n";
        PlaceholderTest_PaperOption::$content[self::PID] = $content;

        // a stale copy of the placeholder, loaded before completion
        $stale = $this->load_document();

        $doc = $this->load_document();
        PlaceholderTest_PaperOption::$validated = null;
        xassert($doc->ensure_content());
        xassert(!$doc->is_placeholder());
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
        xassert_eqq($doc->content(), $content);
        xassert_eqq($doc->size(), strlen($content));
        $hash = $doc->binary_hash();
        xassert_eqq($hash, $doc->content_binary_hash());
        // validation saw a complete document
        xassert_eqq(PlaceholderTest_PaperOption::$validated, [false, strlen($content), $hash]);
        xassert_eqq($this->storage_row($docid), [$hash, strlen($content), hash("crc32b", $content, true)]);

        // a fresh copy is complete and needs no fetch
        $doc2 = $this->load_document();
        xassert(!$doc2->is_placeholder());
        xassert_eqq($doc2->binary_hash(), $hash);
        xassert_eqq($doc2->content(), $content);

        // the stale copy adopts the completed record without fetching
        xassert($stale->is_placeholder());
        xassert_eqq($stale->content(), $content);
        xassert(!$stale->is_placeholder());
        xassert_eqq($stale->binary_hash(), $hash);
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);

        // later content changes do not affect the completed document
        PlaceholderTest_PaperOption::$content[self::PID] = "Changed content\n";
        $doc3 = $this->load_document();
        xassert_eqq($doc3->content(), $content);
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
    }

    function test_concurrent_completion() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Loser content\n";

        // while `$doc` fetches, a concurrent request completes the same
        // placeholder with different content
        PlaceholderTest_PaperOption::$during_fetch = function ($doc) {
            $old = PlaceholderTest_PaperOption::$content[self::PID];
            PlaceholderTest_PaperOption::$content[self::PID] = "Winner content\n";
            $other = $this->load_document();
            xassert($other->ensure_content());
            PlaceholderTest_PaperOption::$content[self::PID] = $old;
        };

        $doc = $this->load_document();
        xassert($doc->ensure_content());
        xassert(!$doc->is_placeholder());
        xassert_eqq($doc->content(), "Winner content\n");
        xassert_eqq($doc->binary_hash(), $doc->content_binary_hash());
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 2);
        xassert_eqq($this->storage_row($docid)[0], $doc->binary_hash());
    }

    function test_concurrent_completion_same_content() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        $content = "Same content\n";
        PlaceholderTest_PaperOption::$content[self::PID] = $content;

        // a concurrent request completes the placeholder with the same content
        PlaceholderTest_PaperOption::$during_fetch = function ($doc) {
            $other = $this->load_document();
            xassert($other->ensure_content());
        };

        $doc = $this->load_document();
        xassert($doc->ensure_content());
        xassert(!$doc->is_placeholder());
        xassert_eqq($doc->content(), $content);
        xassert_eqq($doc->size(), strlen($content));
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 2);
        xassert_eqq($this->storage_row($docid)[0], $doc->binary_hash());
        xassert_eqq($this->storage_row($docid)[1], strlen($content));
    }

    function test_download_fetches() {
        $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Download content\n";

        $doc = $this->load_document();
        $dopt = new Downloader;
        xassert($doc->prepare_download($dopt));
        xassert(!$doc->is_placeholder());
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
        xassert_eqq($doc->content(), "Download content\n");

        // a failed fetch cannot be downloaded
        $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$content[self::PID] = null;
        $doc = $this->load_document();
        xassert(!$doc->prepare_download(new Downloader));
        xassert($doc->is_placeholder());
    }

    function test_validation_on_completion() {
        $docid = $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$content[self::PID] = "";

        // empty content is fine for a document field with no type
        // restrictions, but the placeholder must still get a hash
        $doc = $this->load_document();
        xassert($doc->ensure_content());
        xassert_eqq($doc->size(), 0);
        xassert_eqq($this->storage_row($docid)[1], 0);
    }

    function test_zip_completes_placeholders() {
        $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[self::PID] = "Zipped content\n";
        $doc = $this->load_document();
        $docset = new DocumentInfoSet("placeholders.zip");
        $docset->add_as($doc, "remote.txt");
        $docset->add_string_as("Other content\n", "other.txt");
        $zdoc = $docset->make_zip_document();
        xassert(!!$zdoc);
        xassert(!$doc->is_placeholder());
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
        $zip = new ZipArchive;
        xassert($zip->open($zdoc->content_file()) === true);
        xassert_eqq($zip->getFromName("remote.txt"), "Zipped content\n");
        xassert_eqq($zip->getFromName("other.txt"), "Other content\n");
        $zip->close();

        // a failed fetch prevents the zip
        $this->make_linked_placeholder();
        PlaceholderTest_PaperOption::$content[self::PID] = null;
        $doc = $this->load_document();
        $docset = new DocumentInfoSet("placeholders.zip");
        $docset->add_as($doc, "remote.txt");
        $docset->add_string_as("Other content\n", "other.txt");
        xassert(!$docset->make_zip_document());
        xassert_str_contains($docset->message_set()->full_feedback_text(), "Remote document unavailable");
    }

    function test_primary_document_placeholder() {
        $sub = null;
        foreach (json_decode(file_get_contents(SiteLoader::find("etc/intrinsicoptions.json"))) as $oj) {
            if ($oj->id === DTYPE_SUBMISSION)
                $sub = $oj;
        }
        $sub->function = "+PlaceholderTest_PaperOption";
        $sub->priority = 100;
        $this->conf->set_opt("intrinsicOptions", [$sub]);
        $this->conf->invalidate_caches("options");
        xassert($this->conf->option_by_id(DTYPE_SUBMISSION) instanceof PlaceholderTest_PaperOption);

        // create a paper whose submission is a placeholder
        $old_sub_open = $this->conf->setting("sub_open");
        $old_sub_sub = $this->conf->setting("sub_sub");
        $this->conf->save_refresh_setting("sub_open", 1);
        $this->conf->save_refresh_setting("sub_sub", Conf::$now + 1000);
        $doc = DocumentInfo::make_placeholder($this->conf, "application/pdf")
            ->set_filename("remote.pdf");
        $ps = new PaperStatus($this->u_chair);
        $pid = $ps->save_paper_json((object) [
            "pid" => "new", "title" => "Placeholder Paper",
            "abstract" => "Abstract", "authors" => [["name" => "Ada Author", "email" => "ada@placeholder.edu"]],
            "submission" => $doc, "status" => (object) ["draft" => true]
        ]);
        $this->conf->save_refresh_setting("sub_open", $old_sub_open);
        $this->conf->save_refresh_setting("sub_sub", $old_sub_sub);
        xassert_paper_status($ps);
        xassert($pid > 0);
        $this->new_pid = $pid;

        $prow = $this->conf->checked_paper_by_id($pid);
        xassert($prow->paperStorageId > 1);
        xassert_eqq($prow->sha1, "");
        xassert_eqq($this->conf->fetch_ivalue("select size from Paper where paperId=?", $pid), DocumentInfo::SIZE_PLACEHOLDER);
        $pdoc = $prow->primary_document();
        xassert($pdoc->is_placeholder());
        xassert_eqq($pdoc->size(), DocumentInfo::SIZE_PLACEHOLDER);
        $fpdfsize = Formula::make($this->u_chair, "pdfsize");
        xassert($fpdfsize && $fpdfsize->ok());
        $fpdfsize->prepare();
        xassert_eqq($fpdfsize->eval($prow, null), null);

        // content of the wrong type fails validation
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[$pid] = "Not a PDF\n";
        xassert(!$pdoc->ensure_content());
        xassert_str_contains($pdoc->message_set()->full_feedback_text(), "PDF required");
        xassert($pdoc->is_placeholder());
        xassert_eqq($pdoc->mimetype, "application/pdf");
        xassert_eqq($this->conf->fetch_value("select sha1 from PaperStorage where paperStorageId=?", $pdoc->paperStorageId), "");
        xassert(!$pdoc->content_available());
        xassert_eqq($pdoc->size(), DocumentInfo::SIZE_PLACEHOLDER);
        xassert(!$pdoc->prepare_download(new Downloader));

        // completing it updates the paper
        $pdf = "%PDF-2.0\nPrimary content\n";
        PlaceholderTest_PaperOption::$nfetch = 0;
        PlaceholderTest_PaperOption::$content[$pid] = $pdf;
        $prow = $this->conf->checked_paper_by_id($pid);
        $pdoc = $prow->primary_document();
        xassert($pdoc->ensure_content());
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
        $hash = $pdoc->binary_hash();
        xassert_eqq($hash, $pdoc->content_binary_hash());
        xassert_eqq($prow->sha1, $hash);
        xassert_eqq($prow->primary_document()->binary_hash(), $hash);
        $prow = $this->conf->checked_paper_by_id($pid);
        xassert_eqq($prow->sha1, $hash);
        xassert_eqq($this->conf->fetch_ivalue("select size from Paper where paperId=?", $pid), strlen($pdf));
        xassert(!$prow->primary_document()->is_placeholder());
        xassert_eqq($fpdfsize->eval($prow, null), strlen($pdf));
        xassert_eqq(PlaceholderTest_PaperOption::$nfetch, 1);
    }

    function test_cleanup() {
        if ($this->new_pid) {
            $this->conf->checked_paper_by_id($this->new_pid)->delete_from_database($this->u_chair);
        }
        $this->conf->qe("delete from PaperOption where optionId=?", self::OPTID);
        $this->conf->qe("delete from PaperStorage where documentType=?", self::OPTID);
        $this->conf->set_opt("fixedOptions", $this->old_fixed_options);
        $this->conf->set_opt("intrinsicOptions", $this->old_intrinsic_options);
        $this->conf->invalidate_caches("options");
        PlaceholderTest_PaperOption::$content = [];
    }
}
