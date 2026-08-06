<?php
// t_batch.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Batch_Tester {
    /** @var Conf */
    private $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_backuppattern() {
        $bp = new BackupPattern("%{dbname}/%U.sql.gz");
        xassert($bp->match("barforama/1020340000.sql.gz"));
        xassert_eqq($bp->dbname(), "barforama");
        xassert_eqq($bp->timestamp(), 1020340000);
        xassert(!$bp->match("fsart_10202029292.sql.gz"));
        xassert(!$bp->match("barforama/1020340000.sql.g"));

        $bp->clear()
            ->set_dbname("mydb")
            ->set_confid("myconf")
            ->set_timestamp(1);
        xassert_eqq($bp->expansion(), "mydb/1.sql.gz");

        $bp = (new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz"))
            ->set_dbname("mydb")
            ->set_confid("myconf")
            ->set_timestamp(1667736000);
        xassert_eqq($bp->expansion(), "mydb/myconf-20221106-120000.sql.gz");

        $bp = new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz");
        xassert($bp->match("mydb/myconf-20221106-120000.sql.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->timestamp(), 1667736000);
        xassert(!$bp->match("mydb/myconf-20221106-120000.sal.gz"));

        $bp = new BackupPattern("%{dbname}/%{confid}-%{filename}");
        xassert($bp->match("mydb/myconf-20221106-120000.sql.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->filename(), "20221106-120000.sql.gz");
        xassert($bp->match("mydb/myconf-20221106-120000.sal.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->filename(), "20221106-120000.sal.gz");
        xassert(!$bp->match("mydb/myconf-20221106-120000.sal.gz/"));

        $bp->clear()
            ->set_dbname("foo")
            ->set_confid("bar")
            ->set_filename("amazing.txt");
        xassert_eqq($bp->full_expansion(), "foo/bar-amazing.txt");

        $bp = new BackupPattern("%{dbname}/%{confid}-%{filename}");
        $bp->clear()
            ->set_dbname("foo");
        xassert_eqq($bp->expansion(), "foo/");
        xassert_eqq($bp->full_expansion(), null);

        $bp->clear()
            ->set_dbname("foo")
            ->set_confid("bar")
            ->set_filename_from_path("/var/bool/xxxxx.txt");
        xassert_eqq($bp->full_expansion(), "foo/bar-xxxxx.txt");
    }

    function test_saveusers() {
        $this->conf->qe("delete from ContactInfo where email='addeduser@_.com'");
        xassert(!$this->conf->fresh_user_by_email("addeduser@_.com"));

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"chair\"}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_CHAIR | Contact::ROLE_PC);
        xassert(!$u->is_disabled());

        // don't disable or change roles for locked users
        $this->conf->qe("update ContactInfo set cflags=cflags|? where email=?", Contact::CF_SECURITYLOCK, "addeduser@_.com");

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"pc\", \"disabled\": true}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_CHAIR | Contact::ROLE_PC);
        xassert(!$u->is_disabled());

        $this->conf->qe("update ContactInfo set cflags=cflags&~? where email=?", Contact::CF_SECURITYLOCK, "addeduser@_.com");

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"pc\", \"disabled\": true}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);
        xassert($u->is_disabled());

        $this->conf->qe("delete from ContactInfo where email='addeduser@_.com'");
        $this->conf->invalidate_user($u);
    }

    /** @param string $text
     * @return string */
    private function write_temp($text) {
        $fn = tempdir() . "/users.csv";
        xassert_eqq(file_put_contents($fn, $text), strlen($text));
        return $fn;
    }

    private function delete_batch_users() {
        $this->conf->qe("delete from ContactInfo where email like 'csvbatch%'");
        if (($cdb = $this->conf->contactdb())) {
            Dbl::qe($cdb, "delete from ContactInfo where email like 'csvbatch%'");
        }
        $this->conf->invalidate_caches("users");
    }

    function test_saveusers_csv_file() {
        $this->delete_batch_users();

        // header, `###` comment line, two rows
        $fn = $this->write_temp("email,name,affiliation,roles\n"
            . "### this is a comment\n"
            . "csvbatch1@_.com,\"Adams, John Quincy\",Whitehouse,\"pc,chair\"\n"
            . "csvbatch2@_.com,Millard Fillmore,Buffalo,pc\n");
        $su = new SaveUsers_Batch($this->conf->root_user(), ["_" => [$fn], "quiet" => true]);
        xassert_eqq($su->run(), 0);

        $u1 = $this->conf->fresh_user_by_email("csvbatch1@_.com");
        xassert_neqq($u1, null);
        xassert_eqq($u1->firstName, "John Quincy");
        xassert_eqq($u1->lastName, "Adams");
        xassert_eqq($u1->affiliation, "Whitehouse");
        xassert_eqq($u1->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC | Contact::ROLE_CHAIR);

        $u2 = $this->conf->fresh_user_by_email("csvbatch2@_.com");
        xassert_neqq($u2, null);
        xassert_eqq($u2->firstName, "Millard");
        xassert_eqq($u2->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);

        $this->delete_batch_users();
    }

    function test_saveusers_csv_requires_email_header() {
        $fn = $this->write_temp("user,affiliation\nJohn Adams <csvbatch3@_.com>,UCB\n");
        $su = new SaveUsers_Batch($this->conf->root_user(), ["_" => [$fn], "quiet" => true]);
        $msg = "";
        try {
            $su->run();
        } catch (CommandLineException $ex) {
            $msg = $ex->getMessage();
        }
        xassert_str_contains($msg, "email field missing");
        xassert(!$this->conf->fresh_user_by_email("csvbatch3@_.com"));
    }

    function test_saveusers_csv_only_create() {
        $this->delete_batch_users();

        $fn = $this->write_temp("email,roles\ncsvbatch4@_.com,pc\n");
        $su = new SaveUsers_Batch($this->conf->root_user(), ["_" => [$fn], "quiet" => true]);
        xassert_eqq($su->run(), 0);
        xassert_neqq($this->conf->fresh_user_by_email("csvbatch4@_.com"), null);

        // An existing account fails under `--only-create`, but later rows
        // still save. (The batch script prints the failure to stderr; that
        // line in the test output is expected.)
        $fn = $this->write_temp("email,roles\ncsvbatch4@_.com,chair\ncsvbatch5@_.com,pc\n");
        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "_" => [$fn], "quiet" => true, "only-create" => true
        ]);
        xassert_eqq($su->run(), 1);
        xassert_eqq($this->conf->fresh_user_by_email("csvbatch4@_.com")->roles & Contact::ROLE_CHAIR, 0);
        xassert_neqq($this->conf->fresh_user_by_email("csvbatch5@_.com"), null);

        $this->delete_batch_users();
    }

    function test_hotcrp_daemonize() {
        if ((!is_dir("/proc/self/fd") && !is_dir("/dev/fd"))
            || !is_executable("/bin/bash")
            || !function_exists("stream_socket_pair")) {
            return;
        }
        $daemonize = escapeshellarg(SiteLoader::$root . "/batch/hotcrp-daemonize");
        $devnull = ["file", "/dev/null", "a"];
        $descriptors = [["file", "/dev/null", "r"], $devnull, $devnull];

        // no command: usage error
        $p = proc_open($daemonize, $descriptors, $pipes);
        xassert_eqq(proc_close($p), 1);

        // this socket stands in for the descriptors a web request holds open,
        // such as its connection to the browser and to the database
        $sp = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        xassert_neqq($sp, false);

        $time0 = microtime(true);
        $p = proc_open("{$daemonize} /bin/sh -c " . escapeshellarg("sleep 3"),
            $descriptors, $pipes);
        xassert_eqq(proc_close($p), 0);
        $elapsed = microtime(true) - $time0;

        // the daemon runs for 3sec, so `hotcrp-daemonize` did not wait for it
        xassert_lt($elapsed, 1.0);

        // drop this process's copy of the write end; the read end sees
        // end-of-file unless the daemon inherited a copy, in which case the
        // read blocks until it times out
        fclose($sp[1]);
        stream_set_blocking($sp[0], true);
        stream_set_timeout($sp[0], 1);
        fread($sp[0], 1);
        $metadata = stream_get_meta_data($sp[0]);
        xassert(!$metadata["timed_out"]);
        fclose($sp[0]);
    }
}
