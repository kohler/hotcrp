#!/bin/sh
# slow-pdftohtml.sh -- test wrapper that delays before delegating to pdftohtml.
# `test_banal_concurrent_lease` points `opt.pdftohtmlCommand` here so a background
# format check holds the banal lease long enough to be observed.

sleep 1

for cand in /opt/homebrew/bin/pdftohtml /usr/local/bin/pdftohtml \
            /usr/bin/pdftohtml /opt/local/bin/pdftohtml; do
    if [ -x "$cand" ]; then
        exec "$cand" "$@"
    fi
done

real=`command -v pdftohtml 2>/dev/null`
if [ -n "$real" ]; then
    exec "$real" "$@"
fi

echo "slow-pdftohtml.sh: cannot find pdftohtml" >&2
exit 127
