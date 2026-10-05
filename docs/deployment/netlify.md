# Netlify Deployment

For the full service map, see
[Deployment Architecture](architecture.md).

The public Enderman Grief Control site is deployed to Netlify from the Astro
application in `site/`.

Production URL:

```text
https://enderman-grief-control.netlify.app/
```

## Site Setup

- Connected repository: this repository.
- Base directory: `site/` in the Netlify UI.
- Config file: `site/netlify.toml`.
- Build command: `npm run build`.
- Publish directory: `dist`.
- Node version: `22`.

`site/netlify.toml` is intentionally scoped to the public site. The private
Laravel app continues to build from the repository root on Render.

## Environment Variables

The public site has no Netlify environment variables today. Public links are
static source data.

## Public Links

Distribution, GitHub, dashboard, and portfolio links live in:

```text
site/src/data/links.ts
```

Change those values in source, then run the public-site checks before deploying:

```bash
cd site
npm run check
npm run build
```

The dashboard URL is intentionally hardcoded there until a custom public/private
domain setup creates a real need for another environment variable.

## Deploy Previews And Branch Deploys

Netlify may create deploy previews and branch deploys from the connected
repository when those features are enabled in the Netlify site settings. This
repository does not add preview-specific configuration. Preview, branch, and
production deploys use the same `site/netlify.toml` build shape.

The production branch is controlled in Netlify, not in this repository file.

## Custom Domain Checklist

When a custom domain is added later:

1. Update `site/astro.config.mjs` so Astro `site` matches the public canonical
   domain.
2. Update Render `PUBLIC_SITE_URL` so auth-page logos return to the public
   site.
3. Configure the custom domain and HTTPS in Netlify.
4. Redeploy both surfaces as needed and verify canonical URLs, auth logo links,
   and the public footer dashboard link.
