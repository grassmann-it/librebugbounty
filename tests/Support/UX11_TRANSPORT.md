# UX11 isolated transport fixture

From the repository root, run the copied fixture against the intended assembled source tree:

```sh
ddev exec -d /var/www/html/var/uxpilot-ui-implementation-20261025/integration bash tests/Support/check_ux11_transport.sh
```

For local Sol3 development, substitute its absolute container directory. The script resolves source from its own location, creates a fresh `/tmp/librebugbounty-cribrum-badges-ux11-*` storage root inside DDEV and chooses a free loopback port. It checks the spawned server PID, source root and storage root returned by `__cribrum_badges_fixture`; it fails if an unrelated listener answers. The endpoint exposes runtime/source/storage identity, artifact and database paths, fixtures, every SQLite table, and a categorized calllog. The database, cache, logs and artifacts stay under that fresh root.

The passive check covers all 17 app families through GET navigation, including case/history anchors, diagnostics, review, passive interactive, inventory/workbasket, contact, intake/help, statistics, export, settings, status, errors, about and documentation. It asserts an identical all-table database snapshot, no refused default-client request, and no transport mutation. Named health/status mock reads are counted separately and may be nonzero. An explicitly POSTed retest must add exactly one local default-client `POST /retest` call; its synthetic 503 response does not prove a successful retest.

The fixture kernel uses `APP_ENV=test`, synthetic worker URLs and tokens, and a central `MockHttpClient`. The default worker/interactive client logs every attempt before refusing a non-`http://127.0.0.1:1` URL. Named release, health, security.txt and Cribrum-probe mocks also log calls. They never open a socket; their remote-looking configured URLs are recorded as mocked requests. This is an application dependency-injection boundary, not a container firewall. A service constructing an unrelated transport directly is outside this guarantee. Browser-side requests, popups, WebSockets and Playwright API requests require their own local-only guard in the central browser lane. All findings and stored evidence are synthetic, with no live target capture.
