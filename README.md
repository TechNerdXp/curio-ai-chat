# Curio AI Chat

An AI chat widget for WordPress that answers only from a knowledge base you
control, and says it does not know rather than inventing a price, a date or a
phone number.

**Live demo:** <https://technerdxp.com/curio/> · **Licence:** GPLv2 or later

## The problem it exists for

Most AI chat plugins have the same failure, and it is not a small one: asked
something they cannot answer, they answer anyway. Ask one what a wedding shoot
costs and it produces a confident, plausible, entirely invented figure — and the
customer believes it, because it is on your website in your brand colours.

Curio will not do that, and not because a model was asked nicely.

1. A visitor asks a question.
2. **Your site**, not the AI, searches your knowledge base for matching passages.
3. If nothing matches, **no request is sent to any AI provider at all.** The
   decline is written by the plugin. There is no model in the loop to be creative
   with.
4. If something does match, those passages and only those passages are sent,
   with rules the tone setting cannot edit away: never estimate a price, a date,
   availability, a turnaround, a phone number or an address.
5. The answer comes back with a link to the page it came from, so the visitor can
   check it.

The decline is the product. Everything else follows from it — including the
hand-off button, which appears the first time the assistant cannot help and
turns a dead end into a lead.

## Bring your own AI

Anthropic (Claude), OpenAI or Google Gemini. Your key, your account, billed to
you directly. Nothing is resold and nothing routes through the developer. The
model list is fetched from your provider's live API, so it never offers a model
that has been retired.

It ships in **demo mode**: no key, no API call, no cost, and it declines in
exactly the places the real thing declines. There is no sample data, so on a
fresh install it correctly knows nothing at all.

## Built so it cannot run up a bill

A public endpoint in front of a metered API is an unbounded charge on somebody's
card, so: a per-visitor rate limit on by default, a monthly ceiling you set,
answers cached and re-served free, a hard cap on question length, and the
provider's own reported token counts shown per month.

## Privacy

No IP addresses are stored, for anyone — rate limiting uses a salted hash. No
cookies: the conversation lives in the browser tab's session storage, survives a
reload, and is gone when the tab closes. Conversation logging is off by default
and wired into WordPress's own export and erasure tools. Nothing is ever sent to
the developer.

## Development

The plugin is the whole of this repository. It has no build step — the PHP, CSS
and JavaScript that ship are the ones in the tree, unminified and readable.

It is developed in a larger workspace that carries the test suites, the listing
artwork and the release tooling. Everything there gates on:

| Gate | What it does |
|---|---|
| Behavioural suite | 154 checks, mostly on *refusing* correctly |
| Renderer suite | 50 checks — real XSS payloads fired at the reply renderer |
| Widget suite | 24 checks driving the real widget in a browser |
| Accessibility | axe-core, WCAG 2.1 AA, across all three skins |
| Escaping and i18n | token-based audit of every PHP file |
| WordPress.org compliance | the statically decidable half of Plugin Check |

Assistant replies are built with `createElement` and `textContent` and never
touch `innerHTML`. Every reply is untrusted text — it comes from a language
model, repeating knowledge-base entries, which came from web pages. The renderer
suite exists to keep that true.

## Extending it

Ten filters, including `curio_providers` to register your own AI provider — a
self-hosted model, an OpenAI-compatible gateway, an internal endpoint — without
forking anything. `curio_relevance_threshold` is the one dial that trades
caution against helpfulness. The Help tab inside the plugin lists them all.

Everything the settings screen does is also available through WP-CLI, including
`wp curio ask "..."`, which puts a question through exactly the path a visitor's
would take and tells you whether it was grounded, greeted or declined.

## Licence

GPLv2 or later. See `LICENSE.txt`.

Built and maintained by [TechNerdXp](https://technerdxp.com/).
