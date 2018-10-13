<?php
// comment.php -- HotCRP paper comment display/edit page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/papertable.php");
$Qreq->text = $Qreq->comment;
$Qreq->tags = $Qreq->commenttags;
if ($Qreq->deletecomment)
    $Qreq->delete = 1;
if ($Qreq->p)
    $Conf->fetch_request_paper($Me, $Qreq);
$Conf->call_api_exit("comment", $Me, $Qreq, $Conf->paper);
