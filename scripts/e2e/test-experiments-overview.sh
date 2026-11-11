#!/usr/bin/env bash
# E2E tests for the experiments overview search and filters.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

# Prints one field of the overview for a query string; names come sorted.
overview() {
  local query="$1" field="$2" out
  out=$($DRUSH php:script "$SCRIPT_DIR/overview-rows.php" -- "$query" 2>&1)
  local line
  line=$(echo "$out" | sed -n "s/^$field: //p")
  if [ "$field" = "names" ]; then
    echo "$line" | tr '|' '\n' | sort | paste -sd'|' -
  else
    echo "$line"
  fi
}

section "Setup"

$DRUSH rl:experiment:create e2e_filter_hero --module=e2e_filter_a --name="E2E Filter Hero Headline" > /dev/null
$DRUSH rl:experiment:create e2e_filter_pricing --module=e2e_filter_a --name="E2E Filter Pricing CTA" > /dev/null
$DRUSH rl:experiment:create e2e_filter_footer --module=e2e_filter_b --name="E2E Filter Footer Box" > /dev/null
$DRUSH php:eval '$s = \Drupal::service("rl.experiment_data_storage"); foreach (["e2e_filter_hero" => 3, "e2e_filter_footer" => 2] as $id => $n) { for ($i = 0; $i < $n; $i++) { $s->recordTurn($id, "v0"); } }'

HERO="E2E Filter Hero Headline"
PRICING="E2E Filter Pricing CTA"
FOOTER="E2E Filter Footer Box"
ALL="$FOOTER|$HERO|$PRICING"

section "Search"

assert_eq "search by name part" "$HERO" "$(overview 'search=filter%20hero' names)"
assert_eq "search ignores case" "$PRICING" "$(overview 'search=FILTER%20PRICING' names)"
assert_eq "search matches the ID" "$FOOTER" "$(overview 'search=e2e_filter_footer' names)"
assert_eq "search trims whitespace" "$HERO" "$(overview 'search=%20%20filter%20hero%20%20' names)"
assert_eq "no match lists nothing" "" "$(overview 'search=no-such-experiment' names)"
assert_has "no match explains itself" "No experiments match the filters." "$(overview 'search=no-such-experiment' empty)"

section "Source and activity"

assert_eq "source filter" "$HERO|$PRICING" "$(overview 'search=e2e_filter&source=e2e_filter_a' names)"
assert_eq "activity with impressions" "$FOOTER|$HERO" "$(overview 'search=e2e_filter&activity=active' names)"
assert_eq "activity without impressions" "$PRICING" "$(overview 'search=e2e_filter&activity=idle' names)"
assert_eq "filters combine" "$PRICING" "$(overview 'search=e2e_filter&source=e2e_filter_a&activity=idle' names)"
assert_eq "filters narrow, never widen" "" "$(overview 'search=e2e_filter&source=e2e_filter_b&activity=idle' names)"
assert_eq "unknown source is ignored" "$ALL" "$(overview 'search=e2e_filter&source=gone' names)"
assert_eq "unknown activity is ignored" "$ALL" "$(overview 'search=e2e_filter&activity=bogus' names)"

section "Summary and reset"

assert_has "summary counts matches" "Showing 2 of" "$(overview 'search=e2e_filter&source=e2e_filter_a' summary)"
assert_eq "no summary without filters" "-" "$(overview '' summary)"
assert_eq "reset shown while filtering" "yes" "$(overview 'search=filter%20hero' reset)"
assert_eq "reset hidden without filters" "no" "$(overview '' reset)"
assert_eq "a search for 0 counts as a filter" "yes" "$(overview 'search=0' reset)"

section "Malformed input"

assert_success "array search does not fail" $DRUSH php:script "$SCRIPT_DIR/overview-rows.php" -- 'search[]=x'
assert_success "nested array source does not fail" $DRUSH php:script "$SCRIPT_DIR/overview-rows.php" -- 'source[a][b]=x'

section "Cleanup"

for id in e2e_filter_hero e2e_filter_pricing e2e_filter_footer; do
  assert_has "delete $id" "success: true" "$($DRUSH rl:experiment:delete "$id" 2>&1)"
done

print_summary
