#!/bin/sh
# slow-pdftohtml.sh -- test wrapper that delays before delegating to pdftohtml.
# `test_banal_concurrent_lease` points `opt.pdftohtmlCommand` here so a background
# format check holds the banal lease long enough to be observed. Set
# HOTCRP_TEST_PDFTOHTML_DELAY to the number of seconds to sleep before running.

if [ -n "$HOTCRP_TEST_PDFTOHTML_DELAY" ]; then
    sleep "$HOTCRP_TEST_PDFTOHTML_DELAY"
fi
exec pdftohtml "$@"
