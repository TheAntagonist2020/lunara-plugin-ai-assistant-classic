# LUNARA AI Assistant Classic

Private WordPress plugin source for the Lunara Film Classic Editor AI assistant.

## Role

This plugin powers the Classic Editor meta box and private suggestion service for Lunara editorial packaging, rewrites, Journal support, Review support, and provider-routed suggestion calls.

## Source Locations

- Local source: `G:\01-Lunara-Website\Backups-And-Exports\lunara-backups\work\lunara-ai-assistant-classic`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-ai-assistant-classic`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `0.6.1`.

### 0.6.1 Gemini credential hardening

- Sends the Gemini key in the `x-goog-api-key` header instead of placing it in the request URL.
- Adds a release contract so credential transport and version metadata cannot silently regress.

## Secrets

Do not commit OpenAI, Anthropic, Gemini, Google, WordPress application passwords, option exports, or environment files. Runtime credentials belong in server constants or private WordPress settings and should never be printed back into the admin UI.

## Verification

- Run PHP lint and `php tests/release-contract.php` after edits.
- Confirm Settings > LUNARA AI Classic loads.
- Confirm Classic Editor screens still show the LUNARA AI meta box for configured post types.
- Confirm public routes do not leak AI settings, prompts, keys, or admin assets.
