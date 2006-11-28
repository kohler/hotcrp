#! /bin/sh
perl -ne 'if($x||/PaperList/){$x=1;print;}' < Code/schema.sql | mysql -uHotNetsV -pHotNetsV HotNetsV
