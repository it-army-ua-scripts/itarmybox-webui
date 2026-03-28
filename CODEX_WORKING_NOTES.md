# Codex Working Notes

## What this project is

This repository is a PHP WebUI for `itarmybox`.

Main purpose:
- manage modules: `mhddos`, `distress`, `x100`
- start and stop services
- edit service parameters
- manage autostart and cron schedule
- manage traffic/power limit
- manage Wi-Fi AP settings
- manage time sync / NTP
- reboot and update the device

This is not a normal web app first. It is a system control panel for a Linux box.

## Architecture in one minute

UI layer:
- plain PHP pages
- static HTML/CSS
- vanilla JS

Privilege boundary:
- web pages should talk to `lib/root_helper_client.php`
- privileged operations should go through `root_helper.php`
- root helper is exposed through a Unix socket managed by systemd

Important files:
- `index.html` - main screen
- `status.php` - live status page
- `tool.php` - module settings page
- `autostart.php` - autostart + schedule UI
- `root_helper.php` - privileged backend
- `lib/tool_helpers.php` - service config parsing and validation
- `config/config.php` - allowed modules and editable params

## Project-specific constraints

Important assumption from the user:
- `docker-compose.yml` is not a real target environment
- do not spend time trying to make Docker the source of truth

Real operating model:
- deployed on a Linux device
- nginx/php/systemd/root-helper integration matters more than local Docker

## Safe way to work on this repo

1. Read before editing.
   Start from the page file, then JS, then helper functions, then `root_helper.php`.

2. Preserve the privilege boundary.
   If a page needs privileged data or privileged actions, prefer root-helper instead of direct `shell_exec`.

3. Be careful with text encoding.
   This repo had mojibake issues before.
   Prefer ASCII-safe representations when a file behaves badly:
   - HTML entities in raw HTML
   - JSON `\uXXXX` escapes in inline JSON
   - PHP `\u{...}` escapes in strings

4. Do not assume Docker reflects production.
   Changes should be judged against the appliance/server deployment model.

5. Validate every small step.
   After PHP edits, run `php -l` on changed files.
   After JS edits, re-read the changed block and watch for broken literals.
   After text changes, verify the file contents directly.

## Recommended validation workflow

For PHP files:
- `php -l status.php`
- `php -l server_time.php`
- `php -l tool.php`
- `php -l autostart.php`

For encoding-sensitive files:
- inspect changed lines directly
- search for suspicious mojibake fragments like `Р`, `СЃ`, `РЎ`

For root-helper related changes:
- confirm the page calls `root_helper_request(...)`
- confirm the action exists in `root_helper.php`
- confirm response shape matches UI expectations

Manual smoke check after Iteration 1 changes:
- open main page and verify the power value suffix renders as `Мбіт/с`
- load `/server_time.php?lang=uk` and verify readable Ukrainian date text
- open update page and verify update action still produces visible log output
- open status page and verify it still returns JSON for `?ajax=1`

## High-risk areas

### 1. `root_helper.php`

This file is still the privileged entrypoint, but it no longer carries every domain inline.
Changes here can affect:
- services
- cron
- traffic shaping
- Wi-Fi
- time sync
- update flow

Treat every change here as potentially cross-cutting.

Current split:
- `root_helper/vnstat.php`
- `root_helper/time_sync.php`
- `root_helper/wifi.php`
- `root_helper/traffic_limit.php`
- `root_helper/system.php`
- `root_helper/distress_autotune.php`
- `root_helper/dispatch.php`

### 2. `index.html` and inline translations

The main page contains embedded translations and user-facing text.
Encoding mistakes here are very visible.

### 3. Home page JS modules

The main page logic is now split across:
- `js/home_runtime.js`
- `js/home_power.js`
- `js/home_monitor.js`
- `js/home_userid.js`
- `js/home_init.js`

Together they drive:
- traffic slider
- system monitor
- version info
- user ID modal
- main page refresh flow

Small text changes can accidentally leave broken literals behind.

### 5. `system_monitor.php`

This endpoint uses direct non-root telemetry reads and command execution without going through root-helper.
After Iteration 1, shell invocation was reduced there by switching to direct process execution instead of shell command strings.
Do not expand this pattern further.
Revisit it in a later refactor if we want all OS command access to be centralized.

### 4. `autostart.php`

This page mixes:
- autostart
- schedule editing
- schedule overlap logic
- power slider UI

It is easy to break the relationship between autostart and schedule.

## Editing strategy I should follow

When making future changes:
- prefer minimal patches
- keep business logic in helper/backend layers
- keep page files thin
- remove direct privileged shell calls from public PHP pages when possible
- preserve current user-visible behavior unless explicitly changing it

## If something looks broken

Check these first:
- encoding corruption
- direct shell calls bypassing root-helper
- mismatch between page JSON expectations and root-helper response
- schedule/autostart mutual exclusion logic
- hardcoded environment paths

## Current known notes

- Docker config is intentionally not the priority path.
- `status.php` fallback direct log read was removed in favor of root-helper-only flow.
- `index.html` and `server_time.php` needed encoding-safe text handling.
- home page JS is split into `home_runtime.js`, `home_power.js`, `home_monitor.js`, `home_userid.js`, and `home_init.js`.
- `autostart.php` schedule slider text uses a clean `Мбіт/с` suffix.
- `update.php` now runs updates through root-helper instead of direct shell execution.
- `server_time.php` now reads timezone through root-helper `time_sync_status`.
- `system_monitor.php` no longer uses `shell_exec`; it uses direct process execution for non-root telemetry commands.
- `lib/version.php` fetches remote version via HTTP stream context instead of shelling out to `curl`/`wget`.
- `tool.php` was slimmed by moving service/page helpers into dedicated files under `lib/`.
- `autostart.php` now relies on `lib/autostart_helpers.php` for schedule and autostart logic.
- `root_helper.php` now keeps bootstrap/request parsing plus shared core helpers, while `vnstat`, `time_sync`, `wifi`, `system`, and dispatch logic live in `root_helper/`.
- The traffic limit / power slider backend domain now lives in `root_helper/traffic_limit.php`; keep traffic limit math, state-file handling, tc reads, and apply logic there instead of in `root_helper.php`.
- The distress autotune domain now lives in `root_helper/distress_autotune.php`; do not duplicate autotune constants or functions back into `root_helper.php`.
- action dispatch is centralized in `root_helper/dispatch.php`.
- `x100` User ID is intentionally not configured from the global User ID screen.
- The WebUI reset action now runs through root-helper action `webui_reset_defaults`; `settings.php` is only a thin UI/controller for it.
- The WebUI reset action also intentionally does not overwrite `x100` `itArmyUserId`.
- `settings.php` should explicitly warn before reset that the Wi-Fi SSID will be changed back to the default `Artline`.
- All further changes should be made with the refactored architecture in mind: keep public PHP pages thin, prefer domain helpers/root-helper actions for orchestration, and do not move complex system logic back into UI entrypoints.
- The WebUI reset action restores these defaults:
  - any currently active module is stopped first, and reset leaves modules stopped
  - mhddos User ID empty, copies `auto`, threads `6500`
  - distress User ID empty, autotune enabled, concurrency `2048`
  - x100 `initialDistressScale` empty, `ignoreBundledFreeVpn=0`
  - autostart off, schedule cleared, traffic limit `31%`
  - Wi-Fi SSID `Artline`, Wi-Fi tx power `1.00 dBm`
  - timezone `Europe/Kyiv` with NTP ensure
  - update branch `main`
  - browser theme preference and desired home traffic slider value cleared from `localStorage`
- Distress autotune on the real box is back to the tuned `load average + RAM` model because the deployed kernel has `# CONFIG_PSI is not set` and does not expose `/proc/pressure/*`.
- The tuned target remains based on the confirmed hardware `2x Cortex-A53 + 4x Cortex-A73` with about one CPU-core equivalent left in reserve, plus a RAM safety window of `10%..15%`.
- Distress autotune state should be reasoned about as three separate values:
  - `desiredConcurrency` in autotune state
  - `configConcurrency` from `ExecStart`
  - `liveAppliedConcurrency` from the active process cmdline
- If autotune successfully writes new distress config concurrency but `serviceRestart('distress')` fails, the code now attempts rollback of config/state and returns richer diagnostics about the failed restart and rollback outcome.
- Distress autotune config/state writes should stay rollback-safe: `setDistressAutotuneMode()` and related reset/save flows should not leave `ExecStart` changed if autotune state persistence fails afterward.
- `lib/root_helper_client.php` now uses longer action-specific read timeouts for long-running root-helper actions.
- update branch state should be persisted only after a successful update path, not before running the update.
- Update runs through root-helper should not refresh `itarmybox-root-helper.socket` in the middle of the request; otherwise the active Unix socket response can be interrupted even when the update itself succeeds.
- The WebUI update flow should update only the WebUI repository; do not piggyback updates for `/opt/itarmy` from this script.
- WebUI update needs write access to `/var/www/html/itarmybox-webui` in `itarmybox-root-helper@.service`; with `ProtectSystem=strict`, missing this path in `ReadWritePaths` causes `read-only file system` during `git reset/clean`.
- theme support is shared: PHP pages mount the toggle through `render_app_footer()`, and the home page shows it inside the fixed header bar.
- home page language switching should call `window.ItArmyTheme.refresh()` so the toggle labels stay in sync after `applyLang(...)`.
- The home page power slider now guards against race conditions: scheduled applies block background refresh repainting, duplicate release events are deduplicated, schedule lock physically disables the slider, and stale POST responses should not overwrite newer slider state.
- The home page power slider should stay encapsulated in `js/home_power.js`; `js/home_init.js` should only wire high-level startup and call `initPowerControls()`.
- `render_back_link()` should be a plain fallback link to the logical parent page. Using `history.back()` created navigation loops after `POST -> redirect -> flash`, especially between `tool.php` and `tools_list.php`.
- The shared status log on `status.php` now supports fullscreen viewing by double-clicking the log box; it should keep live updates while open and close via second double-click, `Esc`, close button, or backdrop click.

## Refactor roadmap snapshot

### Iteration 1

- stabilize encoding-sensitive UI text
- reduce direct shell usage in public PHP endpoints
- add a practical smoke-check routine for changed endpoints
- document safe editing/verification steps

### Iteration 2

- split `lib/tool_helpers.php` by domain
- slim down `tool.php`
- split home page JS into focused modules
- reduce mixed UI/business logic in `autostart.php`

### Iteration 3

- split `root_helper.php` into domain files
- keep dispatch/bootstrap in `root_helper.php`
- unify action/response contracts
- centralize repeated command and file helpers

Iteration 3 status:
- completed initial domain split for `vnstat`, `time_sync`, `wifi`, and `system`
- replaced long action `if` chain with `dispatchRootHelperAction(...)`
- preserved existing response shapes for current actions

## Concrete execution order

1. finish Iteration 1 before touching large architecture
2. do `tool.php` + helper extraction before root-helper refactor
3. split frontend files before changing backend contracts
4. only then refactor `root_helper.php`
