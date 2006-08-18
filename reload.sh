#! /bin/sh
perl -ne 'if($x||/PaperList/){$x=1;print;}' < Code/conference.sql | mysql -uHotNetsV -pHotNetsV HotNetsV
