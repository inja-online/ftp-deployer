---
layout: ../layouts/DocsLayout.astro
title: AI Agent Integration
description: Install and use the AI Agent Friendly Skill for seamless automated deployments with coding assistants
---

# AI Agent Integration

FTP Deployer is built to be fully compatible with autonomous AI coding assistants (such as **Google Antigravity**, **Claude Code**, and others). By integrating the repository's native AI Agent Skill, your AI assistant can safely run, automate, diagnose, and document deployments for you.

---

## What is the AI Agent Skill?

The repository includes a pre-configured AI Agent Skill named `ftp-deployer`. This skill provides structured guidance and instructions that are automatically ingested by AI agents. It guides them to:
- Use structured JSON outputs instead of human-readable text.
- Follow a strict, safe runbook to avoid deploying unbuilt assets or leaking credentials.
- Parse structured errors and apply precise troubleshooting steps.

---

## Safe Agent execution with `--format=agent`

The core of the AI agent integration is the `--format=agent` option on the `ftp-deployer` command. When run with this flag, the CLI returns structured JSON:

```bash
php artisan ftp-deploy production --format=agent
```

### Success Response Shape
```json
{
  "status": "success",
  "profile": "production",
  "uploaded": 3,
  "deleted": 1,
  "skipped": 120,
  "logs": [
    {"level": "info", "source": "deploy", "message": "Uploading routes/web.php"}
  ],
  "runner": {"ok": true, "logs": []}
}
```

### Error Response Shape
```json
{
  "status": "error",
  "profile": "production",
  "message": "Missing FTP setting: ftp.host",
  "logs": [
    {"level": "error", "source": "validation", "message": "Missing FTP setting: ftp.host"}
  ]
}
```

AI agents parse this output programmatically, check the exit code (`0` for success, non-zero for failure), and instantly understand exactly what went wrong without guessing from raw logs.

---

## Skill Installation

Depending on your workspace setup and the AI agent you are using, you can load this skill in one of two ways.

### Option A: Workspace-Level (Recommended)

FTP Deployer includes the skill inside the `.agents/` workspace directory. 
If your AI agent (like **Google Antigravity**) is configured to discover workspace-scoped skills:

1. Ensure the `.agents` folder exists at the root of your Laravel project containing:
   ```text
   .agents/
   └── skills/
       └── ftp-deployer/
           └── SKILL.md
   ```
2. Your agent will **automatically discover** and load this skill upon starting a conversation in this workspace. No manual setup is needed!

> [!NOTE]
> Workspace-scoped skills are perfect for team environments where you want every developer (and their agent) to share the same deployment knowledge base.

### Option B: Global-Level

If you want the `ftp-deployer` skill to be available across all your projects:

1. Copy the skill directory from your project to your global agent customization root:
   - **Google Antigravity**: `~/.gemini/config/skills/ftp-deployer/`
   - **Claude Code**: Copy or reference in your global config folder.
2. If using a custom path, reference it in your global `skills.json` file:

```json
{
  "entries": [
    { "path": "path/to/custom/skills" }
  ]
}
```

### Option C: Installation via CLI

You can install this skill directly using the `skills` CLI installer. Execute the following command:

```bash
npx skills add {repo} --skill ftp-deployer
```

*(Replace `{repo}` with the repository URL, e.g. `https://github.com/injaonline/ftp-deployer`)*

---

## Mode knowledge the skill gives agents

Agents should understand both deployment modes before changing config or interpreting logs.

| Topic | Simple mode | Versioned mode |
|---|---|---|
| App destination | `app_root` | `release_root/release_id` |
| Public destination | `public_root` | `public_root` with managed bootloader |
| `.env` | `app_root/.env` | `shared_root/.env` |
| Storage | `app_root/storage` | `shared_root/storage` |
| Vendor | `app_root/vendor` | `release_root/release_id/vendor`; upload reuses `vendor-{composer_json}-{composer_lock}.zip` |
| Boot routing | normal Laravel public index | generated `index.php` hardcodes active release id |
| Risk profile | simpler, live overwrite | safer, fresh release per deploy |

When an agent sees `Reusing vendor.zip already on remote`, it means the hash-named ZIP exists and should not be counted as a new upload. In versioned mode, the reused ZIP is still extracted into the fresh release's `vendor/` because optimized Composer autoload paths are release-relative.

## How to use the Skill with your Agent

Once installed, you can command your agent to handle deployments using natural language.

### Sample Prompts

Here are some examples of what you can ask your AI agent:

* **Triggering a Deploy:**
  > "Deploy the latest changes to production using the FTP Deployer skill."
* **Dry Run / Checking configuration:**
  > "Check my FTP Deployer profiles and run a dry-run check."
* **Troubleshooting failures:**
  > "The deployment failed. Can you check the FTP Deployer logs and fix the connection issue?"

### Safe Agent Runbook

When your agent executes a deployment, it follows this strict multi-step runbook:
1. **Asset Build Verification:** Confirms that local/CI build is complete (`npm run build`, `composer install`).
2. **Profile Validation:** Verifies that the targeted profile exists in `config/ftp-deployer.php`.
3. **Secret Checking:** Ensures FTP credentials and application keys are loaded from environment variables (e.g., `.env`) and never printed to public logs.
4. **Mode Awareness:** Checks whether profile uses `simple` or `versioned` and validates expected remote layout.
5. **Execution:** Runs `php artisan ftp-deploy <profile> --format=agent`.
6. **Post-Deployment Verification:** Parses the JSON output and verifies remote Laravel runner logs for archive extraction, vendor ZIP reuse, and remote Artisan errors.
