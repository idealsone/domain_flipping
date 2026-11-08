# Tests

`python3 tests/run_tests.py [--only NAME]` runs the main suite (suites in `tests/suites/`) against a throw-away instance with its own PHP server and SQLite database; it needs PHP 8.2+, the Laravel `vendor/` folder and (for the browser-driven suites) Playwright. `python3 tools/validate.py --mariadb` checks syntax, wiring, routes and migrations on SQLite and MariaDB. `tests/fixtures/ats_golden.json` holds the expected ATS engine output.

Browser checks live in `tests/ui/` (`BASE=http://127.0.0.1:8000 node tests/ui/contrast_scan.cjs`).
