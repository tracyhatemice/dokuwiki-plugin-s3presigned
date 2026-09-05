# Design: Public Signing API for the s3presigned Helper

Date: 2026-09-05
Status: Approved

## Goal

Let other DokuWiki plugins sign S3 and CloudFront URLs by calling this plugin's
helper, instead of the signing logic staying locked inside private methods of
this plugin's own syntax and action components.

## Problem

Signing lives in three private methods that no other plugin can reach:

- `syntax_plugin_s3presigned::generatePresignedUrl()` — AWS Signature V4
- `syntax_plugin_s3presigned_cloudfront::generateSignedUrl()` — CloudFront RSA-SHA1
- `action_plugin_s3presigned::handleCookies()` — CloudFront signed cookies

`loadPrivateKey()` and `urlSafeBase64()` exist in two copies, in `action.php` and
`syntax/cloudfront.php`. The helper itself contains only parsing and rendering.

## Public API

Consumers load the helper the standard way and call it:

```php
$s3 = plugin_load('helper', 's3presigned');
if (!$s3) return; // plugin not installed or disabled

$url = $s3->signS3Url('my-bucket', 'docs/report.pdf');
```

### Methods

```php
signS3Url(string $bucket, string $objectKey, array $opts = []): string
signCloudFrontUrl(string $domain, string $path, array $opts = []): string
cloudFrontUrl(string $domain, string $path): string
getCloudFrontCookies(string $domain, string $resource, array $opts = []): array
sendCloudFrontCookies(string $domain, string $resource, array $opts = []): bool
```

`cloudFrontUrl()` builds the unsigned, path-encoded URL. Cookie-based consumers
need it: the cookies authorise the request, so the URL itself carries no
signature. It uses no credentials and therefore never throws for missing
config.

`getCloudFrontCookies()` returns the cookie material without sending it:

```php
[
    'cookies' => [
        'CloudFront-Policy'      => '<url-safe base64>',
        'CloudFront-Signature'   => '<url-safe base64>',
        'CloudFront-Key-Pair-Id' => '<key pair id>',
    ],
    'options' => [
        'expires'  => 1772000000,  // absolute epoch seconds
        'path'     => '/',
        'domain'   => '.example.com',  // omitted entirely when not configured
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ],
]
```

`sendCloudFrontCookies()` passes that straight to `setcookie()`. It returns
`false` without sending when `headers_sent()`, `true` when all three cookies
were issued.

### Options and config fallback

Every option falls back to the plugin's admin config when absent.

| Option key         | Methods            | Config fallback        |
|--------------------|--------------------|------------------------|
| `region`           | S3                 | `aws_region`           |
| `access_key`       | S3                 | `aws_access_key`       |
| `secret_key`       | S3                 | `aws_secret_key`       |
| `expires`          | S3                 | `url_expiration`, else 3600 |
| `expires`          | CloudFront, cookies | `cf_url_expiration`, else 3600 |
| `key_pair_id`      | CloudFront         | `cf_key_pair_id`       |
| `private_key`      | CloudFront         | `cf_private_key_pem`   |
| `private_key_file` | CloudFront         | `cf_private_key_file`  |
| `cookie_domain`    | cookies            | `cf_cookie_domain`     |
| `cookie_path`      | cookies            | `cf_cookie_path`, else `/` |
| `secure`           | cookies            | `true`                 |
| `httponly`         | cookies            | `true`                 |
| `samesite`         | cookies            | `'None'`               |

`expires` is always a duration in seconds from now. Precedence for the
CloudFront key matches today's behaviour: `private_key_file` wins over
`private_key`, and a PEM string may use literal `\n` escapes.

### Error contract

All failures throw `\RuntimeException`. It extends `\Exception`, so the existing
`catch (Exception $e)` blocks in the syntax components keep working with no
change.

An unrecognised key in `$opts` throws. Silently ignoring a typo would fall back
to config and sign with the wrong credentials, which fails at the CDN rather
than at the call site.

These existing messages are preserved verbatim, because they are rendered into
the page and users may recognise them:

- `AWS credentials not configured`
- `CloudFront Key Pair ID not configured`
- `OpenSSL extension is required for CloudFront signed URLs`
- `CloudFront private key file not found: <path>`
- `CloudFront private key not configured (set file path or paste PEM)`
- `Invalid RSA private key: <openssl error>`
- `RSA signing failed: <openssl error>`

### Discoverability

`helper.php` implements `getMethods()`, returning a descriptor per public method.
DokuWiki's core `info` plugin renders these
(`lib/plugins/info/syntax.php:153`), so the API is documented in the wiki itself.

## Internal structure

```
helper.php             public facade: config resolution, option merge, getMethods()
S3Signer.php           dokuwiki\plugin\s3presigned\S3Signer
CloudFrontSigner.php   dokuwiki\plugin\s3presigned\CloudFrontSigner
```

Namespaced plugin classes autoload from the plugin root
(`inc/load.php:164` maps `dokuwiki\plugin\s3presigned\S3Signer` to
`lib/plugins/s3presigned/S3Signer.php`). No `require` calls are needed.

### S3Signer

```php
namespace dokuwiki\plugin\s3presigned;

class S3Signer
{
    public function __construct(string $region, string $accessKey, string $secretKey);
    public function presign(string $bucket, string $objectKey, int $expires, ?int $now = null): string;
}
```

The constructor throws when any credential is empty. `presign()` takes a
*duration*, because SigV4's `X-Amz-Expires` is a duration. `$now` defaults to
`time()` and exists so tests can pin the timestamp.

Holds no DokuWiki dependency, so it is unit-testable without a wiki bootstrap.

### CloudFrontSigner

```php
namespace dokuwiki\plugin\s3presigned;

class CloudFrontSigner
{
    public function __construct(string $keyPairId, string $privateKeyPem);
    public function signUrl(string $domain, string $path, int $expiresAt): string;
    public function cookiePolicy(string $domain, string $resource, int $expiresAt): array;
    public static function url(string $domain, string $path): string;
    public static function encodePath(string $path): string;
}
```

`signUrl()` and `cookiePolicy()` take an *absolute* epoch, because CloudFront's
`Expires` and `DateLessThan` are absolute. The helper converts duration to
absolute once, so a URL and its cookies share one expiry.

`encodePath()` rawurlencodes each slash-separated segment. `cookiePolicy()`
deliberately does **not** encode, so wildcard resources like `videos/*` survive
(matching today's behaviour).

The constructor throws when `openssl_sign()` is unavailable, when the key pair
ID is empty, or when the PEM fails to parse.

### Helper facade

The helper resolves config, merges options, and delegates. Private methods
`resolveS3Options()` and `resolveCloudFrontOptions()` do the merge and reject
unknown keys. Reading the CloudFront private key from file or config stays in
the helper, since it is config resolution, not cryptography; the signer receives
a PEM string.

Existing `parseParams()`, `defaultParams()`, `isImage()`, `parseAlignment()`,
`parseContent()`, `renderOutput()`, `renderLink()` and `renderImage()` stay
unchanged. They are not part of the advertised API but keep working.

### Component rewiring

Behaviour is preserved exactly; only the call path changes.

- **syntax.php** — `render()` calls `$helper->signS3Url()`. Delete
  `generatePresignedUrl()` (~78 lines).
- **syntax/cloudfront.php** — `render()` calls `$helper->signCloudFrontUrl()`,
  and the `?cookies` branch calls `$helper->cloudFrontUrl()`. Delete
  `generateSignedUrl()`, `loadPrivateKey()`, `rsaSign()`, `urlSafeBase64()`
  (~76 lines). The metadata branch storing `plugin_s3presigned_cf_cookies` is
  untouched.
- **action.php** — `handleCookies()` calls `$helper->sendCloudFrontCookies()`
  per deduplicated domain. Delete `loadPrivateKey()` and `urlSafeBase64()`.
  The `headers_sent()` guard, metadata lookup and domain deduplication stay.

## Docker development environment

All development, testing and verification runs in Docker. Nothing is installed
on the host.

### Files

- `docker/Dockerfile` — `php:8.3-apache`, plus `mbstring`, `intl`, `gd`, `bz2`,
  `pdo_sqlite` (the extension set DokuWiki's own CI installs) and `git`.
- `docker/entrypoint-wiki.sh` — seeds and auto-installs the wiki.
- `docker-compose.yml` — the two services below.
- `.gitignore` — ignore local Docker state.

### Service: test

Runs the plugin's PHPUnit group against the mounted fork.

```
docker compose run --rm test                       # whole plugin group
docker compose run --rm test --filter S3Signer     # one test class
```

Mounts `${DOKUWIKI_PATH:-../../dokuwiki}` at `/dokuwiki` and this repo at
`/dokuwiki/lib/plugins/s3presigned`. Working directory `/dokuwiki/_test`.

The service sets `entrypoint: ["vendor/bin/phpunit", "--group", "plugin_s3presigned"]`
with no `command`, so arguments given to `docker compose run` are appended to the
phpunit invocation rather than replacing it.

The harness creates its own throwaway tree at `/tmp/dwtests-<microtime>` with
its own `conf/` and `data/` (`_test/bootstrap.php:26-28`), so the fork's own
directories are never written to.

The service runs as the host UID/GID so any stray file is not root-owned.

### Service: wiki

A browsable wiki at `http://localhost:8080` for manual verification.

The fork is a pristine checkout with no `conf/local.php` and an empty wiki, so
mounting it directly would require running the installer by hand and would
write into the user's working tree. Instead the entrypoint, on first start
only, copies the source into a named volume (excluding `.git` and
`_test/vendor`) and writes `conf/local.php`, `conf/users.auth.php` and
`conf/acl.auth.php` for a ready-to-use `admin` account.

This repo is bind-mounted over the copy at `lib/plugins/s3presigned`, so plugin
edits are live while wiki state stays throwaway and the fork stays untouched.

### Fork location

`DOKUWIKI_PATH` overrides the default relative path `../../dokuwiki`, for
checkouts laid out differently.

## Testing

Test-driven: each behaviour gets a failing test before its implementation.
Tests live in `_test/`, which the fork's `phpunit.xml` already picks up through
`../lib/plugins/*/_test/`. Every class carries `@group plugin_s3presigned` and
`@group plugins`.

### Differential testing against the current implementation

This is a refactor of working, deployed code, so the primary obligation is
proving behaviour did not change. Amazon's official SigV4 test suite covers
`Authorization`-header signing rather than the query-string presigned form, and
the commonly cited presigned example targets the legacy `bucket.s3.amazonaws.com`
host instead of the regional host this plugin builds, so it cannot serve as a
known-answer vector here without the endpoint override that is out of scope.

Instead each signer test carries a **frozen oracle**: the body of the current
private method, copied verbatim, with `time()` replaced by an injected
timestamp. The test asserts the new signer's output is byte-identical to the
oracle's across a table of inputs. The oracle is marked as frozen and must never
be "fixed" to match a change in the signer — a divergence means the refactor
altered behaviour.

### _test/S3SignerTest.php

Differential assertions against the frozen copy of `generatePresignedUrl()` over
a table covering plain keys, keys with spaces, unicode keys, nested paths, and
varied regions and expiries. Structural assertions on top: path segments are
encoded individually while separating slashes are not; canonical query
parameters are sorted; the payload hash is `UNSIGNED-PAYLOAD`; `X-Amz-Signature`
is 64 hex characters. Empty credentials throw.

### _test/CloudFrontSignerTest.php

Generates a throwaway 2048-bit RSA key in `setUp()` via `openssl_pkey_new()`, so
no key material is committed. Differential assertions against frozen copies of
`generateSignedUrl()` and the policy construction in `handleCookies()`. Because
RSA-SHA1 is deterministic for a fixed key and message, these comparisons are
byte-exact. Correctness assertions on top: `openssl_verify()` accepts the
produced signature against the canned policy; encoded output contains none of
`+`, `/`, `=`; the canned policy JSON carries the exact resource and expiry;
cookie policies keep `*` unencoded while URLs encode path segments; a malformed
PEM throws.

### _test/HelperApiTest.php

Extends `DokuWikiTest` with `protected $pluginsEnabled = ['s3presigned']`.
Covers: each method reachable through `plugin_load('helper', 's3presigned')`;
config supplies defaults; per-call options override config; an unknown option
key throws; `expires` produces the expected absolute expiry; a URL and its
cookies issued together share one expiry; `getMethods()` returns a descriptor
for every public API method.

### _test/RenderRegressionTest.php

Pins the rendered HTML for the `s3://` and `cf://` syntax, including the
`?cookies` branch, proving the rewiring is behaviour-neutral. Credentials come
from test config, and output is asserted against the signature-independent parts
of the markup plus the presence of the signature parameters.

## Documentation

`README.md` gains a "Calling from another plugin" section: loading the helper,
the five methods, the options table, the error contract, and a worked example
for both a URL and the cookie flow.

## Out of scope

Deliberately excluded; each would change behaviour rather than structure.

- **Cookie resource encoding mismatch.** In `?cookies` mode the rendered URL is
  rawurlencoded (`syntax/cloudfront.php:72`) while the cookie policy resource is
  not (`action.php:52`). They can disagree for paths containing special
  characters. Behaviour is preserved as-is.
- **The `?direct` parameter.** Parsed at `helper.php:29` but read by no renderer.
  Left in place.
- **Custom S3-compatible endpoints** (MinIO, Cloudflare R2). Would need a new
  option key and config setting.

## Backward compatibility

No breaking change. Wiki syntax, rendered output, config keys and error messages
are unchanged. The deleted methods were all private. `\RuntimeException` is
caught by the existing `catch (Exception $e)` handlers.
