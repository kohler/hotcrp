<?php
// listactions/la_getjson.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetACMXML_ListAction extends ListAction {
    /** @var bool */
    /** @var ?DocumentInfoSet */
    function __construct($conf, $fj) {
    }
    function document_callback($dj, DocumentInfo $doc, $dtype, PaperStatus $pstatus) {
        if ($doc->ensure_content()) {
            $dj->content_file = $doc->export_filename();
            $this->zipdoc->add_as($doc, $dj->content_file);
        }
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        error_reporting(E_ALL & ~E_NOTICE);
        ini_set("display_errors", "0");

        // GITHUB API KEY
        $ghkey = getenv()["GHKEY"];
        $headers = ["Authorization: token " . $ghkey,
                    "Cache-Control: max-age=0, private, no-cache, no-store, must-revalidate",
                    "Pragma: no-cache",
                    "X-Content-Type-Options: nosniff",
                    "User-Agent: HotCRP WiSPCE"];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, TRUE);

        curl_setopt($curl, CURLOPT_URL, "https://api.github.com/repos/WiPSCE/ACM-XML-Metadata/branches");
        $branches = curl_exec($curl);
        $branch = json_decode($branches)[0]->commit->sha;
        
        curl_setopt($curl, CURLOPT_URL, "https://raw.githubusercontent.com/WiPSCE/ACM-XML-Metadata/" . $branch . "/categories-sections-types.csv");
        $sectionsCSV = curl_exec($curl);
        curl_close($curl);
        if($sectionsCSV === false) {
            die("Please let administrator check GitHub key and URL for export");
        }
        $sections = [];
        $types = [];
        foreach(explode(PHP_EOL, $sectionsCSV) as $row) {
            if($row == "")
                continue;
            $cols = str_getcsv($row);
            $sections[$cols[0]] = $cols[1];
            $types[$cols[0]] = $cols[2];
        }
        array_shift($sections);
        array_shift($types);

        curl_setopt($curl, CURLOPT_URL, "https://raw.githubusercontent.com/WiPSCE/ACM-XML-Metadata/" . $branch . "/affiliations.csv");
        $affilCSV = curl_exec($curl);
        curl_close($curl);
        if($affilCSV === false) {
            die("Please let administrator check GitHub key and URL for export");
        }
        $affiliations = [];
        foreach(explode(PHP_EOL, $affilCSV) as $row) {
            if($row == "")
                continue;
            $cols = str_getcsv($row);
            $affiliations[str_replace("\"", "", $cols[0])] = array(
                "institution" => $cols[1],
                "city" => $cols[2],
                "country" => $cols[3]
            );
        }
        array_shift($affiliations);

        curl_setopt($curl, CURLOPT_URL, "https://raw.githubusercontent.com/WiPSCE/ACM-XML-Metadata/" . $branch . "/proceedingID.txt");
        $proceedingID = curl_exec($curl);
        curl_close($curl);
        if($proceedingID === false) {
            die("Please let administrator check GitHub key and URL for export");
        }

        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["hide_docids" => true]);

        $w=new XMLWriter();
        $w->openMemory();
        $w->startDocument('1.0','UTF-8');
        $w->startElement("erights_record"); // root

        //parent data
        $w->startElement("parent_data");
            $w->startElement("proceeding");
                $w->text($proceedingID);
            $w->endElement();
            //$w->writeElement("volume");
            //$w->writeElement("issue");
            //$w->writeElement("issue_date");
            $w->writeElement("source", "self-generated");
        $w->endElement();

        $missingAffiliations = [];

        $artNum = 1;
        foreach ($ssel->paper_set($user, ["topics" => true, "options" => true]) as $prow) {
            $info = $ps->paper_json($prow);
            $tags = [];
            $sort = "";

            if(!isset($info->submission_category) || strlen($info->submission_category) <= 1)
                die("Paper #" . $info->pid . " (\"" . $info->title . "\") has no submission category defined");

            if(!isset($sections[$info->submission_category]))
                die("Submission category " . $info->submission_category . " not found in GitHub/WiPSCE/ACM-XML-Metadata/categories-sections-types.csv");

            foreach(explode(" ", $prow->paperTags) as $t) {
                if(strlen($t) == 0)
                    continue;

                $t = explode("#", $t)[0];

                array_push($tags, $t);

                if(substr($t, 0, 4) == "sort")
                    $sort = substr($t, 4);
            }

            $w->startElement("paper");
                $w->writeElement("section", $sections[$info->submission_category]);
                $w->writeElement("paper_type", $types[$info->submission_category]);
                $w->writeElement("paper_title", $info->title);
                $w->writeElement("event_tracking_number", $info->pid);
                $w->writeElement("published_article_number", $artNum++);
                $w->writeElement("sequence_no", $sort);
                $w->startElement("authors");
                
                $aSeq = 1;
                foreach($info->authors as $a) {
                    $isContact = false;

                    foreach($info->contacts as $c) {
                        if($c["email"] == $a->email)
                            $isContact = true;
                    }

                    if(!isset($affiliations[$a->affiliation])) {
                        array_push($missingAffiliations, $a->affiliation);
                        $affiliations[$a->affiliation] = array("institution" => $a->affiliation, "city" => "MISSING", "country" => "MISSING");
                    }

                    $w->startElement("author");
                        $w->writeElement("first_name", $a->first);
                        $w->writeElement("last_name", $a->last);
                        $w->writeElement("email_address", $a->email);
                        $w->writeElement("sequence_no", $aSeq++);
                        $w->writeElement("contact_author", $isContact ? "Y" : "N");
                        $w->startElement("affiliations");
                            $w->startElement("affiliation");
                            $w->writeElement("institution", $affiliations[$a->affiliation]["institution"]);
                            $w->writeElement("city", $affiliations[$a->affiliation]["city"]);
                            $w->writeElement("country", $affiliations[$a->affiliation]["country"]);
                            $w->writeElement("sequence_no", 1);
                            $w->endElement();
                        $w->endElement();
                    $w->endElement();
                }
                $w->endElement();        
            $w->endElement();
        }

        $w->endElement(); // end root

        if(count($missingAffiliations) > 0) {
            $mCSV = "";
            foreach($missingAffiliations as $m) {
                $mCSV .= "\"" . $m . "\",\"\",\"\",\"\"" . "\r\n";
            }
            echo "Missing affiliations in GitHub/WiPSCE/ACM-XML-Metadata/affiliations.csv - please add the following rows and complete them:\r\n\r\n";
            echo "<pre>";
            echo $mCSV;
        } else {
            header("Content-Type: text/xml; charset=utf-8");
            if(!isset($_GET["showOnly"]) || $_GET["showOnly"] != "1")
                header("Content-Disposition: attachment; filename=" . mime_quote_string("acmexport.xml"));
            //echo "<pre>"; print_r($info); print_r($prow);
            echo $w->outputMemory(true);
            //json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }

        exit;
    }
}
