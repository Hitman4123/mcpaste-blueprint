# MCPaste for Pterodactyl

A [Blueprint](https://blueprint.zip) extension that puts a **Share log** button
in every server console on a Pterodactyl panel. One click sends the console to
[MCPaste](https://mcpaste.com), which reads the log, pins the errors it
recognises to the top, and gives back a link to drop into a support ticket.

- The button sits next to Start, Restart and Stop.
- The panel sends the log, not the browser, so your MCPaste API key never
  reaches a customer's browser.
- The server's node, state, resource use, Docker image and egg are attached,
  so support doesn't have to ask.

> **Status: 1.0.0.** Tested end to end on a Pterodactyl + Blueprint panel with
> a Wings node and a running server (see [Testing](#testing)).

## Requirements

- Pterodactyl Panel with [Blueprint](https://blueprint.zip) installed. It is
  built for Blueprint `beta-2026-08`; Blueprint warns, but still installs, on
  other versions.
- An MCPaste account, and an API key from its **API keys** page.

## Install

From the Pterodactyl folder (usually `/var/www/pterodactyl`):

```bash
curl -fLO https://github.com/Hitman4123/mcpaste-blueprint/releases/latest/download/mcpaste.blueprint
blueprint -install mcpaste
```

Then open **Admin → Extensions → MCPaste** and fill in the two settings.

To update, download the new `mcpaste.blueprint` and run `blueprint -install
mcpaste` again. To remove it, run `blueprint -remove mcpaste`.

## Settings

| Setting | What it is |
| --- | --- |
| `MCPASTE_DOMAIN` | The MCPaste site to post to. Defaults to `https://mcpaste.com`. Any http(s) address works, if you use your own domain or run your own MCPaste. |
| `MCPASTE_TOKEN` | An MCPaste API key (`mcp_live_…`). Make one just for this panel, and give it the panel's hostname on MCPaste. Every shared log lands in that MCPaste account. |
| Attach the server owner's email | Off by default. See below. |

**Save** checks the key with MCPaste and tells you whether it worked.

The token is stored in the panel's database, encrypted with the panel's
`APP_KEY`. It is only read on the server when posting to MCPaste. The admin
page shows only its first and last few characters, and nothing sends it to a
browser.

## What gets sent to MCPaste

When someone presses **Share log** and confirms, the panel posts one paste to
`MCPASTE_DOMAIN/api/create`. It contains:

- **The console:** up to the last 1,000 lines the browser received from the
  server's console. That is the output since the console page opened,
  including the history Wings replays when it connects. IP addresses are
  masked if "Hide IP addresses" is ticked (on by default).
- **Server details**, as a block at the top:
  - server name, node name and state (running, offline…)
  - CPU, RAM and disk use, with limits
  - Docker image and egg
- **The owner's email address**, only if an admin switches that setting on.
- **The panel's hostname**, as the paste's source, and a title like
  "*server name* — console".

**Anyone with a paste's link can read it.** That's why the owner's email is off
by default. Pastes follow the expiry and other defaults of the MCPaste account
the key belongs to.

Nothing is sent until someone presses the button and confirms in the dialog.
The extension sends nothing on its own, and nothing to anyone other than
`MCPASTE_DOMAIN`.

## Who can share

Anyone who can open that server's console, which is the owner, root admins,
and subusers with the console permission (`websocket.connect`). The panel
checks this on every share.

- Suspended, installing or transferring servers can't be shared by
  non-admins.
- Each user can share 5 times a minute.

## Testing

1.0.0 was tested on a local Pterodactyl panel built from Blueprint's
official Docker image (Blueprint `beta-2026-08`), with a Wings node
(`ghcr.io/pterodactyl/wings`) running a small server that prints
Minecraft-style log lines. It posted to a local copy of MCPaste.

- **Install:** `blueprint -install mcpaste` succeeds, the panel's frontend
  build compiles the extension, and the panel's own `tsc` reports nothing in
  it. Reinstalling over it (an update) keeps its settings.
- **Admin page:**
  - rejects a bad key and confirms a good one with MCPaste
  - never puts the saved key in the page
  - stores the key encrypted
- **Console:**
  - **Share log** sits beside Start, Restart and Stop, and is enabled once
    the console connects.
  - With the server running, the dialog counted the lines received live.
    After a page reload, it also counted the history Wings replays.
  - Share returned a link, with Copy and Open it.
- **The paste:**
  - The details card arrived parsed, with live State, CPU / RAM and Disk
    from Wings, the Docker image and the egg.
  - IP addresses were masked, and the panel's hostname was shown as the
    source.
- **Who can share:**

  | Who | Result |
  | --- | --- |
  | The server's owner | a link (201) |
  | A subuser with console access | a link (201) |
  | A subuser without console access | 404, matching the 403 the panel's own console gives them |
  | No sign-in | 401 |
  | The owner, on a suspended server | 409 |
  | A sixth share in a minute | 429 |
  | An empty console | 422 |

## Building it yourself

```bash
node scripts/build.mjs   # → dist/mcpaste.blueprint (needs Node 22.2+, nothing from npm)
```

The script checks `extension/conf.yml` and `extension/components/Components.yml`
the way Blueprint does at install time. It then packs `extension/` into
`dist/mcpaste.blueprint`, a zip with `conf.yml` at its root, the same thing
`blueprint -export` makes. The same sources always give the same file.
Releases are built by `.github/workflows/release.yml` from a `v*` tag.

| Path | What it is |
| --- | --- |
| `extension/conf.yml` | Blueprint metadata and bindings |
| `extension/admin/` | Admin → Extensions → MCPaste (view and controller) |
| `extension/app/ShareController.php` | The share route: access check, details block, post to MCPaste |
| `extension/app/McpasteSettings.php` | Settings storage; the token is encrypted |
| `extension/routers/client.php` | `GET /api/client/extensions/mcpaste/status`, `POST …/servers/{uuid}/share` |
| `extension/components/ShareLogButton.tsx` | The button and its dialog |

## License

[MIT](LICENSE)
