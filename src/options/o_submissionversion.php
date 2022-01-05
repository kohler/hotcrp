<?php
// o_submissionversion.php -- HotCRP helper class for submission version intrinsic
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SubmissionVersion_PaperOption extends PaperOption {
    function __construct($conf, $args) {
        parent::__construct($conf, $args);
    }
    function value_force(PaperValue $ov) {
        if ($ov->prow->finalPaperStorageId > 1
            && $ov->prow->paperStorageId > 1) {
            $ov->set_value_data([$ov->prow->paperStorageId], [null]);
        }
    }
    function value_present(PaperValue $ov) {
        return $ov->value > 1;
    }
    function render(FieldRender $fr, PaperValue $ov) {
        assert($fr->table !== null);
        if ($fr->user->can_view_pdf($ov->prow) && $ov->value > 1) {
            $fr->title = false;
            $dname = $this->conf->_c("field", "Submission version");
            $fr->set_html('<p class="pgsm"><small>' . $ov->prow->document(DTYPE_SUBMISSION)->link_html(htmlspecialchars($dname), DocumentInfo::L_SMALL | DocumentInfo::L_NOSIZE) . "</small></p>");
        }
    }
}
