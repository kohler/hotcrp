<?php
require_once("src/init.php");

$arg = getopt("hn:", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3check.php\n");
    exit(0);
}

if (!$Conf->setting_data("s3_bucket"))
    die("* S3 is not configured for this conference\n");

$s3doc = HotCRPDocument::s3_document();

$args = array("marker" => null, "max-keys" => 100);
$xml = null;
$xmlpos = 0;
while (1) {
    if ($xml === null || $xmlpos >= count($xml->Contents)) {
        $content = $s3doc->ls("doc/", $args);
        $xml = new SimpleXMLElement($content);
        $xmlpos = 0;
    }
    if (!isset($xml->Contents) || $xmlpos >= count($xml->Contents))
        break;
    $node = $xml->Contents[$xmlpos];
    $args["marker"] = $node->Key;
    if (preg_match(',/([0-9a-f]{40})(?:[.][^/]*|)\z,', $node->Key, $m)) {
        echo "$node->Key: ";
        $content = $s3doc->load($node->Key);
        $sha1sum = sha1($content, false);
        if ($sha1sum !== $m[1]) {
            echo "bad checksum $sha1sum\n";
            error_log("$node->Key: bad checksum $sha1sum");
        else
            echo "ok\n";
    }
    ++$xmlpos;
}
