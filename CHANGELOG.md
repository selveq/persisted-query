# Changelog

## 4.0.1

- The configured password and username reach Credis, so persisted queries work against a Redis or Valkey with `requirepass` instead of failing with `NOAUTH Authentication required`.

## 4.0.0

Forked from `scandipwa/persisted-query` 3.1.1. Module name and namespace are unchanged, and the package replaces `scandipwa/persisted-query` at every version, so it installs as a drop-in replacement.

- Magento 2.4.9 and PHP 8.3 support.
- Registrations store the query text instead of its AST, under `pq:query:<hash>` and `pq:ttl:<hash>` keys that expire after 30 days; run `bin/magento scandipwa:pq:flush` once after upgrading.
- A `PUT` must carry the theme's hash of the query: `400` on a mismatch, `409` for a different query under an existing hash, and it never executes the query.
- Query text is bounded at 64 KB; a larger registration answers `413`.
- `SW-cache-age` is clamped to a configurable ceiling, and `0` means do not cache.
- Uncacheable queries are answered with `no-store` and no `X-Pool`.
- Nesting depth and core's pre-execution guards now apply on the persisted path.
- Malformed registrations answer `502` and unknown or invalid hashes `410`, never a stack trace.
- Array `GET` parameters, a non-numeric `SW-cache-age` and a malformed `PUT` body no longer fatal.
- Default TTL, maximum TTL and hash verification are configurable under Stores > Configuration.
- Dependencies declared: `magento/module-graph-ql-cache`, `magento/module-backend`, `magento/module-config`.

## 2.1.0

- Replace predis/predis with `colinmollenhour/credis`, that supports `PhpRedis` module for high load.

## 1.5.0

- Replace predis/predis with `colinmollenhour/credis`, that supports `PhpRedis` module for high load.

## 2.0.2

- Fix argument value partial escaping

## 1.4.8

- Fix argument value partial escaping

## 2.0.1

- Fix float parameter handling

## 1.4.7

- Fix float parameter handling

## 2.0.0

- Magento 2.3.2 adoption
- Fixes

## 1.4.3

- verbose error messages for Varnish flush requests

## 1.3.0

- Event observer for`bin/magento cache:flush` to trigger persisted query varnish storage flush (happens also on setup:upgrade)
- `bin/magento scandipwa:pq:flush` - command for flushing query document (redis) storage (does not happen on setup:upgrade)
- Removed: `persisted_query` cache is unlisted in CLI and Admin cache control menu
- File restructuring improving readability
- Updating README.md & CHANGELOG.md
- Update Magento module version to fit release tag
- Minor CS improvements

## 1.2.0

- flush option for persisted query redis
- cache control for PQ and PQ responses
- flushing logic for varnish and redis
- Removed: version tag in composer.json
- Update README.md
- improve code styling and comments

## 1.1.1

- `--pq-scheme` is not setting `tcp` by default.
- Move CHANGELOG.md to the root of the module
- README.md

## 1.1.0

- Added custom flags to `setup:config:set` CLI command
- Changed README.md

## 1.0.0

- Initial commit
- predis/predis as dependency
- persisted query support
- Magento 2 module registration: ScandiPWA_PersistedQuery
- `Plugin\PersistedQuery` registered for `Magento\GraphQl\Controller\GraphQl`
- `Plugin\PersistedQuery` registered for `ScandiPWA\GraphQl\Controller\GraphQl`
