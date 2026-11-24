# Prepare an email and save a Gmail draft

Development feature on `feat/2.3.0`; not part of the tagged 2.2.0 release.

Open a case and choose **Prepare email** (**E-Mail vorbereiten**). Select German
or English, inspect the outgoing data, then explicitly generate a draft. You can
edit, copy or download the result, or save it as a Gmail draft when the optional
connector is configured. The editor exists in the current page until you leave
or reload it; download text you want to keep locally.

The first version composes one case at a time. You can start without AI or
explicitly request an OpenAI introduction. An optional Gmail connection can
save your reviewed text as a draft. Preparing or saving a draft does not send
email, mark a case as reported, discover contacts or scan a target. A selected
web reporting route can receive copied submission text; it cannot create a Gmail
draft addressed to a website URL.

## What goes to OpenAI

The request preview shows the exact JSON body: the hostname, language and
qualified report components with their certainty, plus the writing instructions
and output schema. A selected custom writing profile adds its instructions and
example emails. Only clicking **Draft with OpenAI** starts the external
request. Previewing or downloading the JSON makes no model call.

The application does not automatically include the current case's recipient,
original PoC URL, stored screenshots, raw response bodies, internal case IDs or
private report-gen files in model input. Text you manually enter into a profile
or example is included when you select that profile: remove names, addresses or
URLs you do not want to transmit and inspect the exact request preview.
The application adds the exact original PoC and controlled factual paragraphs
locally. The model supplies the subject, opening and closing. Unsupported,
missing or stale report projections cannot be used; changing the case or contact
after preview requires another preview.

Read the whole result before using it. Schema and local checks do not establish
that generated prose is factually correct. Preparing a draft is not a review
receipt or evidence of a sent report.

## Manage writing profiles and example emails

Open **Settings → AI management** (`/settings/ai`) to create a named writing
profile. Give it your own style instructions and optional named example emails.
Saving or editing a profile stores it locally; it makes no provider request and
does not import Gmail messages or personal skill files. The built-in profile
remains available as a fixed starting point.

Choose a profile in the email editor before previewing or generating. Its name
and version identify the instructions and examples in the request. A profile
can be selected as the default for newly opened editors. Already prepared text
keeps its original profile binding; changing the default does not retarget it.

Profiles guide the AI-written subject, opening and closing. They do not change
case findings, certainty, technical paragraphs or the exact original PoC. Example
emails illustrate style; their facts and recipients are not facts about the
current case. The application's fixed writing rules and output validation still
apply. Manual drafting uses the local starting text rather than running these
instructions.

Edits create a new revision. Concurrent edits are rejected while preserving the
submitted form for correction. Archiving removes a profile from new selections
without deleting its history. A changed or archived profile makes an earlier
request preview stale; review a new preview before generating again. Existing
Gmail receipts remain tied to the profile used for their reviewed message.

Profiles and their historical example texts are private application data in the
SQLite database and therefore included in the normal database backup. Archive
is not erasure. Keep backups private too. The connection panel displays the
configured provider and model; API keys remain server configuration.

## Server configuration

Set `OPENAI_API_KEY` in the application's server environment or its ignored
`.env.local` file. Use a project key for the API organization that should pay for
requests. Never put the key in browser JavaScript or commit it.

The default model is `gpt-6.2-sol` with medium reasoning. The backend uses
Symfony HttpClient to call the Responses API directly; no SDK or curl subprocess
is required in the web request. The API key stays server-side. Without a key,
local preview, request export and **Start without AI** remain available. The
Gmail connector does not require an OpenAI key.

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

## Save the edited text as a Gmail draft

Review the final recipient, subject, message and optional local signature, then
select the review checkbox and choose **Save as Gmail draft**. Editing the text
clears that checkbox. Gmail receives the final edited message, including the
original PoC and signature; those fields are still excluded from OpenAI input.

The server rechecks the current case and selected email route. It reserves a
durable attempt before contacting the Gmail adapter. The adapter creates the
draft and reads that same draft back to verify its recipient, subject, complete
text and draft status. A create response alone is not a verified success.

An ambiguous result keeps the attempt locked. Check its existing receipt instead
of creating another draft. Reconciliation reads a known draft ID; it never
guesses that a timeout means no draft was created. A proven rejection before any
Gmail write can permit an explicit new attempt after its cause is fixed. If the
case changes while Gmail is creating the draft, the result can exist remotely
and be marked stale locally. It is not silently deleted or recreated.

This protects attempts made through this connector, including concurrent tabs
and lost replies. It is not a full reconciliation of every message, draft or
external reporting tool in your mailbox. An existing Gmail draft is edited in
Gmail; this first connector does not update, delete, star or send it.

The optional host adapter in `bin/gmail-draft-bridge.py` reuses an existing
`report_gen` installation and its OAuth client. It exposes a small local Unix
socket, not an internet port. In DDEV the socket is accessible through the
existing project mount; Google credentials and the Python environment remain
on the host. The bridge and web process must use the same Unix user. Its socket
directory and private receipts are owner-only. No new OAuth token store is
created by this integration.

Set `EMAIL_GMAIL_SOCKET` to the socket path as seen by the PHP process and
`EMAIL_GMAIL_ACCOUNT` to the expected Gmail account. Run the database migration
for durable attempt receipts before enabling the connector. The adapter's
`--help` lists its configured host paths and launch options. Do not expose its
socket or receipt directory through the document root. The existing report-gen
authentication client is responsible for refreshing its token.

For a host checkout at `/absolute/path/to/librebugbounty`, an example launch is:

```sh
/absolute/path/to/report-gen/.venv/bin/python -B \
  /absolute/path/to/librebugbounty/bin/gmail-draft-bridge.py \
  --socket /absolute/path/to/librebugbounty/var/gmail-bridge/bridge.sock \
  --report-gen-root /absolute/path/to/report-gen \
  --account operator@example.invalid \
  --receipt-dir /absolute/path/to/librebugbounty/storage/gmail-drafts
```

Replace all paths and the account with your existing installation. For DDEV,
the corresponding application settings are:

```dotenv
EMAIL_GMAIL_SOCKET=/var/www/html/var/gmail-bridge/bridge.sock
EMAIL_GMAIL_ACCOUNT=operator@example.invalid
```

Run `ddev exec php bin/console doctrine:migrations:migrate --no-interaction`
before enabling the connector. A user service can supervise the launch command
with `UMask=0077` and `Restart=on-failure`. Run it as the project owner, not root;
it checks the connecting process's Unix identity. Starting the service creates
local storage but makes no Google request. Stop the service before replacing
its storage or authentication installation.

The socket is replaceable runtime state. The host database
`storage/gmail-drafts/gmail-drafts.sqlite3` is persistent private data: it
contains submitted message text and remote IDs needed to prevent duplicate
attempts. Do not clear it as a cache. The application database separately keeps
attempt receipts without the message body. The [backup tool](../BACKUP.md)
automatically includes the host database at this default path; use
`--gmail-receipts /absolute/path/to/gmail-drafts.sqlite3` for a custom location.
Keep the bridge and other host writers stopped during backup and recovery.
Restore writes the host database beside `database.sqlite` in a new directory;
it does not switch the running bridge. Restoring an old snapshot does not undo
newer Gmail operations, so reconcile intervening operations before creating
more drafts.

Only an explicit draft operation uses Gmail. Local status reads and application
startup do not read the mailbox. The implementation uses the documented
[draft creation](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/create)
and [draft retrieval](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/get)
operations; it has no send operation.

## Skills and MCP

The implementation adapts general report-writing constraints into a bounded
application workflow. It does not execute personal skill files or import their
examples. User-entered profiles are managed locally and their selected content
is part of the explicit request preview. A later profile-package importer and
MCP adapter should share the same case projection and validation rules. The [2.3.0 architecture
proposal](design/2.3.0-ai-email-and-mcp.md) describes that separation and the
remaining work; an MCP server is not included in this slice.
