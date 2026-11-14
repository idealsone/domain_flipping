#!/bin/bash
W=/home/u280039231/cvtara_deploy; L=$W/e2e3750b.log
if [ -f $W/e2e3750b.done ]; then tail -c 6000 $L; exit 0; fi
touch $W/e2e3750b.done; exec >>$L 2>&1; echo "== e2e $(date -u)"
curl -fsSL https://raw.githubusercontent.com/idealsone/domain_flipping/df8eb1a46ad814b14ca307cd5de7bc0d3aa30335/deploy-3750/e2e.php -o $W/e2e3750.php && php $W/e2e3750.php
echo "== end $(date -u)"
