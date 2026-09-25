# php-s3 — website & documentation (`web` branch)

This is an **orphan branch**: independent history, no application code.

```
src/content/docs/        ← documentation markdown & landing page (Astro Starlight)
src/content/docs/docs/   ← documentation guides (install, hostinger, usage, architecture, s3-compatibility, research)
dist/                    ← built static site — created locally (`pnpm build`), uploaded to host, never committed
```

- **Documentation set:** Installation · Hostinger · Usage · Architecture · S3 Compatibility · Research
- Built with [Astro Starlight](https://starlight.astro.build)

**Commands:**

```bash
pnpm install
pnpm dev      # local docs preview on http://localhost:4321
pnpm build    # generates static HTML + search index in dist/
```

**The application lives on the `main` branch.** For project context, workflows and agent
notes, see `AGENTS.md` on `main`.

Rendered docs & landing page: <https://php-s3.codaipro.com>
