=== Curio AI Chat ===
Contributors: technerdxp
Tags: ai chatbot, chatbot, knowledge base, customer support, live chat
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI chat widget that answers only from knowledge you control, and says it does not know rather than inventing a price, date or phone number.

== Description ==

Most AI chat plugins have the same problem, and it is not a small one: given a question they cannot answer, they answer anyway. Ask one what a wedding shoot costs and it will produce a confident, plausible, completely invented figure, and a customer will believe it, because it is on your website in your brand colours.

**Curio will not do that.** It answers only from a knowledge base you control. When a question is not covered, it says so plainly and hands the visitor to you. That is not a setting you can accidentally turn off; it is how the plugin is built.

= How it actually works =

1. A visitor asks a question.
2. Your site, not the AI, searches your knowledge base for passages that match it.
3. **If nothing matches, no request is sent to the AI at all.** The decline is written by the plugin. There is no model in the loop to be creative with.
4. If something does match, those passages and only those passages are sent to your chosen AI, along with instructions it cannot edit away: never estimate a price, a date, availability, a turnaround time, a phone number or an email address; if it is not in the passages, you do not have it.
5. The answer comes back with a link to the page it came from, so the visitor can check it.

= What happens when it says no =

A grounded assistant declines more often than a guessing one. That is the trade, and it makes the moment straight after a decline the most valuable screen in the widget, so the plugin treats it as a feature rather than a failure.

* **A hand-off line** in the assistant's own words: your email, your phone number, whatever you want said.
* **A button beside it.** Point it at your contact page, an email address, a help desk or a booking link, and it appears the first time the assistant cannot answer. A sentence is easy to read past; a button is one press, and it is in front of the customer at the exact moment they are deciding whether to bother.
* **Every decline is logged** as a question your site does not answer yet, with an "Answer this" button on the Insights tab.

And it does not decline the easy one: somebody who opens with "hello" gets your greeting back, not "I do not have that detail", and it costs no API call to do it.

= Where its knowledge comes from =

* **Your existing content.** Tick which post types it may read — pages, posts, any custom post type — and it indexes them, splitting each one into passages so a question can be matched against a paragraph rather than a whole page. Edits are picked up the moment you save.
* **Your WooCommerce products.** Descriptions, price, SKU, stock status, categories. Prices are written out as sentences the assistant can quote, and refresh whenever a product changes.
* **Answers you write.** The things that are not on your site anywhere: what you charge, your opening hours, your cancellation policy, how long delivery takes. Type them in, or paste a JSON array to load an FAQ export in one go.

= Bring your own AI =

Choose **Anthropic (Claude)**, **OpenAI (ChatGPT)** or **Google Gemini**, paste your own API key, and pay the provider directly. Nothing is resold, nothing routes through the developer, and there is no subscription to this plugin.

The model dropdown is filled from your provider's live API, so it never offers you a model that has been retired. Press "Refresh model list" and it asks your key what it can actually reach.

= Demo mode, so you can try before you spend =

The plugin ships in demo mode. It answers from your knowledge base with no API key, no API call and no cost, and it declines in exactly the places the real thing will decline. It is an honest rehearsal, not a scripted one, and there is no sample data, so on a fresh install it correctly knows nothing at all.

= It looks like your site, not like a plugin =

Colour scheme (light, dark, follow the visitor's system setting, or fully custom), accent colour with a live contrast check against WCAG AA, corner style, three sizes, either bottom corner, launcher as an icon or a labelled pill, your own avatar, your theme's font, and a custom CSS box for anything else. If you use a block theme, one click reads the palette straight out of it.

Everything is emitted as CSS variables scoped to the widget, so a skin can never leak into your theme.

= Built so it cannot run up a bill =

The chat endpoint is public and sits in front of a metered API, which without limits is an unbounded charge on your card. So:

* A per-visitor rate limit, on by default.
* A monthly ceiling on total API calls, which you set.
* Repeat questions answered from cache, free, and cleared automatically the moment your knowledge or settings change.
* A hard cap on question length.
* Token counts reported by the provider itself, shown per month, so you always know what you are spending.

= The screen that makes it better every week =

Because the assistant declines what it has not been told, every decline is a real customer asking something your site does not answer yet. The Insights tab lists those questions, grouped and counted, with an "Answer this" button beside each one. Work down the list and the assistant improves without anyone tuning a model.

= Privacy, taken seriously =

* **No IP addresses are ever stored**, for anyone. Rate limiting uses a salted hash.
* **No cookies.** The conversation is held in the browser tab's own session storage so it survives a reload, never reaches the server, and is discarded when the tab closes.
* Conversation logging is **off by default**, with automatic retention enforced by a scheduled task rather than by you remembering.
* Wired into WordPress's own privacy tools: data export and erasure requests for registered users are handled automatically, and suggested privacy-policy text is offered to your policy page.
* No analytics, no tracking, no phone-home. The plugin sends nothing to its developer, ever.

= Manageable from the command line =

Everything the settings screen does is available through WP-CLI, because the
deployments that matter are the unattended ones: a provisioning script, a
staging refresh, a client site being set up for the fifth time:

`wp curio status` · `wp curio import faq.json` · `wp curio reindex` · `wp curio set business_name "..."` · `wp curio key anthropic --test` · `wp curio ask "do you deliver on Saturdays?"`

`wp curio ask` puts a question through exactly the path a visitor's would take
and tells you whether the answer was grounded or declined, which is the quickest way to
confirm a deployment works, and the only quick way to confirm it still refuses
what it should.

= It keeps its place =

Reload the page, follow a link and come back, get bounced through a payment gateway: the conversation is still there, still open, still scrolled where you left it. It is held in the browser tab and nowhere else, so it costs no database rows, needs no cookie banner and is gone when the tab closes. There is a "clear this conversation" button in the chat header for anyone who would rather start again.

= Also =

* No jQuery on the front end. One stylesheet and one deferred script, loaded only on pages the widget appears on.
* Keyboard accessible, screen-reader labelled, respects `prefers-reduced-motion`, works in Windows high-contrast mode.
* Full-screen on phones, the way every chat product people already use behaves.
* Translation ready.
* Ten filters for developers, including one to add your own AI provider without forking anything.

== External services ==

This plugin can send data to a third-party AI provider in order to generate replies. **No data is sent anywhere until you save an API key and select that provider.** In demo mode there is no outbound request of any kind, and only one provider is ever contacted: the one you selected.

**What is sent, in every case:** the visitor's message, the recent turns of that same conversation, and the knowledge passages your site retrieved for that question. **What is never sent:** IP addresses, visitor identities, email addresses, WordPress credentials, or any information about your site beyond the content you chose to index.

Requests are made when a visitor sends a chat message, when you press "Test" on the Connection tab, and when you press "Refresh model list".

= Anthropic (Claude) =

Used when Anthropic is the selected provider. Requests go to `api.anthropic.com`.

* Terms of service: https://www.anthropic.com/legal/commercial-terms
* Privacy policy: https://www.anthropic.com/legal/privacy

= OpenAI (ChatGPT) =

Used when OpenAI is the selected provider. Requests go to `api.openai.com`.

* Terms of service: https://openai.com/policies/business-terms/
* Privacy policy: https://openai.com/policies/privacy-policy/

= Google Gemini =

Used when Google Gemini is the selected provider. Requests go to `generativelanguage.googleapis.com`.

* Terms of service: https://ai.google.dev/gemini-api/terms
* Privacy policy: https://policies.google.com/privacy

You are responsible for your own account with whichever provider you choose, and for telling your visitors that a chat message is processed by a third party. The plugin offers suggested wording for your privacy policy under **Settings → Privacy → Privacy Policy Guide**.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Chat Assistant**.
3. **Sources**: tick the content the assistant may read, then press "Index site content now" and wait for the bar.
4. **Knowledge**: add the answers that are not written anywhere on your site: prices, hours, policies. Ten entries covers most of what a small business is asked.
5. **Assistant**: fill in your business name and, above all, what happens when it cannot help: the hand-off line it says, and the button that goes to your contact page or email address. That is the difference between a dead end and a lead.
6. Open your site and try it. Demo mode costs nothing.
7. **Connection**: when you are happy with what it knows, paste an API key from Anthropic, OpenAI or Google and switch provider.

== Frequently Asked Questions ==

= Will it ever invent a price? =

No. When your knowledge base has nothing matching a question, no request reaches the AI. The plugin writes the decline itself. When it does find something, the model receives those passages plus rules it cannot edit away, including an explicit ban on estimating any figure not in front of it. The "tone" box lets you change how it sounds; it cannot delete the rules that keep it honest.

= Do I need an API key to try it? =

No. Demo mode is the default. It answers from your knowledge base with no API call and no cost, and declines exactly where the real thing declines.

= What does it cost to run? =

You pay your AI provider directly for what you use. On the cheapest model from any of the three providers, a typical exchange is a fraction of a penny, and repeat questions are answered from cache for nothing. The Insights tab shows the token counts your provider reported, per month, so there are no surprises.

= It says it does not know something that is on my site. =

Three usual causes: that post type is not ticked on the Sources tab; the index has not been run since you wrote the page; or the visitor's wording is different enough that retrieval missed it. Add the question as a written answer on the Knowledge tab. Hand-written entries are deliberately weighted above indexed page text.

= Does it work with WooCommerce? =

Yes. Tick "Index products" on the Sources tab and it reads descriptions, price, SKU, stock status, categories and tags, refreshing whenever a product is saved.

= Can I make it match my brand? =

Yes: colour scheme, accent colour, corners, size, position, launcher style, avatar and font, with a live preview and a contrast check. Block themes can have their palette read in one click. Anything else goes in the custom CSS box.

= Will it slow my site down? =

One stylesheet and one deferred script, about 45KB of readable, unminified source and roughly 13KB over the wire once your server compresses them, and only on pages where the widget actually appears. No jQuery, no framework, no external CDN, and no request to any AI provider until a visitor sends a message.

= Is it accessible? =

The widget is keyboard operable throughout, labelled for screen readers, uses a live region so replies are announced, traps focus only when it covers the page, honours `prefers-reduced-motion`, and stays visible in Windows high-contrast mode. The appearance tab warns you if your accent colour fails WCAG AA contrast.

= Does the conversation survive a page reload? =

Yes. It is kept in the browser tab's own session storage, so a reload, a link followed and come back from, or a trip through a checkout all find the chat where it was left. Nothing is written to your database and nothing is sent anywhere. Closing the tab ends it, and there is a button in the chat header to clear it sooner.

= Can a visitor reach a human? =

Yes, once you have given them somewhere to go. On the Assistant tab, point the hand-off button at a page on your site, an email address or any web address: a help desk, a booking calendar, a messaging link. It appears the first time the assistant cannot answer, or from the start if you prefer. If you set nothing, no button is shown: one that goes nowhere is worse than none.

= Does it store what visitors type? =

Only if you switch it on, and it is off by default. No IP address is ever stored, for anyone. When logging is on, records are deleted automatically after a retention period you set, and are covered by WordPress's own data export and erasure tools.

= What happens to my data if I delete the plugin? =

Your API keys are always removed. Everything else is kept unless you tick "Delete everything when the plugin is deleted" on the Insights tab. Deactivating to debug a theme conflict should not destroy an afternoon of your writing.

= Can I use a different AI, or a self-hosted model? =

Yes. The `curio_providers` filter takes any class implementing the provider interface, so an OpenAI-compatible gateway, a local model or an internal endpoint can be added without forking the plugin.

= I am behind Cloudflare and everyone is being rate limited as one person. =

That is the expected behaviour when PHP only ever sees your proxy's address. Use the `curio_client_ip` filter to return the real client address from a header you control and trust.

== Screenshots ==

1. Answering from the knowledge base, in a chat headed with the name of the site it is installed on rather than the name of the plugin.
2. The guarantee, demonstrated rather than described: nothing in the knowledge base covers the question, so the plugin writes the decline itself and no request reaches the AI at all. A person is offered in the same breath.
3. On a phone the panel takes the screen, and it takes the part of the screen that can be seen: with the keyboard up it sits above the keys, header and message box included.

== Changelog ==

= 1.0.1 =
* On phones the open panel now fits the part of the screen that can be seen: with the keyboard up it sits above the keys, header and all, instead of sliding half off the top.
* Short conversations now rest on the message box instead of hanging from the top of an otherwise empty panel.
* The optional "Chat by Curio" credit now links to the plugin's own page rather than to the author's profile, so a visitor who taps it is told what the widget is.
* Every dash in the settings screens, the readme and the command line output rewritten as a comma, a colon or a full stop.

= 1.0.0 =
* First public release.
* Grounded retrieval with a relevance threshold: no matching knowledge means no AI request and an honest decline.
* A hand-off button to a contact page, an email address or any web address, shown the first time the assistant cannot answer.
* Conversations survive a page reload, held in the browser tab and written to no database, with a clear-conversation button in the chat header.
* Greetings are greeted rather than declined, without an API call.
* Three providers, Anthropic (Claude), OpenAI (ChatGPT) and Google Gemini, with live model lists.
* Demo mode with no API key and no cost, and no sample data of any kind.
* Indexing of pages, posts, custom post types and WooCommerce products, in batches, with automatic updates on save.
* Hand-written answers with bulk JSON import and export.
* Full appearance controls with live preview, block-theme palette detection and WCAG AA contrast checking.
* Per-visitor rate limiting, a monthly API ceiling, answer caching and a question length cap.
* Unanswered-question log so the knowledge base improves from real traffic.
* API keys encrypted at rest, never rendered back into the page, never autoloaded.
* No IP addresses stored; GDPR export and erasure support; suggested privacy policy text.
* Ten filters, including one to register your own AI provider.
* WP-CLI commands for status, import, export, reindexing, settings, keys and asking a test question.

== Upgrade Notice ==

= 1.0.1 =
Fixes the chat panel on phones when the keyboard is open.

= 1.0.0 =
First public release.
