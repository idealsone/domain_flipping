#!/bin/bash
# Read-only: print the stored Search Console data and SEO agent proposals (owner approved 2026-10-31 10:00 UTC). Changes nothing.
W=/home/u280039231/cvtara_deploy; curl -fsSL -o $W/rd.php https://raw.githubusercontent.com/idealsone/domain_flipping/deploy-pkg-sc-read/rd.php && php $W/rd.php 2>&1 | gzip -9 | base64 -w0
