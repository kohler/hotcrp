<?php
// test/options.php -- HotCRP conference options for test databases

global $Opt;
$Opt["dbName"] = "hotcrp_testdb";
$Opt["dbPassword"] = "m5LuaN23j26g";
$Opt["shortName"] = "Testconf I";
$Opt["longName"] = "Test Conference I";
$Opt["paperSite"] = "http://hotcrp.lcdf.org/test/";
$Opt["safePasswords"] = true;
$Opt["passwordHmacKey"] = "6MFZ8fnvAudRVRn4CXsMNrqVjSvTZqrVBFLEBfxRvxvsEjWn";
$Opt["conferenceKey"] = "g3aaENZnMNxn6PxcNHnbRPDac9EYTM3k42BY6fGbYWwefD8E";
$Opt["contactName"] = "Eddie Kohler";
$Opt["contactEmail"] = "ekohler@hotcrp.lcdf.org";
$Opt["sendEmail"] = false;
$Opt["debugShowSensitiveEmail"] = true;
$Opt["emailFrom"] = "you@example.com";
$Opt["disablePS"] = true;
$Opt["smartScoreCompare"] = true;
