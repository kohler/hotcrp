<?php
// t_documentbasics.php -- HotCRP tests
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class DocumentBasics_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var ?S3Client
     * @readonly */
    public $s3c;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->s3c = S3_Tester::make_s3_client($conf, "DocumentBasics");
    }

    function test_s3_signature() {
        $s3d = new S3Client([
            "key" => "AKIAIOSFODNN7EXAMPLE",
            "secret" => "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
            "bucket" => null
        ]);
        $s3d->set_fixed_time(gmmktime(0, 0, 0, 5, 24, 2013));
        Conf::set_current_time(gmmktime(0, 0, 0, 5, 24, 2013));

        $sig = $s3d->signature("GET",
                               "https://examplebucket.s3.amazonaws.com/test.txt",
                               ["Range" => "bytes=0-9"]);
        xassert_eqq($sig["signature"], "f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41");

        $sig = $s3d->signature("PUT",
                               "https://examplebucket.s3.amazonaws.com/test%24file.text",
                               ["x-amz-storage-class" => "REDUCED_REDUNDANCY",
                                "Date" => "Fri, 24 May 2013 00:00:00 GMT",
                                "content" => "Welcome to Amazon S3."]);
        xassert_eqq($sig["signature"], "98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd");

        $sig = $s3d->signature("GET",
                               "https://examplebucket.s3.amazonaws.com?lifecycle",
                               []);
        xassert_eqq($sig["signature"], "fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543");
    }

    function test_docstore_fixed_prefix() {
        xassert_eqq(Filer::docstore_fixed_prefix(null), null);
        xassert_eqq(Filer::docstore_fixed_prefix(""), null);
        xassert_eqq(Filer::docstore_fixed_prefix("/"), "/");
        xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e"), "/a/b/c/d/e/");
        xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e///"), "/a/b/c/d/e///");
        xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b"), "/a/b/c/d/e/%/a/b/");
        xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b%"), "/a/b/c/d/e/%/a/b%/");
        xassert_eqq(Filer::docstore_fixed_prefix("/a/b/c/d/e/%%/a/b%h%x"), "/a/b/c/d/e/%/a/");
        xassert_eqq(Filer::docstore_fixed_prefix("/%02h%x"), "/");
        xassert_eqq(Filer::docstore_fixed_prefix("%02h%x"), null);
    }

    function test_content_binary_hash() {
        $this->conf->save_setting("opt.contentHashMethod", 1, "sha1");

        $doc = DocumentInfo::make_empty($this->conf);
        xassert_eqq($doc->text_hash(), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
        xassert_eqq($doc->content_binary_hash(), hex2bin("da39a3ee5e6b4b0d3255bfef95601890afd80709"));

        $doc = DocumentInfo::make_content($this->conf, "");
        xassert_eqq($doc->text_hash(), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
        xassert_eqq($doc->content_binary_hash(), hex2bin("da39a3ee5e6b4b0d3255bfef95601890afd80709"));

        $doc->set_content("Hello\n");
        xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
        xassert_eqq($doc->content_binary_hash(), hex2bin("1d229271928d3f9e2bb0375bd6ce5db6c6d348d9"));

        $this->conf->save_setting("opt.contentHashMethod", 1, "sha256");
        xassert_eqq($doc->text_hash(), "1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));

        $doc->set_content("");
        xassert_eqq($doc->text_hash(), "sha2-e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"));

        $doc->set_content("Hello\n");
        xassert_eqq($doc->text_hash(), "sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
        xassert_eqq($doc->content_binary_hash(), "sha2-" . hex2bin("66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18"));
    }

    function test_docstore_path() {
        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
        $this->conf->save_setting("opt.contentHashMethod", 1, "sha1");

        $doc = DocumentInfo::make_content($this->conf, "");
        $doc->set_content("Hello\n", "text/plain");
        xassert_eqq(Filer::docstore_path($doc), "/foo/bar/1d2/1d229/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9");

        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar");
        $this->conf->save_refresh_setting("opt.docstoreSubdir", 1, true);

        xassert_eqq(Filer::docstore_path($doc), "/foo/bar/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");
        xassert_eqq($doc->s3_key(), "doc/1d/1d229271928d3f9e2bb0375bd6ce5db6c6d348d9.txt");

        $this->conf->save_setting("opt.contentHashMethod", 1, "sha256");
        $doc->set_content("Hello\n", "text/plain");
        xassert_eqq(Filer::docstore_path($doc), "/foo/bar/sha2-66/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");

        $this->conf->save_refresh_setting("opt.docstore", 1, "/foo/bar/%3h/%5h/%h");
        xassert_eqq(Filer::docstore_path($doc), "/foo/bar/sha2-66a/sha2-66a04/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18");
        xassert_eqq($doc->s3_key(), "doc/66a/sha2-66a045b452102c59d840ec097d59d9467e13a3f34f6494e539ffd32c1bb35f18.txt");

        $this->conf->save_setting("opt.docstore", null);
        $this->conf->save_refresh_setting("opt.docstoreSubdir", null);
        xassert_eqq($this->conf->docstore(), null);
    }

    function test_create_s3() {
        if (!$this->s3c) {
            return;
        }
        $x = $this->s3c->create_bucket();
        xassert_eqq($x, true);

        $x = $this->s3c->put("hello.txt", file_get_contents(SiteLoader::$root . "/README.md"), "text/plain");
        xassert_eqq($x, true);

        $x = $this->s3c->put("hello1.txt", file_get_contents(SiteLoader::$root . "/README.md"), "text/plain");
        xassert_eqq($x, true);

        xassert_eqq(iterator_to_array($this->s3c->ls_all_keys("h")), ["hello.txt", "hello1.txt"]);
    }

    function test_cleanup_s3() {
        if (!$this->s3c) {
            return;
        }
        if ($this->conf->opt("testS3Bucket")) {
            $this->s3c->delete_many(["hello.txt", "hello1.txt"]);
        } else {
            $this->s3c->delete_many(iterator_to_array($this->s3c->ls_all_keys("")));
            $this->s3c->delete_bucket(S3Client::CONFIRM_DELETE_BUCKET);
        }
    }
}
