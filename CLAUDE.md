# RL Module - AI Analytics API Implementation Plan

## Overview

Add a service-based analytics API to the RL module that enables both Drush commands and other Drupal modules to access experiment performance data, insights, and recommendations.

## Architecture

```
RlAnalyzerInterface (contract)
       ↓
  RlAnalyzer (service - all business logic)
       ↓
  ┌────┴────┐
Drush     Other modules
Commands  (via DI)
```

## Implementation Tasks

### Phase 1: Core Service Layer

- [ ] **1.1** Create `RlAnalyzerInterface` defining the API contract
- [ ] **1.2** Create `RlAnalyzer` service implementation
- [ ] **1.3** Register service in `rl.services.yml`
- [ ] **1.4** Implement core methods:
  - `listExperiments()` - All experiments with summary stats
  - `getStatus(string $experimentId)` - Detailed experiment status
  - `getPerformance(string $experimentId, array $options)` - Arm performance with labels
  - `getTrends(string $experimentId, array $options)` - Historical data
  - `getConfig(string $experimentId)` - Experiment configuration/metadata

### Phase 2: Drush Commands

- [ ] **2.1** Create `RlCommands.php` Drush command class
- [ ] **2.2** Implement commands:
  - `rl:list` - List all experiments
  - `rl:status <experiment>` - Experiment status
  - `rl:performance <experiment>` - Arm performance
  - `rl:trends <experiment>` - Historical trends
  - `rl:export <experiment>` - Full JSON export
- [ ] **2.3** Add proper help text and examples for AI discoverability

### Phase 3: Testing & Documentation

- [ ] **3.1** Test all Drush commands with synthetic data
- [ ] **3.2** Update README.md with API documentation
- [ ] **3.3** Update docs/rl_project_desc.html
- [ ] **3.4** Verify browser charts still work after changes

### Phase 4: PR & Review

- [ ] **4.1** Create GitHub issue
- [ ] **4.2** Create feature branch
- [ ] **4.3** Commit with proper messages
- [ ] **4.4** Push and create PR
- [ ] **4.5** Verify PR checks pass

## File Structure

```
rl/
├── src/
│   ├── Service/
│   │   ├── RlAnalyzerInterface.php   # NEW - API contract
│   │   └── RlAnalyzer.php            # NEW - Implementation
│   └── Drush/
│       └── Commands/
│           └── RlCommands.php        # NEW - Drush commands
├── rl.services.yml                   # UPDATE - Register service
├── README.md                         # UPDATE - API docs
└── docs/
    └── rl_project_desc.html          # UPDATE - Project description
```

## API Method Signatures

```php
interface RlAnalyzerInterface {
  public function listExperiments(): array;
  public function getStatus(string $experimentId): array;
  public function getPerformance(string $experimentId, int $limit = 20): array;
  public function getTrends(string $experimentId, string $period = 'weekly', int $periods = 8): array;
  public function export(string $experimentId, bool $includeSnapshots = false): array;
}
```

## Testing Commands

```bash
# Rsync to test site
rsync -avz --delete /Users/jur/www/git-projects/dxpr/8/project-groups/rl-group/rl/ /Users/jur/www/dxpr10b.com/web/modules/contrib/rl/

# Clear cache
cd /Users/jur/www/dxpr10b.com && ddev drush cr

# Test commands
ddev drush rl:list --format=json
ddev drush rl:status mock_10_arm_test --format=json
ddev drush rl:performance ai_sorting-help_center_categories-block_1 --limit=10 --format=json
ddev drush rl:trends ab_test_headline_variants --format=json
```

## Success Criteria

1. All Drush commands work and return valid JSON
2. Human-readable labels (not just IDs) in output
3. Pre-computed insights (vs_average, confidence, trends)
4. Self-documenting help text for AI consumption
5. No regression in existing functionality
6. PR checks pass
