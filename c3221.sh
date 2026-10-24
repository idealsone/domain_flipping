#!/bin/bash
# Read-only check: how live ApiController differs from the 3.22.1 one. Runs once.
W=/home/u280039231/cvtara_deploy; H=/home/u280039231/domains/cvtara.com
[ -f $W/c3221.out ] && { cat $W/c3221.out; exit 0; }
mkdir -p $W/c3221 && tar -xzf $W/cvt3221.tgz -C $W/c3221 cvtara_app/app/Http/Controllers/ApiController.php
{ wc -l $H/cvtara_app/app/Http/Controllers/ApiController.php; diff -u $H/cvtara_app/app/Http/Controllers/ApiController.php $W/c3221/cvtara_app/app/Http/Controllers/ApiController.php; echo "== end"; } > $W/c3221.out 2>&1
rm -rf $W/c3221 $W/d3221.done; cat $W/c3221.out
