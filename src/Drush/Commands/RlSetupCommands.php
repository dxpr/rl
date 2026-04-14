<?php

declare(strict_types=1);

namespace Drupal\rl\Drush\Commands;

use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Attributes as CLI;

/**
 * Drush command for AI coding assistant setup.
 */
final class RlSetupCommands extends RlCommandsBase {

  /**
   * Constructs RlSetupCommands.
   */
  public function __construct(
    protected readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * Installs AI skill files to the project root.
   *
   * Installs the parent rl module's skill files plus the skill files of any
   * enabled rl_* submodule (rl_page_title, rl_menu_link, etc.). Each
   * submodule ships its own SKILL.md inside its own module directory; this
   * command discovers them via the module extension list and copies them
   * to the project root so AI assistants can discover the full command
   * surface in one shot.
   */
  #[CLI\Command(name: 'rl:setup-ai', aliases: ['rl-sa'])]
  #[CLI\Help(description: '[YAML] Installs RL AI skill files (parent module + all enabled rl_* submodules) so coding assistants can discover rl:* commands and A/B testing workflows.')]
  #[CLI\Option(name: 'host', description: 'Target: claude, agents, or all (default: all)')]
  #[CLI\Option(name: 'check', description: 'Check if installed files are up to date (no changes made)')]
  #[CLI\Usage(name: 'drush rl:setup-ai', description: 'Install for all AI tools (parent + submodules)')]
  #[CLI\Usage(name: 'drush rl:setup-ai --check', description: 'Check if skill files are up to date')]
  #[CLI\Usage(name: 'drush rl-sa --host=claude', description: 'Install for Claude Code only')]
  #[CLI\Usage(name: 'drush rl-sa --host=agents', description: 'Install for Codex/Gemini/Copilot/Cursor')]
  public function setupAi(
    array $options = [
      'host' => 'all',
      'check' => FALSE,
    ],
  ): string {
    $projectRoot = $this->getProjectRoot();
    $host = $options['host'] ?? 'all';

    if ($projectRoot === NULL) {
      return $this->error('Could not determine project root (no composer.json found).');
    }
    if (!in_array($host, ['claude', 'agents', 'all'])) {
      return $this->error('Invalid --host value. Use: claude, agents, or all.');
    }

    // Discover all rl-ecosystem modules with skill files: the parent rl
    // module plus any enabled rl_* submodule.
    $modules = $this->discoverRlModules();
    if (empty($modules)) {
      return $this->error('Could not locate rl module path.');
    }

    if ($options['check']) {
      return $this->checkSkillFiles($modules, $projectRoot, $host);
    }

    $results = [];
    $supportedTools = [];
    $installClaude = in_array($host, ['claude', 'all']);
    $installAgents = in_array($host, ['agents', 'all']);

    foreach ($modules as $moduleName => $modulePath) {
      $relativeFiles = $this->skillFilePaths($moduleName, $installClaude, $installAgents);
      foreach ($relativeFiles as $relativePath) {
        $results = array_merge($results, $this->installFile($modulePath, $projectRoot, $relativePath));
      }
    }
    if ($installClaude) {
      $supportedTools[] = 'Claude Code: .claude/skills/{rl,rl_page_title,rl_menu_link}/SKILL.md';
    }
    if ($installAgents) {
      $supportedTools[] = 'Codex / Gemini / Copilot / Cursor: .agents/skills/{rl,rl_page_title,rl_menu_link}/SKILL.md';
    }

    return $this->yaml([
      'success' => TRUE,
      'message' => 'RL AI skill files installed.',
      'modules_processed' => array_keys($modules),
      'actions' => $results,
      'supported_tools' => $supportedTools,
    ]);
  }

  /**
   * Discover enabled RL ecosystem modules that ship skill files.
   *
   * @return array<string, string>
   *   Map of module machine name to absolute module root path. Always
   *   includes the parent `rl` module; submodules are included only if
   *   they are enabled and have a skill file at the expected path.
   */
  protected function discoverRlModules(): array {
    $modules = [];
    try {
      // @phpstan-ignore-next-line
      $module_handler = \Drupal::service('module_handler');
      $extensions = $this->moduleExtensionList->getList();
    }
    catch (\Exception $e) {
      return $modules;
    }

    foreach ($extensions as $name => $extension) {
      if ($name !== 'rl' && !str_starts_with($name, 'rl_')) {
        continue;
      }
      // The parent rl module is always included; submodules only if enabled.
      if ($name !== 'rl' && !$module_handler->moduleExists($name)) {
        continue;
      }
      $relativePath = $this->moduleExtensionList->getPath($name);
      if (!$relativePath) {
        continue;
      }
      $absolutePath = DRUPAL_ROOT . '/' . $relativePath;
      // Only include modules that actually ship a SKILL.md.
      $skill = $absolutePath . '/.claude/skills/' . $name . '/SKILL.md';
      $agents = $absolutePath . '/.agents/skills/' . $name . '/SKILL.md';
      if (file_exists($skill) || file_exists($agents)) {
        $modules[$name] = $absolutePath;
      }
    }
    return $modules;
  }

  /**
   * Returns the relative skill file paths for a module by host filter.
   *
   * @return string[]
   *   Relative paths under the module root, e.g.
   *   `.claude/skills/{module}/SKILL.md`.
   */
  protected function skillFilePaths(string $moduleName, bool $claude, bool $agents): array {
    $files = [];
    if ($claude) {
      $files[] = '.claude/skills/' . $moduleName . '/SKILL.md';
    }
    if ($agents) {
      $files[] = '.agents/skills/' . $moduleName . '/SKILL.md';
      $files[] = '.agents/skills/' . $moduleName . '/agents/openai.yaml';
    }
    return $files;
  }

  /**
   * Checks if installed skill files match the module sources.
   *
   * @param array<string, string> $modules
   *   Map of module name to absolute module path (from discoverRlModules).
   * @param string $projectRoot
   *   Absolute path to the Composer project root where files are installed.
   * @param string $host
   *   Host filter: "claude", "agents", or "all".
   */
  protected function checkSkillFiles(array $modules, string $projectRoot, string $host): string {
    $installClaude = in_array($host, ['claude', 'all']);
    $installAgents = in_array($host, ['agents', 'all']);

    $results = [];
    $outdated = FALSE;
    foreach ($modules as $moduleName => $modulePath) {
      foreach ($this->skillFilePaths($moduleName, $installClaude, $installAgents) as $relativePath) {
        $source = $modulePath . '/' . $relativePath;
        $dest = $projectRoot . '/' . $relativePath;

        if (!file_exists($source)) {
          // The module does not ship this particular file; skip it silently.
          continue;
        }
        if (!file_exists($dest)) {
          $results[] = sprintf('%s — NOT INSTALLED', $relativePath);
          $outdated = TRUE;
        }
        elseif (md5_file($source) !== md5_file($dest)) {
          $results[] = sprintf('%s — OUTDATED', $relativePath);
          $outdated = TRUE;
        }
        else {
          $results[] = sprintf('%s — up to date', $relativePath);
        }
      }
    }

    if ($outdated) {
      return $this->yaml([
        'success' => FALSE,
        'message' => 'Skill files are outdated. Run drush rl:setup-ai to update.',
        'files' => $results,
      ]);
    }

    return $this->yaml([
      'success' => TRUE,
      'message' => 'All skill files are up to date.',
      'files' => $results,
    ]);
  }

  /**
   * Copies a single file from a module to project root.
   *
   * Silently skips when the source file does not exist (a module may ship
   * a SKILL.md but not the agents YAML, or vice versa).
   */
  protected function installFile(string $modulePath, string $projectRoot, string $relativePath): array {
    $source = $modulePath . '/' . $relativePath;
    $dest = $projectRoot . '/' . $relativePath;

    if (!file_exists($source)) {
      return [];
    }

    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
      mkdir($destDir, 0755, TRUE);
    }

    $action = file_exists($dest) ? 'updated' : 'installed';
    copy($source, $dest);
    return [sprintf('%s %s at %s', basename($relativePath), $action, $relativePath)];
  }

}
