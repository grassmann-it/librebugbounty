# Hunter contact suggestions

Development feature on `feat/2.3.0`. Hunter is an optional contact-discovery
provider beside the existing security.txt lookup. It does not prepare or send
messages, change the selected disclosure route or start a target scan.

## Configure the connection

Set `HUNTER_API_KEY` in the server environment or ignored `.env.local`, then apply
the pending database migrations:

```sh
ddev exec php bin/console doctrine:migrations:migrate --no-interaction
```

Keep the key out of browser code, URLs and Git. The application sends it in the
`X-API-Key` header to the fixed Hunter API endpoint using a separate, untraced
HTTP client. It does not import another tool's key or budget ledger.
**Settings → AI management** shows whether a key is configured; this is not an
account-authentication or credit-balance check. Opening settings makes no Hunter
request.

## Search from a case

Open **Contact suggestions** in the case, review the displayed domain and credit
notice, then explicitly start the Hunter lookup. The request contains only that
server-selected domain and fixed pagination limits. It includes no PoC, cookies,
request body, screenshot, finding details or existing contact notes. The
application does not silently broaden the search to a parent domain.

One request retrieves at most ten addresses, without further pages. The
integration uses [Hunter Domain Search](https://hunter.io/api-documentation/v2#domain-search),
whose published cost is one credit for one to ten returned addresses. The
application conservatively reserves one credit before dispatch. Check current
plan conditions in the [Hunter API guide](https://help.hunter.io/en/articles/1970956-hunter-api).
There are no automatic retries or follow-up verification requests.

Results show the provider, queried domain, lookup time, local expiry and available
source links. Hunter's address type, confidence, provenance, department and
verification metadata remain provider-reported information. Confidence and
deliverability do not establish that someone is responsible for security
reports. Review the source and choose a suitable contact yourself. Existing
selected routes are preserved until you explicitly replace them.

The server rechecks the case, exact domain and contact/check restrictions. A
changed or blocked case cannot adopt a stale result as a current suggestion.
Official disclosure instructions and security.txt remain useful independent
sources; Hunter is not a substitute for checking them.

## Cache, reservations and recovery

Successful results, including no matches, are cached locally for seven days.
Another explicit lookup for the same exact domain can reuse that result across
cases without a new API request. Cache reuse does not renew its expiry.

A separate `hunter_domain_lookup` table records attempts and reservations before
network I/O. Concurrent tabs and cases share that record. Pending or unknown
outcomes block a new request; a timeout is not treated as proof that no credit
was used. A rejection proven before dispatch can release its reservation.
Provider HTTP errors retain the conservative reservation.

This installation permits at most four reserved searches per exact queried
domain, without an automatic daily or per-case reset. Provider-reported cost
headers may be displayed separately; they do not silently replenish that local
limit. This is an application-local counter, not the account's remaining balance
or a shared budget with other tools. It does not group different subdomains by
operator. Do not reuse an existing operator-wide budget arrangement without
coordinating those separate callers.

Deleting a case does not delete the independent reservation/cache record. It is
private application data and is included in the normal SQLite backup. Restoring
an older backup does not restore spent Hunter credits or reverse newer requests;
reconcile intervening activity before resuming paid lookups. An unresolved
attempt remains blocked for investigation rather than being automatically
retried or cleared.
