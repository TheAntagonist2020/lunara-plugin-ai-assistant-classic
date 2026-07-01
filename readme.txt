=== LUNARA AI Assistant Classic ===
Contributors: lunara
Requires at least: 6.3
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPL-2.0-or-later

Private LUNARA editorial assistant for the WordPress Classic Editor, including Journal and Review post types.

== Description ==

Adds a Classic Editor meta box for LUNARA editorial packaging:

* titles
* deks and excerpts
* H2 subheaders
* pull quotes
* complete rewrites of selected or pasted reference text
* Journal packaging
* full editorial packages
* packaging audits

Default post types: post, journal, review.

== Installation ==

Upload the plugin zip and activate it. Then visit Settings > LUNARA AI Classic, choose an AI provider, and add the matching API key.

Provider optionality: pick Anthropic (Claude), OpenAI, or Google Gemini from the AI provider dropdown. Choosing one provider routes every task to it, so a single API key is enough. "Automatic" uses whichever key is set (Claude preferred) and applies smart per-task routing when several keys are present. Keys can also be defined on the server: LUNARA_ANTHROPIC_API_KEY / ANTHROPIC_API_KEY, LUNARA_OPENAI_API_KEY / OPENAI_API_KEY, or LUNARA_GEMINI_API_KEY / GEMINI_API_KEY / GOOGLE_API_KEY.

Do not activate this alongside another plugin that uses the same editor surface unless you intentionally want both assistant UIs visible.

== Changelog ==

= 0.6.0 =
* Added an AI provider selector (Anthropic/Claude, OpenAI, Google Gemini, or Automatic). Choosing a provider routes every task to it so one API key is enough. The main Generate box previously required OpenAI specifically; it now uses the selected provider.
* Automatic mode picks whichever provider has a key (Claude preferred) and keeps smart per-task routing when several keys are set, falling back gracefully when a preferred provider has no key.
* Settings now show which provider is currently active and warn when the active provider has no key.
