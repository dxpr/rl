<?php

namespace Drupal\Tests\rl\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests report sorting, selection, confirmation, and permissions.
 */
#[Group('rl')]
#[RunTestsInSeparateProcesses]
class ExperimentsOverviewTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['rl', 'block'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalPlaceBlock('system_messages_block');
    $this->drupalLogin($this->drupalCreateUser(['view rl reports', 'administer rl experiments']));
  }

  /**
   * Tests every heading in both directions and numeric/date sorting.
   */
  public function testSorting(): void {
    $this->createExperiment('z-id', 'Alpha', 'z_source', 2, 10, 2, 1700000000, 0);
    $this->createExperiment('a-id', 'Zulu', 'a_source', 10, 2, 10, 1600000000, 1800000000);
    $this->drupalGet('admin/reports/rl');
    $this->assertSession()->elementsCount('css', 'thead th a', 7);
    $this->assertSession()->elementTextContains('css', 'tbody tr:first-child', 'Alpha');
    $ascending = [
      'Experiment' => 'Alpha',
      'Source' => 'Zulu',
      'Impressions' => 'Alpha',
      'Conversions' => 'Zulu',
      'Variants' => 'Alpha',
      'Created' => 'Zulu',
      'Last Activity' => 'Alpha',
    ];
    foreach ($ascending as $heading => $first) {
      $this->drupalGet('admin/reports/rl', ['query' => ['order' => $heading, 'sort' => 'asc']]);
      $this->assertSession()->elementTextContains('css', 'tbody tr:first-child', $first);
      $this->assertSession()->elementAttributeContains('css', 'th.is-active', 'aria-sort', 'ascending');
      $this->clickLink($heading);
      $this->assertSession()->elementTextContains('css', 'tbody tr:first-child', $first === 'Alpha' ? 'Zulu' : 'Alpha');
      $this->assertSession()->elementAttributeContains('css', 'th.is-active', 'aria-sort', 'descending');
    }
    $this->createExperiment('0', NULL, 'empty_source', 0, 0, 0, 0, 0);
    $this->drupalGet('admin/reports/rl', ['query' => ['order' => 'Experiment', 'sort' => 'asc']]);
    $this->assertSession()->elementTextContains('css', 'tbody tr:first-child', 'Never');
    $this->assertSession()->linkExists('0');
    $this->assertSession()->fieldExists('Update 0');
    $this->drupalGet('admin/reports/rl', ['query' => ['order' => 'invalid', 'sort' => 'invalid']]);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests selection, cancellation, deletion, and stale confirmation URLs.
   */
  public function testBulkDeletion(): void {
    $this->createExperiment('0', 'Zero');
    $this->createExperiment('test[brackets]', '<script>alert(1)</script>');
    $this->createExperiment('keep', 'Keep');
    $this->drupalGet('admin/reports/rl');
    $this->submitForm([], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Select one or more experiments.');
    $edit = [
      'experiments[experiment_' . hash('sha256', '0') . ']' => TRUE,
      'experiments[experiment_' . hash('sha256', 'test[brackets]') . ']' => TRUE,
    ];
    $this->submitForm($edit, 'Apply to selected items');
    $confirmation_url = $this->getSession()->getCurrentUrl();
    $this->assertSession()->pageTextContains('Are you sure you want to delete these 2 experiments?');
    $this->assertSession()->pageTextContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertExperimentExists('0', TRUE);
    $this->clickLink('Cancel');
    $this->assertExperimentExists('0', TRUE);
    $this->submitForm($edit, 'Apply to selected items');
    $consumed_url = $this->getSession()->getCurrentUrl();
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains('Deleted 2 experiments.');
    foreach (['0', 'test[brackets]'] as $id) {
      $this->assertExperimentExists($id, FALSE);
      foreach (['rl_arm_data', 'rl_experiment_totals', 'rl_arm_snapshots'] as $table) {
        $this->assertSame(0, (int) $this->container->get('database')->select($table)->condition('experiment_id', $id)->countQuery()->execute()->fetchField());
      }
    }
    $this->assertExperimentExists('keep', TRUE);
    $this->drupalGet($consumed_url);
    $this->assertSession()->addressEquals('admin/reports/rl');

    // Another administrator cannot use this user's private selection.
    $this->drupalLogin($this->drupalCreateUser(['view rl reports', 'administer rl experiments']));
    $this->drupalGet($confirmation_url);
    $this->assertSession()->addressEquals('admin/reports/rl');

    // Report viewers retain sorting, without mutation controls or route access.
    $this->drupalLogin($this->drupalCreateUser(['view rl reports']));
    $this->drupalGet('admin/reports/rl');
    $this->assertSession()->elementsCount('css', 'thead th a', 7);
    $this->assertSession()->elementNotExists('css', 'input[type="checkbox"]');
    $this->assertSession()->buttonNotExists('Apply to selected items');
    $this->assertSession()->linkNotExists('Delete');
    $this->drupalGet($confirmation_url);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests sorting before pagination and an empty report.
   */
  public function testPagerAndEmptyState(): void {
    $this->drupalGet('admin/reports/rl');
    $this->assertSession()->pageTextContains('No experiments found.');
    $this->assertSession()->buttonNotExists('Apply to selected items');
    for ($i = 1; $i <= 51; $i++) {
      $this->createExperiment('id-' . $i, 'Experiment ' . $i, 'test', $i);
    }
    $this->drupalGet('admin/reports/rl', ['query' => ['order' => 'Impressions', 'sort' => 'asc']]);
    $this->assertSession()->elementsCount('css', 'tbody tr', 50);
    $this->assertSession()->linkNotExists('Experiment 51');
    $this->clickLink('Next page');
    $this->assertSession()->elementsCount('css', 'tbody tr', 1);
    $this->assertSession()->linkExists('Experiment 51');
    $this->submitForm(['experiments[experiment_' . hash('sha256', 'id-51') . ']' => TRUE], 'Apply to selected items');
    $this->clickLink('Cancel');
    $this->assertSession()->linkExists('Experiment 51');
    $this->assertSession()->elementAttributeContains('css', 'th.is-active', 'aria-sort', 'ascending');
  }

  /**
   * Tests that live statistics cannot change a submitted selection.
   */
  public function testSelectionSurvivesReordering(): void {
    for ($i = 1; $i <= 51; $i++) {
      $this->createExperiment('id-' . $i, 'Experiment ' . $i, 'test', $i);
    }
    $this->drupalGet('admin/reports/rl', ['query' => ['order' => 'Impressions', 'sort' => 'asc']]);
    // Traffic moves a selected experiment off the displayed page before POST.
    $this->container->get('database')->update('rl_experiment_totals')
      ->fields(['total_turns' => 1000])
      ->condition('experiment_id', 'id-1')
      ->execute();
    $this->submitForm([
      'experiments[experiment_' . hash('sha256', 'id-1') . ']' => TRUE,
      'experiments[experiment_' . hash('sha256', 'id-2') . ']' => TRUE,
    ], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Are you sure you want to delete these 2 experiments?');
    $this->assertSession()->pageTextContains('Experiment 1 (id-1)');
    $this->assertSession()->pageTextContains('Experiment 2 (id-2)');
    $this->submitForm([], 'Delete');
    $this->assertExperimentExists('id-1', FALSE);
    $this->assertExperimentExists('id-2', FALSE);
    $this->assertExperimentExists('id-51', TRUE);
  }

  /**
   * Tests that a destination parameter cannot bypass the confirmation step.
   */
  public function testDestinationStillShowsConfirmation(): void {
    $this->createExperiment('selected', 'Selected experiment');
    $this->drupalGet('admin/reports/rl', ['query' => ['destination' => '/user']]);
    $this->submitForm([
      'experiments[experiment_' . hash('sha256', 'selected') . ']' => TRUE,
    ], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Are you sure you want to delete this experiment?');
    $this->assertExperimentExists('selected', TRUE);
    $this->submitForm([], 'Delete');
    $this->assertExperimentExists('selected', FALSE);
    $this->assertSession()->addressEquals('user/' . $this->loggedInUser->id());
  }

  /**
   * Tests that submitted selections still enforce current permissions.
   */
  public function testRevokedPermission(): void {
    $this->createExperiment('selected', 'Selected experiment');
    $this->drupalGet('admin/reports/rl');
    foreach ($this->loggedInUser->getRoles(TRUE) as $role_id) {
      Role::load($role_id)->revokePermission('administer rl experiments')->save();
    }
    $this->submitForm([
      'experiments[experiment_' . hash('sha256', 'selected') . ']' => TRUE,
    ], 'Apply to selected items');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertExperimentExists('selected', TRUE);
  }

  /**
   * Tests that separate confirmation pages keep independent selections.
   */
  public function testIndependentSelections(): void {
    $this->createExperiment('first', 'First experiment');
    $this->createExperiment('second', 'Second experiment');
    $confirmations = [];
    foreach (['first', 'second'] as $id) {
      $this->drupalGet('admin/reports/rl');
      $this->submitForm([
        'experiments[experiment_' . hash('sha256', $id) . ']' => TRUE,
      ], 'Apply to selected items');
      $confirmations[$id] = $this->getSession()->getCurrentUrl();
    }
    $this->assertNotSame($confirmations['first'], $confirmations['second']);
    $this->drupalGet($confirmations['first']);
    $this->submitForm([], 'Delete');
    $this->assertExperimentExists('first', FALSE);
    $this->assertExperimentExists('second', TRUE);
    $this->drupalGet($confirmations['second']);
    $this->submitForm([], 'Delete');
    $this->assertExperimentExists('second', FALSE);
  }

  /**
   * Creates an experiment with deliberately distinct statistics.
   */
  protected function createExperiment(string $id, ?string $name, string $module = 'test', int $turns = 2, int $rewards = 1, int $arms = 1, int $created = 1700000000, int $updated = 1700000010): void {
    $database = $this->container->get('database');
    $database->insert('rl_experiment_registry')->fields([
      'experiment_id' => $id,
      'experiment_name' => $name,
      'module' => $module,
      'registered_at' => $created,
    ])->execute();
    if (!$arms) {
      return;
    }
    $database->insert('rl_experiment_totals')->fields([
      'experiment_id' => $id,
      'total_turns' => $turns,
      'updated' => $updated,
    ])->execute();
    for ($i = 0; $i < $arms; $i++) {
      $database->insert('rl_arm_data')->fields([
        'experiment_id' => $id,
        'arm_id' => (string) $i,
        'turns' => $turns,
        'rewards' => $i ? 0 : $rewards,
      ])->execute();
    }
    $database->insert('rl_arm_snapshots')->fields([
      'experiment_id' => $id,
      'arm_id' => '0',
      'turns' => $turns,
      'rewards' => $rewards,
      'total_experiment_turns' => $turns,
      'created' => $created,
    ])->execute();
  }

  /**
   * Checks whether an experiment is still registered.
   */
  protected function assertExperimentExists(string $id, bool $expected): void {
    $this->assertSame($expected, (bool) $this->container->get('database')->select('rl_experiment_registry')->condition('experiment_id', $id)->countQuery()->execute()->fetchField());
  }

}
