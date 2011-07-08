" Vim syntax file
" Language: HotCRP Offline Review Forms

if exists("b:current_syntax")
  finish
endif


" Matches
syn match hotCrpSec "^==+==.*$"
syn match hotCrpSubSec "^==-==.*$"

let b:current_syntax = "hotcrp"

hi def link hotCrpSec  Comment
hi def link hotCrpSubSec Constant
