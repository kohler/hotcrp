<?php
// mimetypes.inc -- HotCRP helper file for MIME types
// HotCRP is Copyright (c) 2006-2011 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $mimetypeSynonyms, $mimetypeName;

$mimetypeSynonyms = array("application/mspowerpoint" => "application/vnd.ms-powerpoint",
			  "application/powerpoint" => "application/vnd.ms-powerpoint",
			  "application/x-mspowerpoint" => "application/vnd.ms-powerpoint");

$mimetypeNames = array("application/pdf" => "PDF",
		       "application/postscript" => "PostScript file",
		       "application/vnd.ms-powerpoint" => "PowerPoint presentation",
		       "application/vnd.openxmlformats-officedocument.presentationml.presentation" => "PowerPoint presentation",
		       "video/mp4" => "MPEG4 video",
		       "video/x-msvideo" => "AVI video");
