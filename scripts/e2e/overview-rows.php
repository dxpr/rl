<?php

/**
 * @file
 * Renders the RL experiments overview and filter forms for a query string.
 *
 * Usage: drush php:script overview-rows.php "search=hero&source=module"
 * Prints the listed experiment names, the summary line and the reset state.
 */

use Drupal\rl\Form\ExperimentsFilterForm;
use Drupal\rl\Form\ExperimentsOverviewForm;
use Drupal\user\Entity\User;

parse_str($extra[0] ?? '', $query);
\Drupal::service('account_switcher')->switchTo(User::load(1));
// Keep the CLI request, which carries the session, and swap in the query.
\Drupal::requestStack()->push(\Drupal::request()->duplicate($query));

$overview = \Drupal::formBuilder()->getForm(ExperimentsOverviewForm::class);
$filters = \Drupal::formBuilder()->getForm(ExperimentsFilterForm::class);

print 'names: ' . implode('|', array_column($overview['#experiments'], 'name')) . "\n";
print 'summary: ' . (isset($overview['summary']) ? strip_tags((string) $overview['summary']['#markup']) : '-') . "\n";
print 'empty: ' . strip_tags((string) $overview['experiments']['#empty']) . "\n";
print 'reset: ' . (isset($filters['actions']['reset']) ? 'yes' : 'no') . "\n";
