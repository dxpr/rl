# AI assistant integration

The RL module ships with a built-in skill file
(`.claude/skills/rl/SKILL.md`) that teaches AI coding assistants how
to manage experiments through natural language. Compatible with Claude Code,
Codex CLI, Gemini CLI, GitHub Copilot, Cursor, and any tool supporting the
[Agent Skills standard](https://agentskills.io/specification).

## Quick setup

```bash
# Install skill files for AI tool discovery
drush rl:setup-ai

# Claude Code only
drush rl:setup-ai --host=claude

# Codex/Gemini/Copilot/Cursor only
drush rl:setup-ai --host=agents
```

## Example prompts

After installation, use the `/rl` slash command or ask naturally:

```
/rl list all experiments
/rl analyze hero_cta_test
/rl create experiment homepage_banner --module=my_module
```

Your assistant will respond to prompts like:

- "List all running A/B tests"
- "Analyse the hero_cta_test experiment"
- "Create a new A/B test for the homepage banner"
- "What's the conversion rate for variant B?"

<!-- TODO: screenshot of a Claude Code session using `/rl analyze` on a live experiment -->
