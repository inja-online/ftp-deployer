# FTP Deployer Documentation Site

This is the source code for the **FTP Deployer** documentation website, built using **Astro v7** file-system routing with Markdown and Vanilla CSS.

---

## 🚀 Project Structure

The project structure is organized as follows:

```text
docs/
├── public/                # Static assets (favicons, images)
├── src/
│   ├── layouts/
│   │   └── DocsLayout.astro # Custom high-fidelity responsive layout
│   └── pages/
│       ├── index.md       # Introduction (Home page)
│       ├── installation.md # Package installation guide
│       ├── configuration.md # Complete profile/config reference
│       ├── concepts.md     # Architecture / how it works
│       ├── commands.md     # CLI commands reference
│       ├── agent-skill.md  # AI Agent Skill installation & usage
│       ├── security.md     # Security model and hardening
│       └── troubleshooting.md # Common failures and fixes
├── astro.config.mjs       # Astro configuration (base & site settings)
└── package.json           # Project dependencies & script aliases
```

---

## 🧞 Commands

Run all commands from the `docs/` directory using **Bun**:

| Command | Action |
| :--- | :--- |
| `bun install` | Installs dependencies |
| `bun run dev` | Starts local dev server at `http://localhost:4321/ftp-deployer/` |
| `bun run build` | Builds your production static site to `./dist/` |
| `bun run preview` | Previews your built site locally |
| `bunx astro check` | Runs Astro diagnostics |

---

## 🔒 Deployment

Deploys are automated via GitHub Actions on every push to `main` or `master` branches that modifies the `/docs` directory. The workflow builds the static site and publishes it to **GitHub Pages**.

See the workflow file at `.github/workflows/deploy-docs.yml`.
