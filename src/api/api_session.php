<?php
// api_session.php -- HotCRP session API calls
// Copyright (c) 2008-2019 Eddie Kohler; see LICENSE.

class Session_API {
    static function setsession(Contact $user, $qreq) {
        if (is_string($qreq)) {
            $v = $qreq;
        } else {
            $v = $qreq->v;
        }

        $error = false;
        preg_match_all('/(?:\A|\s)(foldpaper[abpt]|foldpscollab|foldhomeactivity|(?:pl|pf|ul)display|scoresort)(|\.[^=]*)(=\S*|)(?=\s|\z)/', $v, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $unfold = intval(substr($m[3], 1) ? : "0") === 0;
            if ($m[1] === "scoresort" && $m[2] === "" && $m[3] !== "") {
                $user->save_session($m[1], substr($m[3], 1));
            } else if (($m[1] === "pldisplay" || $m[1] === "pfdisplay")
                       && $m[2] !== "") {
                PaperList::change_display($user, substr($m[1], 0, 2), substr($m[2], 1), $unfold);
            } else if ($m[1] === "uldisplay"
                       && preg_match('/\A\.[-a-zA-Z0-9_:]+\z/', $m[2])) {
                $x = $user->session($m[1]);
                if ($x === null || strpos($x, " ") === false)
                    $x = " tags overAllMerit ";
                $v = substr($m[2], 1);
                $x = str_replace(" $v ", " ", $x) . ($unfold ? "$v " : "");
                if ($x === " tags overAllMerit " || $x === " overAllMerit tags ")
                    $x = null;
                $user->save_session($m[1], $x);
            } else if (substr($m[1], 0, 4) === "fold" && $m[2] === "") {
                $user->save_session($m[1], $unfold ? 0 : null);
            } else {
                $error = true;
            }
        }

        return ["ok" => !$error];
    }
}
