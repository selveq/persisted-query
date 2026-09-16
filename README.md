# ScandiPWA PersistedQuery

Fork of [scandipwa/persisted-query](https://github.com/scandipwa/persisted-query) 3.1.1, maintained by Selveq for Magento 2.4.9 and PHP 8.3. Module name and namespace are unchanged, and the package replaces `scandipwa/persisted-query` at every version, so it installs as a drop-in replacement. Selveq is not affiliated with or endorsed by Scandiweb.

## What it does

- Executes a registered query on `GET /graphql?hash=<hash>` and answers with `X-Pool` and a `Cache-Control` max-age, so Varnish or a CDN can cache the response; an unknown hash answers `410`.
- Registers a query on `PUT /graphql?hash=<hash>` only under the hash the theme computes for it (MurmurHash3 seed 1 over the query text as UTF-16 code units); a mismatch answers `400`.
- Caps the `SW-cache-age` a client may ask for at a configurable ceiling, honours `0` as do-not-cache, and answers uncacheable queries with `no-store`.
- Keeps registrations in Redis for 30 days; `bin/magento scandipwa:pq:flush` and the `persisted_query_response` cache type purge the Varnish pool.

## Install

```sh
composer require selveq/persisted-query
bin/magento setup:upgrade
bin/magento setup:config:set --pq-host=redis --pq-port=6379 --pq-database=5 --pq-scheme=tcp
```

Redis holds the registered queries, and caching the responses needs Varnish as the caching application. After upgrading from `scandipwa/persisted-query`, run `bin/magento scandipwa:pq:flush` once.

## Configuration

| Setting                                              | Default   | What it does                                                             |
| ---------------------------------------------------- | --------- | ------------------------------------------------------------------------ |
| `scandipwa_persisted_query/cache/default_ttl`        | `60`      | max-age when the registration carried no `SW-cache-age`                  |
| `scandipwa_persisted_query/cache/max_ttl`            | `2592000` | ceiling for `SW-cache-age`, never above the 30-day registration lifetime |
| `scandipwa_persisted_query/registration/verify_hash` | `1`       | refuse a registration whose hash is not the theme's hash of the query    |

## License

[OSL-3.0](LICENSE), the license of the original work. Scandiweb's copyright notices are kept in every file, and each file Selveq changed carries a `Modifications © Selveq` notice.
