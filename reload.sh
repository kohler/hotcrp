#! /bin/sh
perl -ne 'if($x||/RELOAD HERE/){$x=1;print;}' < Code/schema.sql | mysql -uHotNetsV -pHotNetsV HotNetsV
