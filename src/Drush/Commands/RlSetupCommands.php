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
   */
  #[CLI\Command(name: 'rl:setup-ai', aliases: ['rl-sa'])]
  #[CLI\Help(description: '[YAML] Installs RL AI skill files so coding assistants can discover rl:* commands and A/B testing workflows.')]
  #[CLI\Option(name: 'host', description: 'Target: claude, agents, or all (default: all)')]
  #[CLI\Option(name: 'check', description: 'Check if installed files are up to date (no changes made)')]
  #[CLI\Usage(name: 'drush rl:setup-ai', description: 'Install for all AI tools')]
  #[CLI\Usage(name: 'drush rl:setup-ai --check', description: 'Check if skill files are up to date')]
  #[CLI\Usage(name: 'drush rl-sa --host=claude', description: 'Install for Claude Code only')]
  #[CLI\Usage(name: 'drush rl-sa --host=agents', description: 'Install for Codex/Gemini/Copilot/Cursor')]
  public function setupAi(
    array $options = [
      'host' => 'all',
      'check' => FALSE,
    ],
  ): string {
    $modulePath = $this->getModulePath();
    $projectRoot = $this->getProjectRoot();
    $host = $options['host'] ?? 'all';

    if ($modulePath === NULL) {
      return $this->error('Could not determine rl module path.');
    }
    if ($projectRoot === NULL) {
      return $this->error('Could not determine project root (no composer.json found).');
    }
    if (!in_array($host, ['claude', 'agents', 'all'])) {
      return $this->error('Invalid --host value. Use: claude, agents, or all.');
    }

    if ($options['check']) {
      return $this->checkSkillFiles($modulePath, $projectRoot, $host);
    }

    $results = [];
    $installClaude = in_array($host, ['claude', 'all']);
    $installAgents = in_array($host, ['agents', 'all']);

    if ($installClaude) {
      $results = array_merge($results, $this->installFile(
        $modulePath,
        $projectRoot,
        '.claude/skills/rl/SKILL.md',
      ));
    }

    if ($installAgents) {
      $results = array_merge($results, $this->installFile(
        $modulePath,
        $projectRoot,
        '.agents/skills/rl/SKILL.md',
      ));
      $results = array_merge($results, $this->installFile(
        $modulePath,
        $projectRoot,
        '.agents/skills/rl/agents/openai.yaml',
      ));
    }

    $supportedTools = [];
    if ($installClaude) {
      $supportedTools[] = 'Claude Code: .claude/skills/rl/SKILL.md';
    }
    if ($installAgents) {
      $supportedTools[] = 'Codex / Gemini / Copilot / Cursor: .agents/skills/rl/SKILL.md';
    }

    return $this->yaml([
      'success' => TRUE,
      'message' => 'RL AI skill files installed.',
      'actions' => $results,
      'supported_tools' => $supportedTools,
    ]);
  }

  /**
   * Checks if installed skill files match the module source.
   */
  protected function checkSkillFiles(string $modulePath, string $projectRoot, string $host): string {
    $files = [];
    if (in_array($host, ['claude', 'all'])) {
      $files[] = '.claude/skills/rl/SKILL.md';
    }
    if (in_array($host, ['agents', 'all'])) {
      $files[] = '.agents/skills/rl/SKILL.md';
      $files[] = '.agents/skills/rl/agents/openai.yaml';
    }

    $results = [];
    $outdated = FALSE;
    foreach ($files as $relativePath) {
      $source = $modulePath . '/' . $relativePath;
      $dest = $projectRoot . '/' . $relativePath;

      if (!file_exists($dest)) {
        $results[] = sprintf('%s — NOT INSTALLED', $relativePath);
        $outdated = TRUE;
      }
      elseif (!file_exists($source)) {
        $results[] = sprintf('%s — source missing', $relativePath);
      }
      elseif (md5_file($source) !== md5_file($dest)) {
        $results[] = sprintf('%s — OUTDATED', $relativePath);
        $outdated = TRUE;
      }
      else {
        $results[] = sprintf('%s — up to date', $relativePath);
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
   * Copies a single file from module to project root.
   */
  protected function installFile(string $modulePath, string $projectRoot, string $relativePath): array {
    $results = [];
    $source = $modulePath . '/' . $relativePath;
    $dest = $projectRoot . '/' . $relativePath;

    if (!file_exists($source)) {
      return [sprintf('Source not found: %s', $relativePath)];
    }

    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
      mkdir($destDir, 0755, TRUE);
    }

    $action = file_exists($dest) ? 'updated' : 'installed';
    copy($source, $dest);
    $results[] = sprintf('%s %s at %s', basename($relativePath), $action, $relativePath);

    return $results;
  }

}
