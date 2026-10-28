# Hosting a free public demo (Render)

For a showcase link in a proposal or portfolio item. Not a production setup.

## What `ROUTEWISE_PUBLIC_DEMO=true` does

- The login page offers one **Enter the demo workspace** button that signs in the seeded *agent*, never an admin. Password login is disabled.
- Agents cannot change owners, scoring rules or webhook secrets, so a visitor cannot lock out the demo or read a secret.
- The data is synthetic. `php artisan routewise:demo-reset` wipes and re-seeds. It runs on every container start and daily at 03:00 (through `schedule:work`). It refuses to run unless the flag is on, or `--force` is passed.
- The same scheduler also runs the CRM retries every minute.

## Deploy

1. Push the project to GitHub. `.env` is git-ignored.
2. On render.com choose **New > Blueprint** (or **Web Service > Docker**), select the repository. `render.yaml` selects the free plan.
3. When the build finishes, open the `onrender.com` URL.

`APP_KEY` is generated at start and `APP_URL` comes from `RENDER_EXTERNAL_URL`.

## Honest limits

- The free plan sleeps after about 15 minutes idle; the next visit takes around a minute.
- SQLite lives inside the container, so data is lost on restart (the demo resets anyway).
- It runs `php artisan serve` (single process): fine for someone clicking around, not for load.
- **The Docker setup has not been built or run by the author** (no Docker on the build machine). The application behaviour is covered by tests (`PublicDemoTest`, `DemoResetTest`), but the first Render build may need a small fix; check the build log.
- A public webhook endpoint exists on the demo. Its sources use the published `whsec_demo_*` secrets, so anyone can post synthetic leads to it. That is acceptable for a demo that resets daily; do not put real data in it.

## Alternative

Run it locally and share it for a call with a free tunnel (Cloudflare Tunnel or ngrok). Keep `ROUTEWISE_PUBLIC_DEMO=true` while it is public.
