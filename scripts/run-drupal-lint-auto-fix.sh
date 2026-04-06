#!/bin/bash

source scripts/prepare-drupal-lint.sh

# phpcbf exit codes: 0=clean, 1=fixed, 2=unfixable remain, 3=error.
# Only exit 3 is a real failure; 0-2 are normal operation.
phpcbf --standard=Drupal \
  --extensions=php,module,inc,install,test,profile,theme,info,txt,md,yml \
  --ignore=node_modules,rl/vendor,.github,vendor \
  . || [ $? -le 2 ]

phpcbf --standard=DrupalPractice \
  --extensions=php,module,inc,install,test,profile,theme,info,txt,md,yml \
  --ignore=node_modules,rl/vendor,.github,vendor \
  . || [ $? -le 2 ]
