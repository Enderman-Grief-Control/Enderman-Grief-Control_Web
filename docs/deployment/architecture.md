# Deployment Architecture

Enderman Grief Control is split into two deployed surfaces:

- Public project site: Astro + TypeScript in `site/`, deployed to Netlify.
- Private maintainer app: Laravel + Inertia + React at the repository root,
  deployed to Render.

The public site is the visitor-facing home. The private app is the
auth-protected dashboard for analytics and operations. Ordinary public page
rendering must not depend on Render being awake or reachable.

## Services

```text
Visitor
  -> Netlify
       Public Astro site from site/
       Official distribution links
       Footer dashboard link
          -> Render
               Private Laravel app
               /dashboard -> login when signed out
               /dashboard -> analytics when signed in
                    -> Supabase PostgreSQL

Maintainer
  -> Render login/dashboard
       Auth-page logo
          -> Netlify public site

GitHub Actions
  -> tests and validation workflows
  -> scheduled php artisan metrics:collect
       -> Supabase PostgreSQL
```

## Ownership

| Area                       | Owner                               | Notes                                                                                                                    |
| -------------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Public project content     | Git, rendered by Netlify            | The Astro source, screenshots, public links, and deployment docs are version-controlled in this repository.              |
| Public changing metrics    | Future Supabase public read model   | M2 may expose a narrow public stats model for the Astro site. The current public site has no runtime metrics dependency. |
| Private analytics and auth | Render app with Supabase PostgreSQL | Metric snapshots, distribution records, sessions, cache, queue tables, and auth-backed dashboard data stay private.      |
| Scheduled collection       | GitHub Actions                      | The production scheduler runs `php artisan metrics:collect` directly against Supabase, independent of Render.            |

## Surface Boundaries

Netlify builds the public site from `site/` and serves static public pages.
Those pages can link to Render, Modrinth, CurseForge, GitHub, and the
maintainer portfolio, but the initial page render does not call the Render app.
This keeps the public project site fast and available even when the Render Free
service is asleep.

Render builds the private Laravel app from the repository root `Dockerfile`.
The app redirects `/` to `/dashboard`; guests are sent to login by the
dashboard auth middleware, and signed-in maintainers land on the dashboard.

Supabase is the production PostgreSQL store. Today it holds private analytics
snapshots and application tables. Later, M2 may add a deliberately narrow
public read model for social-proof stats.

GitHub Actions owns CI checks and production metrics collection. The scheduled
workflow does not wake Render; it boots Artisan in Actions and writes snapshots
to Supabase.

## Moving Between Apps

Public visitors start on the Netlify site:

```text
Netlify public site
  -> official distribution link
  -> Modrinth, CurseForge, or GitHub
```

Maintainers can use the quiet footer dashboard link:

```text
Netlify public site
  -> Maintainer dashboard footer link
  -> Render /dashboard
  -> login when signed out
  -> dashboard after sign-in
```

The private app links back to the public site from its auth-page logo:

```text
Render login/register-style auth pages
  -> logo link from PUBLIC_SITE_URL
  -> Netlify public site
```

## Render Free Cold Start

The public site does not depend on Render cold-start time. The only visitor path
that can wake Render is the footer dashboard link. The post-deploy manual check
should record how long that first dashboard/login request takes on the Render
Free plan.

Current finding: the Render Free cold start takes about one minute before the
dashboard/login request responds.

## Related Docs

- [Netlify deployment](netlify.md)
- [Render deployment](render.md)
- [Production metrics collection](metrics-collection.md)
