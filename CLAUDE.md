# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

A GLPI plugin (PHP) that integrates GLPI 10/11 with the WATI WhatsApp API. It enables bidirectional WhatsApp communication between support technicians and ticket requesters.

**Plugin directory name:** `watiplugin`
**Install path:** `<glpi_root>/plugins/watiplugin/`
**Version:** 2.1.1

## Deployment

There is no build step. Deployment is manual:
1. Copy the plugin folder to `<glpi_root>/plugins/watiplugin`
2. Activate via GLPI: **Setup > Plugins > WATI Pro Notifier > Install/Enable**
3. Configure via **Setup > Plugins > WATI Pro Notifier > Settings**

## Architecture

### GLPI Plugin Hooks (setup.php + hook.php)

`setup.php` registers two GLPI hooks:
- `item_update` on `Ticket` → `plugin_watiplugin_notification_assign` — fires when a ticket is updated; sends WhatsApp to assigned technicians
- `item_add` on `ITILFollowup` → `plugin_watiplugin_notification_followup` — fires when a follow-up is added; sends WhatsApp to the requester (and to the technician if requester replied)

Both hooks call `plugin_watiplugin_call_wati_api()` which uses `file_get_contents` with a stream context (not cURL) to POST to the WATI `sendTemplateMessage` endpoint.

### WATI Template Message Structure

All notifications use a single configurable WATI template with three parameters:
- `{{name}}` — recipient's first name
- `{{ticket}}` — ticket ID + message content
- `{{url}}` — link to the ticket in GLPI

The template must be pre-approved in WATI/Meta before use.

### Webhook (webhook.php)

A standalone PHP file that bootstraps GLPI manually (no session/auth). Used as the endpoint for WATI Flow Builder (chatbot). Accepts POST with JSON body.

Actions dispatched via `$data['action']`:
| Action | Function | Purpose |
|---|---|---|
| `validate` | `validateUser()` | Look up user by phone number |
| `create_ticket` | `createTicketFromWati()` | Create ticket, optionally as guest |
| `create_guest_user` | `createGlpiUser()` | Register new user from WhatsApp data |
| `add_followup` | `addTicketFollowup()` | Post comment to existing ticket |
| `get_user_tickets` | `getUserTickets()` | List last 5 open tickets for a user |

**Critical:** `webhook.php` line 4 hardcodes the GLPI root path (`$glpi_root = '/var/www/glpi2'`). This must be updated for each deployment environment.

### Configuration

Stored in `glpi_plugin_watiplugin_configs` (single row, id=1). Fields:
- `wati_url` — WATI API base URL (e.g., `https://live-server-XXXXX.wati.io`)
- `wati_token` — Bearer token for WATI API
- `wati_template_name` — Approved WATI template name
- `glpi_base_url` — Public URL of this GLPI instance (used in ticket links)
- `glpi_root_path` — Absolute filesystem path to GLPI root

Config is managed through `PluginWatipluginConfig` (`inc/config.class.php`) and rendered by `config.php` + `front/config.form.php`.

### Phone Number Handling

User lookup searches both `mobile` and `phone` fields using `LIKE "%phone%"`. Numbers are stripped of non-numeric characters before WATI API calls (`preg_replace('/[^0-9]/', '', $phone)`). Phone numbers must include country code (e.g., `521...` for Mexico).

### Logging

All debug output goes to `files/_log/watiplugin.log` via `Toolbox::logInFile("watiplugin", ...)`. The log is verbose — many entries are labeled "Error crítico" even for non-errors (leftover debug labels).

### Anti-Echo Filter

In `plugin_watiplugin_notification_followup`, the system compares `$author_id` (follow-up author) with `$tech_id` (assigned technician) to avoid sending the technician a notification about their own comment.

### Guest User Creation

`createGlpiUser()` creates users with profile ID 1 (assumed to be Self-Service/Post-only). The guest's `name` field is set to `guest_name` (not a username — GLPI uses `name` as the login field, which may cause conflicts if the name is not unique).
