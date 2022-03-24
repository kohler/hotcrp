#! /bin/sh
a=0
runcheck () {
    echo $1:
    php -d error_reporting=E_ALL $1
    z=$?
    echo
    if [ "$a" = 0 ]; then a=$z; fi
}

runcheck test/test01.php
runcheck test/test02.php
runcheck test/test03.php
runcheck test/test04.php
runcheck test/test05.php
runcheck test/test06.php
runcheck test/test07.php

exit $a
