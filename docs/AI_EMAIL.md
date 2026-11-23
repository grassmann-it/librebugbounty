# Prepare an email with OpenAI

Development feature on `feat/2.3.0`; not part of the tagged 2.2.0 release.

Open a case and choose **Prepare email** (**E-Mail vorbereiten**). Select German
or English, inspect the outgoing data, then explicitly generate a draft. You can
edit, copy or download the result. The draft exists in the current page until
you leave or reload it; download text you want to keep.

The first version composes one case at a time. It does not send email, create a
Gmail draft, mark a case as reported, discover contacts or scan a target. A
selected web reporting route can also receive the generated submission text.

## What goes to OpenAI

The request preview shows the exact JSON body: the hostname, language and
qualified report components with their certainty, plus the writing instructions
and output schema. Only clicking **Draft with OpenAI** starts the external
request. Previewing or downloading the JSON makes no model call.

Recipient addresses, the original PoC URL, stored screenshots, raw response
bodies, internal case IDs and private report-gen examples are not model input.
The application adds the exact original PoC and controlled factual paragraphs
locally. The model supplies the subject, opening and closing. Unsupported,
missing or stale report projections cannot be used; changing the case or contact
after preview requires another preview.

Read the whole result before using it. Schema and local checks do not establish
that generated prose is factually correct. Preparing a draft is not a review
receipt or evidence of a sent report.

## Server configuration

Set `OPENAI_API_KEY` in the application's server environment or its ignored
`.env.local` file. Use a project key for the API organization that should pay for
requests. Never put the key in browser JavaScript or commit it.

The default model is `gpt-6.2-sol` with medium reasoning. The backend uses
Symfony HttpClient to call the Responses API directly; no SDK or curl subprocess
is required in the web request. The API key stays server-side. Without a key,
local preview and request export remain available.

Optional server settings are `OPENAI_EMAIL_MODEL` (`gpt-6.2-sol` or
`gpt-6.2-astra`) and `OPENAI_EMAIL_REASONING` (`low`, `medium`, `high`, `xhigh` or
`max`, subject to model/account support). Leave medium enabled for the initial
integration; a larger reasoning budget can take longer. These are deployment
settings, not values submitted by the browser.

Requests have a bounded timeout and no automatic retry. If a response is lost,
the provider may already have processed and billed the request; a manual retry
can incur another charge. API failures leave the existing text available for
editing. `store: false` disables response storage through the Responses API; it
does not itself establish an organization's Zero Data Retention eligibility.
See [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data).

## Direct curl request

Download the request JSON from the preview and review it. With `OPENAI_API_KEY`
already set in your shell, run:

```sh
curl --fail-with-body --silent --show-error \
  --connect-timeout 10 --max-time 120 \
  --url https://api.openai.com/v1/responses \
  --header 'Content-Type: application/json' \
  --header "Authorization: Bearer $OPENAI_API_KEY" \
  --data-binary @openai-email-request.json \
  --output response.json
```

This command makes a real, billable API request. `response.json` is a provider
response, not the finished email: the application normally validates it, checks
that the case is still current and adds the local factual sections and PoC.
There is no response-import workflow in this first version. Do not treat a raw
curl response as a reviewed report.

The request format follows the official [Responses text-generation
guide](https://developers.openai.com/api/docs/guides/text) and [Structured
Outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs).
Refusals, incomplete responses and API errors still require separate handling.

## API credits

Eligible ChatGPT Pro and Business subscriptions can activate API credits for a
linked API organization. Responses usage can draw on those credits. Availability,
expiry and the current balance belong to that organization; this application
does not claim to know them. Check the official [API credits
guide](https://developers.openai.com/api/docs/guides/chatgpt-api-credits) and your
API billing settings. If purchased credits remain after free credits run out,
requests can continue against the purchased balance.

## Skills and MCP

The first implementation adapts general report-writing constraints into a
bounded application workflow. It does not execute a personal skill file or
upload private examples. A later skill/profile system and MCP adapter should
share the same case projection and validation rules. The [2.3.0 architecture
proposal](design/2.3.0-ai-email-and-mcp.md) describes that separation and the
remaining work; an MCP server is not included in this slice.
