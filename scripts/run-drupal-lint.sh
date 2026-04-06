#!/bin/bash
source scripts/prepare-drupal-lint.sh

EXIT_CODE=0

echo "---- Checking with Drupal standard... ----"
phpcs --standard=Drupal \
  --extensions=php,module,inc,install,test,profile,theme,info,txt,md,yml \
  --ignore=node_modules,rl/vendor,.github,vendor,README.md \
  --warning-severity=0 \
  -v \
  .
status=$?
if [ $status -ne 0 ]; then
  EXIT_CODE=$status
fi

echo "---- Checking with DrupalPractice standard... ----"
phpcs --standard=DrupalPractice \
  --extensions=php,module,inc,install,test,profile,theme,info,txt,md,yml \
  --ignore=node_modules,rl/vendor,.github,vendor,README.md \
  --warning-severity=0 \
  -v \
  .

status=$?
if [ $status -ne 0 ]; then
  EXIT_CODE=$status
fi

# Exit with failure if any of the checks failed
exit $EXIT_CODE

