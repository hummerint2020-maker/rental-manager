# T0.5 frontend scaffold

The standalone React 18/TypeScript application uses Vite, Tailwind CSS, React Router and a
TanStack Query provider. It contains one translated test page, a language selector and a
translated unknown-route page. There are no business APIs, authentication flows or hosting
health checks. WordPress routing and the PHP app shell are later tasks.

Arabic is the initial locale regardless of browser language. Both the HTML entry and runtime
set `lang="ar" dir="rtl"`; changing the language updates document language, direction, title
and all page copy from `src/locales/ar.json` or `en.json`. Locale selection is in memory only.
Use logical spacing utilities (`ps`, `pe`, `ms`, `me`) and `text-start` for directional layout.

Use Node 24 and npm:

```sh
npm --prefix frontend ci
npm --prefix frontend run dev
```

Open `http://127.0.0.1:5173/app/`. React Router is mounted at `/app`; Vite provides the
development history fallback. This does not register `/app/` in WordPress yet.

```sh
npm --prefix frontend run build
npm --prefix frontend exec -- playwright install chromium
npm --prefix frontend test
npm --prefix frontend run preview
```

The build runs strict TypeScript checks before bundling. Vite writes hashed JS/CSS, an HTML
entry and `manifest.json` into the dedicated root `build/` directory, clearing older outputs.
Every frontend change must rebuild and commit the complete `build/` directory. CI rebuilds
and rejects tracked differences or untracked files under `build/`, preventing stale releases.
Assets use a relative base so deployment is
not tied to a particular WordPress plugin URL. A future PHP shell should resolve entry assets
through the manifest and the actual plugin URL, rather than hard-code hashed filenames.
See [Vite's build options](https://vite.dev/config/build-options) and the
[official Tailwind Vite integration](https://tailwindcss.com/docs/installation/using-vite).

Preview mounts the built output at `/app/`, matching the SPA router, on port 4173.
Playwright launches this preview server itself with a strict port; stop any existing preview
before running tests. Browser tests exercise Arabic RTL with compiled styles, switching to
English LTR and back, router fallback/home navigation, and dictionary/manifest integrity.
Test artifacts go to ignored `tests/artifacts/frontend-playwright/`. The browser tests use
the real production bundle; run build before test. Linux CI installs Chromium system
dependencies with `playwright install --with-deps chromium`.

The same Playwright suite scans all files under `frontend/src` for physical-direction Tailwind
utilities, including variant prefixes, negative values and important modifiers. Use logical
alternatives (`ms`/`me`, `ps`/`pe`, `start`/`end`, `text-start`/`text-end`, `rounded-s`/`rounded-e`,
`border-s`/`border-e`). Fixture tests prove that the guard rejects physical classes and accepts
logical ones. Tailwind scans only `src`, so test fixtures do not add utilities to production CSS.

The T0.4 gate now detects the locked scaffold and enables npm install/build/test. Missing
lockfiles or scripts still fail. CI preserves the PHP/database integration and release/activation
smoke checks; no frontend placeholders are silently skipped for this scaffold.
