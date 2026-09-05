# Public Helper Signing API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move S3 and CloudFront signing out of private methods on this plugin's syntax and action components into a documented helper API that any other DokuWiki plugin can call.

**Architecture:** Two dependency-free signer classes (`S3Signer`, `CloudFrontSigner`) hold the cryptography. `helper.php` becomes a public facade that resolves admin config, merges per-call option overrides, and delegates. The three existing components shrink to thin callers. All work happens in Docker.

**Tech Stack:** PHP 8.3, DokuWiki plugin API, PHPUnit 9 (via DokuWiki's `_test` harness), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-05-helper-signing-api-design.md`

## Global Constraints

- **Never run PHP, composer or phpunit on the host.** Everything goes through `docker compose`. The host has no PHP toolchain and must not gain one.
- **Behaviour must not change.** Wiki syntax, rendered HTML, config keys and error message strings stay identical. The only user-visible addition is new helper methods.
- **These error message strings are preserved verbatim** — they render into wiki pages:
  - `AWS credentials not configured`
  - `CloudFront Key Pair ID not configured`
  - `OpenSSL extension is required for CloudFront signed URLs`
  - `CloudFront private key file not found: <path>`
  - `CloudFront private key not configured (set file path or paste PEM)`
  - `Invalid RSA private key: <openssl error>`
  - `RSA signing failed: <openssl error>`
- **All exceptions are `\RuntimeException`.** It extends `\Exception`, so existing `catch (Exception $e)` blocks keep working.
- **Frozen oracles are never edited.** Test files carry verbatim copies of the pre-refactor algorithms. If a differential test fails, the *signer* is wrong, not the oracle.
- **No key material in the repo.** RSA keys used by tests are generated at runtime in `setUp()`.
- **Every test class carries** `@group plugin_s3presigned` and `@group plugins`.
- **Test namespace:** `dokuwiki\plugin\s3presigned\test` (matches the sibling `logger` plugin).
- **Plugin classes autoload** as `dokuwiki\plugin\s3presigned\<Class>` from `<plugin root>/<Class>.php`. No `require` statements.
- **PHP version floor is 8.2** (DokuWiki's `composer.json`), so typed properties and `??` are fine; do not use 8.3-only syntax.

---

### Task 1: Docker development environment

Nothing else in this plan can be tested until this exists. The deliverable is a
working `docker compose run --rm test` plus a browsable wiki, proven by a real
test that DokuWiki discovers this plugin.

**Files:**
- Create: `docker/Dockerfile`
- Create: `docker/entrypoint-wiki.sh`
- Create: `docker-compose.yml`
- Create: `.gitignore`
- Test: `_test/PluginDiscoveryTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: the command `docker compose run --rm test` runs the plugin's PHPUnit
  group; extra arguments append to the phpunit invocation. Every later task uses
  this command and no other.

**Background the implementer needs:**

The DokuWiki fork lives *outside* this repo at `../../dokuwiki` by default,
overridable with the `DOKUWIKI_PATH` environment variable. This repo is mounted
into it at `lib/plugins/s3presigned`, which is how DokuWiki finds the plugin.

DokuWiki's test bootstrap builds a throwaway wiki at
`/tmp/dwtests-<microtime>` with its own `conf/` and `data/`
(`_test/bootstrap.php:26-28`), so tests never write into the fork. It also
disables every non-default plugin, which is why test classes must declare
`protected $pluginsEnabled = ['s3presigned']`.

The fork is a pristine checkout with no `conf/local.php` and an empty wiki, so
the `wiki` service seeds its own copy rather than installing into the user's
working tree.

- [ ] **Step 1: Write the Dockerfile**

Create `docker/Dockerfile`:

```dockerfile
FROM php:8.3-apache

# The extension set DokuWiki's own CI installs (.github/workflows/testLinux.yml)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libbz2-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mbstring intl gd bz2 pdo_sqlite \
    && a2enmod rewrite

COPY entrypoint-wiki.sh /usr/local/bin/entrypoint-wiki
RUN chmod +x /usr/local/bin/entrypoint-wiki
```

- [ ] **Step 2: Write the wiki entrypoint**

Create `docker/entrypoint-wiki.sh`. On first start it copies the read-only
source into the container-local volume and installs a usable wiki; on later
starts it leaves the existing wiki alone.

```bash
#!/bin/sh
set -eu

SRC=/dokuwiki-src
DST=/var/www/html

if [ ! -f "$DST/doku.php" ]; then
    echo "Seeding DokuWiki from $SRC ..."
    # .git and the 76MB test vendor tree are not needed to serve the wiki
    tar -C "$SRC" --exclude=./.git --exclude=./_test/vendor -cf - . | tar -C "$DST" -xf -
fi

if [ ! -f "$DST/conf/local.php" ]; then
    echo "Installing wiki ..."
    cat > "$DST/conf/local.php" <<'EOF'
<?php
$conf['title'] = 's3presigned dev';
$conf['lang'] = 'en';
$conf['license'] = 'cc-by-sa';
$conf['useacl'] = 1;
$conf['superuser'] = 'admin';
$conf['disableactions'] = 'register';
EOF
    # password is "admin"; this wiki is a throwaway dev container
    printf 'admin:%s:admin:admin@example.com:admin,user\n' \
        "$(php -r 'echo password_hash("admin", PASSWORD_DEFAULT);')" \
        > "$DST/conf/users.auth.php"
    printf '*  @ALL  8\n' > "$DST/conf/acl.auth.php"
    cp "$DST/conf/plugins.required.php" "$DST/conf/plugins.local.php" 2>/dev/null || true
    : > "$DST/conf/plugins.local.php"
fi

chown -R www-data:www-data "$DST/conf" "$DST/data"

exec apache2-foreground
```

- [ ] **Step 3: Write the compose file**

Create `docker-compose.yml`:

```yaml
services:
  test:
    build: ./docker
    working_dir: /dokuwiki/_test
    # run as the host user so no root-owned files land in the fork
    user: "${UID:-1000}:${GID:-1000}"
    # entrypoint (not command) so `docker compose run --rm test --filter X`
    # appends to phpunit instead of replacing it
    entrypoint:
      - vendor/bin/phpunit
      - --do-not-cache-result
      - --group
      - plugin_s3presigned
    volumes:
      - ${DOKUWIKI_PATH:-../../dokuwiki}:/dokuwiki
      - .:/dokuwiki/lib/plugins/s3presigned

  wiki:
    build: ./docker
    entrypoint: ["/usr/local/bin/entrypoint-wiki"]
    ports:
      - "8080:80"
    volumes:
      - ${DOKUWIKI_PATH:-../../dokuwiki}:/dokuwiki-src:ro
      - wiki-root:/var/www/html
      - .:/var/www/html/lib/plugins/s3presigned

volumes:
  wiki-root:
```

- [ ] **Step 4: Write the .gitignore**

Create `.gitignore`:

```
# Docker / local dev state
.env

# PHP tooling
.phpunit.result.cache
vendor/
```

- [ ] **Step 5: Write the failing test**

Create `_test/PluginDiscoveryTest.php`. This proves the harness runs, the mount
lands in the right place, and the plugin is discoverable under its own name.

```php
<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;

/**
 * Proves DokuWiki discovers this plugin under its own name inside the Docker
 * test environment. If this fails, the mount path or the plugin name is wrong
 * and every other test in this suite is meaningless.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class PluginDiscoveryTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    public function testHelperIsLoadable()
    {
        $helper = plugin_load('helper', 's3presigned');

        $this->assertInstanceOf(\helper_plugin_s3presigned::class, $helper);
    }

    public function testSyntaxComponentsAreLoadable()
    {
        $this->assertInstanceOf(
            \syntax_plugin_s3presigned::class,
            plugin_load('syntax', 's3presigned')
        );
        $this->assertInstanceOf(
            \syntax_plugin_s3presigned_cloudfront::class,
            plugin_load('syntax', 's3presigned_cloudfront')
        );
    }

    public function testActionComponentIsLoadable()
    {
        $this->assertInstanceOf(
            \action_plugin_s3presigned::class,
            plugin_load('action', 's3presigned')
        );
    }
}
```

- [ ] **Step 6: Build the image and run the test**

Run:

```bash
docker compose build test
docker compose run --rm test
```

Expected: the image builds, then 3 tests pass with 4 assertions. If the run
reports "No tests executed", the repo is not mounted at
`/dokuwiki/lib/plugins/s3presigned` — check `DOKUWIKI_PATH`.

- [ ] **Step 7: Verify the wiki service serves a page**

Run:

```bash
docker compose up -d wiki
sleep 15
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8080/doku.php
docker compose down
```

Expected: `200`. A `500` usually means a missing PHP extension — read
`docker compose logs wiki`.

- [ ] **Step 8: Verify the fork was not dirtied**

Run:

```bash
git -C "${DOKUWIKI_PATH:-../../dokuwiki}" status --porcelain
```

Expected: empty output. The test harness writes only to `/tmp` inside the
container and the wiki service works from a copy, so the fork must be clean.

- [ ] **Step 9: Commit**

```bash
git add docker docker-compose.yml .gitignore _test/PluginDiscoveryTest.php
git commit -m "test: add Docker development environment

A test service running DokuWiki's PHPUnit harness against the plugin and
a browsable wiki service for manual checks, both mounting the fork at
DOKUWIKI_PATH. Nothing runs on the host."
```

---

### Task 2: S3Signer

Extracts AWS Signature V4 presigning into a class with no DokuWiki dependency.
The class is written first as a pure unit so its correctness can be pinned
before anything starts depending on it.

**Files:**
- Create: `S3Signer.php`
- Test: `_test/S3SignerTest.php`

**Interfaces:**
- Consumes: the Docker test command from Task 1.
- Produces:
  - `dokuwiki\plugin\s3presigned\S3Signer::__construct(string $region, string $accessKey, string $secretKey)` — throws `\RuntimeException('AWS credentials not configured')` if any argument is an empty string.
  - `S3Signer::presign(string $bucket, string $objectKey, int $expires, ?int $now = null): string` — `$expires` is a **duration in seconds**, because SigV4's `X-Amz-Expires` is a duration. `$now` defaults to `time()`.
  - `S3Signer::encodePath(string $path): string` — static; rawurlencodes each slash-separated segment.

**Background the implementer needs:**

The algorithm already exists at `syntax.php:69-146` as the private method
`generatePresignedUrl()`. This task moves it without altering a byte of its
output. The test below carries a frozen copy of that method as an oracle, so the
move is proven rather than assumed.

- [ ] **Step 1: Write the failing test**

Create `_test/S3SignerTest.php`:

```php
<?php

namespace dokuwiki\plugin\s3presigned\test;

use dokuwiki\plugin\s3presigned\S3Signer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S3Signer holds no DokuWiki dependency, so this extends the plain PHPUnit
 * TestCase rather than DokuWikiTest. That the tests pass at all is itself
 * evidence the class stayed dependency-free.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class S3SignerTest extends TestCase
{
    protected const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    protected const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    /** pinned so every signature in this file is reproducible */
    protected const NOW = 1772000000;

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of syntax_plugin_s3presigned::generatePresignedUrl() as it
     * stood before the refactor, with the config lookups turned into arguments
     * and time() replaced by $timestamp.
     *
     * NEVER edit this to make a test pass. A divergence from S3Signer means the
     * refactor changed behaviour, which is precisely what this file exists to
     * detect.
     */
    protected function legacyPresign($bucket, $objectKey, $region, $accessKey, $secretKey, $expiration, $timestamp)
    {
        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        $date = gmdate('Ymd', $timestamp);

        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$region}/s3/aws4_request";
        $credential = "{$accessKey}/{$credentialScope}";

        $encodedObjectKey = implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        $canonicalUri = '/' . ltrim($encodedObjectKey, '/');

        $queryParams = array(
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string)$expiration,
            'X-Amz-SignedHeaders' => 'host'
        );

        ksort($queryParams);
        $canonicalQueryString = '';
        foreach ($queryParams as $key => $value) {
            if ($canonicalQueryString !== '') {
                $canonicalQueryString .= '&';
            }
            $canonicalQueryString .= rawurlencode($key) . '=' . rawurlencode($value);
        }

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';

        $canonicalRequest = implode("\n", array(
            'GET',
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD'
        ));

        $stringToSign = implode("\n", array(
            $algorithm,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest)
        ));

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    public function provideObjects()
    {
        return [
            'plain key'      => ['my-bucket', 'docs/report.pdf', 'us-east-1', 3600],
            'key at root'    => ['my-bucket', 'report.pdf', 'us-east-1', 3600],
            'leading slash'  => ['my-bucket', '/docs/report.pdf', 'us-east-1', 3600],
            'spaces'         => ['my-bucket', 'docs/my report (final).pdf', 'us-west-2', 3600],
            'unicode'        => ['my-bucket', "images/\u{30d5}\u{30a9}\u{30c8}.jpg", 'eu-central-1', 900],
            'ampersand'      => ['my-bucket', 'docs/a&b=c.txt', 'us-east-1', 60],
            'deep path'      => ['other.bucket', 'a/b/c/d/e.txt', 'ap-southeast-1', 604800],
        ];
    }

    /**
     * @dataProvider provideObjects
     */
    public function testMatchesTheLegacyImplementation($bucket, $key, $region, $expires)
    {
        $signer = new S3Signer($region, self::ACCESS_KEY, self::SECRET_KEY);

        $this->assertSame(
            $this->legacyPresign($bucket, $key, $region, self::ACCESS_KEY, self::SECRET_KEY, $expires, self::NOW),
            $signer->presign($bucket, $key, $expires, self::NOW)
        );
    }

    public function testEncodesSegmentsButNotSeparatingSlashes()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'my docs/a b.pdf', 3600, self::NOW);

        $this->assertStringContainsString('/my%20docs/a%20b.pdf?', $url);
        // only the path may be checked for %2F: X-Amz-Credential legitimately
        // contains an encoded slash inside the query string
        $this->assertStringNotContainsString('%2F', parse_url($url, PHP_URL_PATH));
    }

    public function testBuildsRegionalVirtualHostedUrl()
    {
        $signer = new S3Signer('eu-west-3', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        $this->assertStringStartsWith('https://my-bucket.s3.eu-west-3.amazonaws.com/a.txt?', $url);
    }

    public function testQueryParametersAreSorted()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $signed = array_keys($query);
        // X-Amz-Signature is appended after the canonical string, so drop it
        array_pop($signed);

        $sorted = $signed;
        sort($sorted);
        $this->assertSame($sorted, $signed);
    }

    public function testExpiresIsADurationNotATimestamp()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 900, self::NOW);

        $this->assertStringContainsString('X-Amz-Expires=900', $url);
    }

    public function testSignatureIsSha256Hex()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $url = $signer->presign('my-bucket', 'a.txt', 3600, self::NOW);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
    }

    public function testDefaultsToCurrentTime()
    {
        $signer = new S3Signer('us-east-1', self::ACCESS_KEY, self::SECRET_KEY);
        $expected = $this->legacyPresign(
            'my-bucket', 'a.txt', 'us-east-1', self::ACCESS_KEY, self::SECRET_KEY, 3600, time()
        );

        $this->assertSame($expected, $signer->presign('my-bucket', 'a.txt', 3600));
    }

    public function provideMissingCredentials()
    {
        return [
            'no region'     => ['', self::ACCESS_KEY, self::SECRET_KEY],
            'no access key' => ['us-east-1', '', self::SECRET_KEY],
            'no secret key' => ['us-east-1', self::ACCESS_KEY, ''],
        ];
    }

    /**
     * @dataProvider provideMissingCredentials
     */
    public function testEmptyCredentialsThrow($region, $accessKey, $secretKey)
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWS credentials not configured');

        new S3Signer($region, $accessKey, $secretKey);
    }
}
```

Note on `testDefaultsToCurrentTime`: it recomputes the oracle with `time()`, so
it fails only if the two calls straddle a second boundary. That is rare enough to
accept and, if it ever flakes, it flakes loudly rather than silently passing.

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm test --filter S3SignerTest`

Expected: FAIL — `Class "dokuwiki\plugin\s3presigned\S3Signer" not found`.

- [ ] **Step 3: Write the implementation**

Create `S3Signer.php` at the plugin root:

```php
<?php
/**
 * DokuWiki Plugin s3presigned (S3 signer)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

namespace dokuwiki\plugin\s3presigned;

use RuntimeException;

/**
 * Builds AWS Signature Version 4 presigned GET URLs for S3 objects.
 *
 * Deliberately free of DokuWiki dependencies: config resolution belongs to
 * helper_plugin_s3presigned, so this class can be reasoned about and tested
 * on its own.
 */
class S3Signer
{
    protected $region;
    protected $accessKey;
    protected $secretKey;

    public function __construct(string $region, string $accessKey, string $secretKey)
    {
        if ($region === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException('AWS credentials not configured');
        }

        $this->region = $region;
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
    }

    /**
     * Presign a GET request for an object.
     *
     * @param int      $expires lifetime in seconds; X-Amz-Expires is a duration, not a deadline
     * @param int|null $now     unix timestamp to sign at, for reproducible tests
     */
    public function presign(string $bucket, string $objectKey, int $expires, ?int $now = null): string
    {
        if ($now === null) $now = time();

        $datetime = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);

        $host = "{$bucket}.s3.{$this->region}.amazonaws.com";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$date}/{$this->region}/s3/aws4_request";
        $credential = "{$this->accessKey}/{$credentialScope}";

        $canonicalUri = '/' . ltrim(self::encodePath($objectKey), '/');

        $queryParams = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $datetime,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);

        $canonicalQueryString = '';
        foreach ($queryParams as $key => $value) {
            if ($canonicalQueryString !== '') $canonicalQueryString .= '&';
            $canonicalQueryString .= rawurlencode($key) . '=' . rawurlencode($value);
        }

        $canonicalRequest = implode("\n", [
            'GET',
            $canonicalUri,
            $canonicalQueryString,
            "host:{$host}\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            $algorithm,
            $datetime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return "https://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    /**
     * rawurlencode each path segment, leaving the separating slashes intact
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Derive the date/region/service scoped signing key
     */
    protected function signingKey(string $date): string
    {
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm test --filter S3SignerTest`

Expected: PASS — 16 tests (7 from the object provider, 3 from the credentials
provider, 6 standalone).

- [ ] **Step 5: Run the whole group to check nothing regressed**

Run: `docker compose run --rm test`

Expected: PASS, including Task 1's discovery tests.

- [ ] **Step 6: Commit**

```bash
git add S3Signer.php _test/S3SignerTest.php
git commit -m "feat: extract S3 presigning into S3Signer

A dependency-free class carrying the AWS Signature V4 logic, verified
byte-for-byte against a frozen copy of the syntax component's private
method so the extraction is provably behaviour-neutral."
```

---

### Task 3: CloudFrontSigner

Extracts CloudFront RSA-SHA1 URL signing and cookie policy construction into one
class. This is where the current duplication dies: `loadPrivateKey()` and
`urlSafeBase64()` exist today in both `action.php` and `syntax/cloudfront.php`.

**Files:**
- Create: `CloudFrontSigner.php`
- Test: `_test/CloudFrontSignerTest.php`

**Interfaces:**
- Consumes: the Docker test command from Task 1.
- Produces:
  - `dokuwiki\plugin\s3presigned\CloudFrontSigner::__construct(string $keyPairId, string $privateKeyPem)`
  - `CloudFrontSigner::signUrl(string $domain, string $path, int $expiresAt): string` — `$expiresAt` is an **absolute epoch**, because CloudFront's `Expires` is absolute.
  - `CloudFrontSigner::cookiePolicy(string $domain, string $resource, int $expiresAt): array` — returns exactly the three keys `CloudFront-Policy`, `CloudFront-Signature`, `CloudFront-Key-Pair-Id`.
  - `CloudFrontSigner::url(string $domain, string $path): string` — static, unsigned, path-encoded.
  - `CloudFrontSigner::encodePath(string $path): string` — static.
  - `CloudFrontSigner::urlSafeBase64(string $data): string` — static.

**Background the implementer needs:**

Two behaviours look inconsistent but are both deliberate and must be preserved:

1. `signUrl()` **encodes** the path, because a canned policy signs one exact URL.
2. `cookiePolicy()` does **not** encode the resource, because a custom policy
   takes wildcards such as `videos/*` and encoding would turn `*` into `%2A`.

CloudFront's URL-safe base64 is not RFC 4648: it maps `+` to `-`, `/` to `~`,
and `=` to `_`. That is what `strtr($data, '+/=', '-~_')` does.

RSA-SHA1 with PKCS#1 v1.5 padding is deterministic for a fixed key and message,
which is why byte-exact comparison against the frozen oracle works here.

The absolute-vs-duration split between this class and `S3Signer` mirrors the two
AWS protocols and is intentional; the helper in Task 4 hides it from callers.

- [ ] **Step 1: Write the failing test**

Create `_test/CloudFrontSignerTest.php`:

```php
<?php

namespace dokuwiki\plugin\s3presigned\test;

use dokuwiki\plugin\s3presigned\CloudFrontSigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @group plugin_s3presigned
 * @group plugins
 */
class CloudFrontSignerTest extends TestCase
{
    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';
    protected const EXPIRES_AT = 1772003600;

    /** @var string PEM private key, generated per test so none is committed */
    protected $pem;

    /** @var string matching public key, for verifying signatures */
    protected $publicKey;

    public function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($resource, 'could not generate an RSA key');

        openssl_pkey_export($resource, $this->pem);
        $this->publicKey = openssl_pkey_get_details($resource)['key'];
    }

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of syntax_plugin_s3presigned_cloudfront::generateSignedUrl()
     * as it stood before the refactor, with config lookups turned into arguments
     * and the expiry passed in rather than computed.
     *
     * NEVER edit this to make a test pass.
     */
    protected function legacySignUrl($domain, $objectPath, $keyPairId, $pem, $expiration)
    {
        $privateKey = openssl_pkey_get_private($pem);

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));
        $url = "https://{$domain}/" . ltrim($encodedPath, '/');

        $policy = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expiration . '}}}]}';

        $signature = '';
        openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1);

        return $url
            . (strpos($url, '?') !== false ? '&' : '?')
            . 'Expires=' . $expiration
            . '&Signature=' . strtr(base64_encode($signature), '+/=', '-~_')
            . '&Key-Pair-Id=' . $keyPairId;
    }

    /**
     * FROZEN ORACLE.
     *
     * A verbatim copy of the policy construction inside
     * action_plugin_s3presigned::handleCookies() as it stood before the refactor.
     *
     * NEVER edit this to make a test pass.
     */
    protected function legacyCookiePolicy($domain, $path, $keyPairId, $pem, $expiration)
    {
        $privateKey = openssl_pkey_get_private($pem);

        $resourceUrl = "https://{$domain}/{$path}";

        $policy = json_encode(array(
            'Statement' => array(array(
                'Resource' => $resourceUrl,
                'Condition' => array(
                    'DateLessThan' => array('AWS:EpochTime' => $expiration)
                )
            ))
        ));

        $signature = '';
        openssl_sign($policy, $signature, $privateKey, OPENSSL_ALGO_SHA1);

        return array(
            'CloudFront-Policy' => strtr(base64_encode($policy), '+/=', '-~_'),
            'CloudFront-Signature' => strtr(base64_encode($signature), '+/=', '-~_'),
            'CloudFront-Key-Pair-Id' => $keyPairId,
        );
    }

    protected function signer()
    {
        return new CloudFrontSigner(self::KEY_PAIR_ID, $this->pem);
    }

    /** decode CloudFront's non-standard url-safe base64 */
    protected function decode($value)
    {
        return base64_decode(strtr($value, '-~_', '+/='));
    }

    public function providePaths()
    {
        return [
            'plain'         => ['d111abcdef8.cloudfront.net', 'images/photo.jpg'],
            'at root'       => ['d111abcdef8.cloudfront.net', 'photo.jpg'],
            'leading slash' => ['d111abcdef8.cloudfront.net', '/images/photo.jpg'],
            'spaces'        => ['d111abcdef8.cloudfront.net', 'docs/my report.pdf'],
            'unicode'       => ['cdn.example.com', "images/\u{30d5}\u{30a9}\u{30c8}.jpg"],
            'deep'          => ['cdn.example.com', 'a/b/c/d/e.mp4'],
        ];
    }

    /**
     * @dataProvider providePaths
     */
    public function testSignedUrlMatchesTheLegacyImplementation($domain, $path)
    {
        $this->assertSame(
            $this->legacySignUrl($domain, $path, self::KEY_PAIR_ID, $this->pem, self::EXPIRES_AT),
            $this->signer()->signUrl($domain, $path, self::EXPIRES_AT)
        );
    }

    public function provideResources()
    {
        return [
            'wildcard'  => ['d111abcdef8.cloudfront.net', 'videos/*'],
            'directory' => ['d111abcdef8.cloudfront.net', 'gallery/'],
            'exact'     => ['cdn.example.com', 'docs/report.pdf'],
        ];
    }

    /**
     * @dataProvider provideResources
     */
    public function testCookiePolicyMatchesTheLegacyImplementation($domain, $resource)
    {
        $this->assertSame(
            $this->legacyCookiePolicy($domain, $resource, self::KEY_PAIR_ID, $this->pem, self::EXPIRES_AT),
            $this->signer()->cookiePolicy($domain, $resource, self::EXPIRES_AT)
        );
    }

    public function testSignedUrlSignatureVerifiesAgainstThePublicKey()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'a/b.jpg', self::EXPIRES_AT);

        // the canned policy signs the bare URL, before the query string is appended
        $signedUrl = 'https://cdn.example.com/a/b.jpg';
        $policy = '{"Statement":[{"Resource":"' . $signedUrl . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . self::EXPIRES_AT . '}}}]}';

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            1,
            openssl_verify($policy, $this->decode($query['Signature']), $this->publicKey, OPENSSL_ALGO_SHA1)
        );
    }

    public function testCookieSignatureVerifiesAgainstThePublicKey()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);

        $this->assertSame(
            1,
            openssl_verify(
                $this->decode($cookies['CloudFront-Policy']),
                $this->decode($cookies['CloudFront-Signature']),
                $this->publicKey,
                OPENSSL_ALGO_SHA1
            )
        );
    }

    public function testEncodedValuesAvoidCharactersCloudFrontRejects()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);

        foreach (['CloudFront-Policy', 'CloudFront-Signature'] as $name) {
            $this->assertDoesNotMatchRegularExpression('~[+/=]~', $cookies[$name], $name);
        }
    }

    public function testCookiePolicyKeepsWildcardsUnencoded()
    {
        $cookies = $this->signer()->cookiePolicy('cdn.example.com', 'videos/*', self::EXPIRES_AT);
        $policy = json_decode($this->decode($cookies['CloudFront-Policy']), true);

        $this->assertSame('https://cdn.example.com/videos/*', $policy['Statement'][0]['Resource']);
        $this->assertSame(self::EXPIRES_AT, $policy['Statement'][0]['Condition']['DateLessThan']['AWS:EpochTime']);
    }

    public function testSignedUrlEncodesPathSegments()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'my docs/a b.pdf', self::EXPIRES_AT);

        $this->assertStringStartsWith('https://cdn.example.com/my%20docs/a%20b.pdf?', $url);
    }

    public function testSignedUrlCarriesExpiryAndKeyPairId()
    {
        $url = $this->signer()->signUrl('cdn.example.com', 'a.jpg', self::EXPIRES_AT);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame((string)self::EXPIRES_AT, $query['Expires']);
        $this->assertSame(self::KEY_PAIR_ID, $query['Key-Pair-Id']);
    }

    public function testUnsignedUrlHasNoQueryString()
    {
        $this->assertSame(
            'https://cdn.example.com/a/b%20c.jpg',
            CloudFrontSigner::url('cdn.example.com', 'a/b c.jpg')
        );
    }

    public function testEmptyKeyPairIdThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront Key Pair ID not configured');

        new CloudFrontSigner('', $this->pem);
    }

    public function testEmptyPemThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key not configured');

        new CloudFrontSigner(self::KEY_PAIR_ID, '');
    }

    public function testMalformedPemThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid RSA private key');

        new CloudFrontSigner(self::KEY_PAIR_ID, '-----BEGIN PRIVATE KEY----- nonsense -----END PRIVATE KEY-----');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm test --filter CloudFrontSignerTest`

Expected: FAIL — `Class "dokuwiki\plugin\s3presigned\CloudFrontSigner" not found`.

- [ ] **Step 3: Write the implementation**

Create `CloudFrontSigner.php` at the plugin root:

```php
<?php
/**
 * DokuWiki Plugin s3presigned (CloudFront signer)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  tracyhatemice
 */

namespace dokuwiki\plugin\s3presigned;

use RuntimeException;

/**
 * Signs CloudFront URLs and builds CloudFront signed-cookie policies.
 *
 * Deliberately free of DokuWiki dependencies. Reading the private key from a
 * file or from config is config resolution, so it belongs to
 * helper_plugin_s3presigned; this class receives a PEM string.
 */
class CloudFrontSigner
{
    protected $keyPairId;

    /** @var resource|\OpenSSLAsymmetricKey */
    protected $privateKey;

    public function __construct(string $keyPairId, string $privateKeyPem)
    {
        if (!function_exists('openssl_sign')) {
            throw new RuntimeException('OpenSSL extension is required for CloudFront signed URLs');
        }
        if ($keyPairId === '') {
            throw new RuntimeException('CloudFront Key Pair ID not configured');
        }
        if ($privateKeyPem === '') {
            throw new RuntimeException('CloudFront private key not configured (set file path or paste PEM)');
        }

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Invalid RSA private key: ' . openssl_error_string());
        }

        $this->keyPairId = $keyPairId;
        $this->privateKey = $key;
    }

    /**
     * Sign one exact URL with a canned policy.
     *
     * @param int $expiresAt absolute unix timestamp; CloudFront's Expires is a deadline
     */
    public function signUrl(string $domain, string $path, int $expiresAt): string
    {
        $url = self::url($domain, $path);

        $policy = '{"Statement":[{"Resource":"' . $url . '","Condition":{"DateLessThan":{"AWS:EpochTime":' . $expiresAt . '}}}]}';
        $signature = $this->sign($policy);

        return $url
            . (strpos($url, '?') !== false ? '&' : '?')
            . 'Expires=' . $expiresAt
            . '&Signature=' . self::urlSafeBase64($signature)
            . '&Key-Pair-Id=' . $this->keyPairId;
    }

    /**
     * Build the three signed cookies for a resource, which may be a wildcard.
     *
     * A custom policy is required for cookies, and the resource is deliberately
     * NOT url-encoded: encoding would turn a wildcard such as videos/* into
     * videos/%2A and it would match nothing.
     *
     * @param int $expiresAt absolute unix timestamp
     * @return array{CloudFront-Policy: string, CloudFront-Signature: string, CloudFront-Key-Pair-Id: string}
     */
    public function cookiePolicy(string $domain, string $resource, int $expiresAt): array
    {
        $resourceUrl = "https://{$domain}/{$resource}";

        $policy = json_encode([
            'Statement' => [[
                'Resource' => $resourceUrl,
                'Condition' => [
                    'DateLessThan' => ['AWS:EpochTime' => $expiresAt],
                ],
            ]],
        ]);

        $signature = $this->sign($policy);

        return [
            'CloudFront-Policy' => self::urlSafeBase64($policy),
            'CloudFront-Signature' => self::urlSafeBase64($signature),
            'CloudFront-Key-Pair-Id' => $this->keyPairId,
        ];
    }

    /**
     * The unsigned URL for an object. Cookie-based access needs this: the
     * cookies carry the authorisation, so the URL itself stays bare.
     */
    public static function url(string $domain, string $path): string
    {
        return "https://{$domain}/" . ltrim(self::encodePath($path), '/');
    }

    /**
     * rawurlencode each path segment, leaving the separating slashes intact
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * CloudFront's url-safe base64, which is not RFC 4648: + becomes -,
     * / becomes ~, and = becomes _.
     */
    public static function urlSafeBase64(string $data): string
    {
        return strtr(base64_encode($data), '+/=', '-~_');
    }

    protected function sign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('RSA signing failed: ' . openssl_error_string());
        }

        return $signature;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `docker compose run --rm test --filter CloudFrontSignerTest`

Expected: PASS — 19 tests (6 path cases, 3 resource cases, 10 standalone).

- [ ] **Step 5: Run the whole group**

Run: `docker compose run --rm test`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add CloudFrontSigner.php _test/CloudFrontSignerTest.php
git commit -m "feat: extract CloudFront signing into CloudFrontSigner

One class for signed URLs and cookie policies, verified against frozen
copies of both pre-refactor code paths. Replaces the loadPrivateKey and
urlSafeBase64 pair that was duplicated across action.php and
syntax/cloudfront.php."
```

---

### Task 4: Helper facade and public API documentation

Turns `helper.php` into the documented entry point other plugins call. This is
the task the whole plan exists for, so the README section ships with it.

**Files:**
- Modify: `helper.php` (add to the existing class; existing methods are untouched)
- Modify: `README.md` (add a "Calling from another plugin" section)
- Test: `_test/HelperApiTest.php`

**Interfaces:**
- Consumes: `S3Signer` and `CloudFrontSigner` from Tasks 2 and 3, with the exact signatures listed in those tasks.
- Produces, on `helper_plugin_s3presigned`:
  - `signS3Url(string $bucket, string $objectKey, array $opts = []): string`
  - `signCloudFrontUrl(string $domain, string $path, array $opts = []): string`
  - `cloudFrontUrl(string $domain, string $path): string`
  - `getCloudFrontCookies(string $domain, string $resource, array $opts = []): array`
  - `sendCloudFrontCookies(string $domain, string $resource, array $opts = []): bool`
  - `getMethods(): array`

  Task 5 calls the first five and nothing else.

**Background the implementer needs:**

`$this->getConf('x')` reads `$conf['plugin']['s3presigned']['x']`, falling back
to `conf/default.php`. `PluginTrait::loadConfig()` binds `$this->conf` **by
reference** to `$conf['plugin']['s3presigned']` and sets a `configloaded` flag
(`inc/Extension/PluginTrait.php:201-214`).

That interacts badly with testing. `plugin_load()` caches instances in the
**global `$DOKU_PLUGINS`** (`inc/Extension/PluginController.php`, `load()`), and
`DokuWikiTest::setUp()` does *not* reset that global — while it *does* execute
`$conf = array()`, destroying the array element the cached instance's
`$this->conf` referenced. A helper reused across tests therefore reads stale,
detached config while reporting `configloaded`.

The fix is one argument: `plugin_load('helper', 's3presigned', true)` forces a
fresh instance, which reloads config from the current `$conf`. Production code
must keep using the cached form; this only matters under test.

`helper.php` is in the global namespace, so `\RuntimeException` resolves without
a `use`, but the two signer classes need `use` statements.

The `getMethods()` format is dictated by the core `info` plugin, which reads
`$method['name']`, `$method['desc']`, `$method['params']` and
`$method['return']` unconditionally (`lib/plugins/info/syntax.php:163-183`).
All four keys must exist on every entry. `params` maps description to type;
`return` uses only its first pair.

Duration versus deadline: callers always pass `expires` as a **duration in
seconds**. The helper converts to an absolute epoch for CloudFront and passes
the duration straight through for S3. Callers never see the difference.

- [ ] **Step 1: Write the failing test**

Create `_test/HelperApiTest.php`:

```php
<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;
use RuntimeException;

/**
 * Exercises the public API other plugins call.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class HelperApiTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    protected const ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    protected const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';

    /** @var string generated per test, so no key material is committed */
    protected $pem;

    public function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $this->pem);

        // must be set before any helper instance loads its config: loadConfig()
        // binds $this->conf by reference to this array
        global $conf;
        $conf['plugin']['s3presigned'] = [
            'aws_region' => 'us-east-1',
            'aws_access_key' => self::ACCESS_KEY,
            'aws_secret_key' => self::SECRET_KEY,
            'url_expiration' => 3600,
            'cf_key_pair_id' => self::KEY_PAIR_ID,
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $this->pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];
    }

    /**
     * Always a FRESH instance. plugin_load() caches in the global $DOKU_PLUGINS,
     * which DokuWikiTest::setUp() never clears even though it replaces $conf,
     * so a reused helper would read stale, detached configuration.
     *
     * @return \helper_plugin_s3presigned
     */
    protected function helper()
    {
        return plugin_load('helper', 's3presigned', true);
    }

    public function testSignS3UrlUsesConfiguredCredentials()
    {
        $url = $this->helper()->signS3Url('my-bucket', 'docs/report.pdf');

        $this->assertStringStartsWith(
            'https://my-bucket.s3.us-east-1.amazonaws.com/docs/report.pdf?',
            $url
        );
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $query['X-Amz-Signature']);
        $this->assertStringStartsWith(self::ACCESS_KEY . '/', $query['X-Amz-Credential']);
        $this->assertSame('3600', $query['X-Amz-Expires']);
    }

    public function testSignS3UrlOptionsOverrideConfig()
    {
        $url = $this->helper()->signS3Url('other-bucket', 'a.txt', [
            'region' => 'ap-southeast-1',
            'access_key' => 'AKIAOVERRIDEEXAMPLE',
            'secret_key' => 'overridesecretexamplekey',
            'expires' => 900,
        ]);

        $this->assertStringStartsWith('https://other-bucket.s3.ap-southeast-1.amazonaws.com/a.txt?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('AKIAOVERRIDEEXAMPLE/', $query['X-Amz-Credential']);
        $this->assertSame('900', $query['X-Amz-Expires']);
    }

    public function testSignS3UrlWithoutCredentialsThrows()
    {
        global $conf;
        $conf['plugin']['s3presigned']['aws_secret_key'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWS credentials not configured');

        $this->helper()->signS3Url('my-bucket', 'a.txt');
    }

    public function testSignCloudFrontUrlUsesConfiguredKey()
    {
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'videos/a.mp4');

        $this->assertStringStartsWith('https://cdn.example.com/videos/a.mp4?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(self::KEY_PAIR_ID, $query['Key-Pair-Id']);
        $this->assertNotEmpty($query['Signature']);
    }

    public function testSignCloudFrontUrlExpiryIsAnAbsoluteDeadline()
    {
        $before = time();
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', ['expires' => 900]);
        $after = time();

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertGreaterThanOrEqual($before + 900, (int)$query['Expires']);
        $this->assertLessThanOrEqual($after + 900, (int)$query['Expires']);
    }

    public function testSignCloudFrontUrlAcceptsAnInlinePem()
    {
        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
            'key_pair_id' => 'APKAOVERRIDEEXAMPLE',
            'private_key' => $this->pem,
        ]);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('APKAOVERRIDEEXAMPLE', $query['Key-Pair-Id']);
    }

    public function testSignCloudFrontUrlReadsAPrivateKeyFile()
    {
        $path = tempnam(sys_get_temp_dir(), 'cfkey');
        file_put_contents($path, $this->pem);

        try {
            $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
                'private_key_file' => $path,
            ]);
            $this->assertStringContainsString('Signature=', $url);
        } finally {
            unlink($path);
        }
    }

    public function testMissingPrivateKeyFileThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key file not found: /nonexistent/key.pem');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', [
            'private_key_file' => '/nonexistent/key.pem',
        ]);
    }

    public function testUnconfiguredPrivateKeyThrows()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CloudFront private key not configured');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4');
    }

    public function testEscapedNewlinesInAConfiguredPemAreRestored()
    {
        global $conf;
        // the config UI stores a pasted PEM with literal backslash-n
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = str_replace("\n", '\\n', $this->pem);

        $url = $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4');

        $this->assertStringContainsString('Signature=', $url);
    }

    public function testCloudFrontUrlIsUnsignedAndNeedsNoCredentials()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_key_pair_id'] = '';
        $conf['plugin']['s3presigned']['cf_private_key_pem'] = '';

        $url = $this->helper()->cloudFrontUrl('cdn.example.com', 'videos/a b.mp4');

        $this->assertSame('https://cdn.example.com/videos/a%20b.mp4', $url);
    }

    public function testGetCloudFrontCookiesReturnsCookiesAndOptions()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame(
            ['CloudFront-Policy', 'CloudFront-Signature', 'CloudFront-Key-Pair-Id'],
            array_keys($signed['cookies'])
        );
        $this->assertSame(self::KEY_PAIR_ID, $signed['cookies']['CloudFront-Key-Pair-Id']);

        $this->assertSame('/', $signed['options']['path']);
        $this->assertTrue($signed['options']['secure']);
        $this->assertTrue($signed['options']['httponly']);
        $this->assertSame('None', $signed['options']['samesite']);
    }

    public function testCookieExpiryReflectsTheRequestedDuration()
    {
        $before = time();
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*', ['expires' => 120]);
        $after = time();

        $this->assertGreaterThanOrEqual($before + 120, $signed['options']['expires']);
        $this->assertLessThanOrEqual($after + 120, $signed['options']['expires']);
    }

    public function testCookieDomainIsOmittedWhenNotConfigured()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertArrayNotHasKey('domain', $signed['options']);
    }

    public function testCookieDomainAndPathComeFromConfig()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_cookie_domain'] = '.example.com';
        $conf['plugin']['s3presigned']['cf_cookie_path'] = '/media/';

        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame('.example.com', $signed['options']['domain']);
        $this->assertSame('/media/', $signed['options']['path']);
    }

    public function testCookieOptionsCanBeOverriddenPerCall()
    {
        $signed = $this->helper()->getCloudFrontCookies('cdn.example.com', 'videos/*', [
            'cookie_domain' => '.override.test',
            'cookie_path' => '/v/',
            'samesite' => 'Lax',
            'secure' => false,
            'httponly' => false,
        ]);

        $this->assertSame('.override.test', $signed['options']['domain']);
        $this->assertSame('/v/', $signed['options']['path']);
        $this->assertSame('Lax', $signed['options']['samesite']);
        $this->assertFalse($signed['options']['secure']);
        $this->assertFalse($signed['options']['httponly']);
    }

    public function testSendCloudFrontCookiesReportsWhetherItCouldSend()
    {
        // Whether headers are already sent depends on how PHPUnit is buffering
        // output, so assert the guard's contract rather than a fixed value.
        $result = $this->helper()->sendCloudFrontCookies('cdn.example.com', 'videos/*');

        $this->assertSame(!headers_sent(), $result);
    }

    public function provideUnknownOptionCalls()
    {
        return [
            'signS3Url'            => ['signS3Url', ['my-bucket', 'a.txt']],
            'signCloudFrontUrl'    => ['signCloudFrontUrl', ['cdn.example.com', 'a.mp4']],
            'getCloudFrontCookies' => ['getCloudFrontCookies', ['cdn.example.com', 'videos/*']],
        ];
    }

    /**
     * A typo must not fall through to config and sign with the wrong
     * credentials, which would only fail later at the CDN.
     *
     * @dataProvider provideUnknownOptionCalls
     */
    public function testUnknownOptionThrows($method, $args)
    {
        $args[] = ['no_such_option' => 'x'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no_such_option');

        $this->helper()->$method(...$args);
    }

    public function testCookieOptionsAreRejectedByTheUrlMethods()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cookie_path');

        $this->helper()->signCloudFrontUrl('cdn.example.com', 'a.mp4', ['cookie_path' => '/']);
    }

    public function testGetMethodsDescribesEveryPublicApiMethod()
    {
        $methods = $this->helper()->getMethods();

        $names = array_column($methods, 'name');
        sort($names);

        $this->assertSame([
            'cloudFrontUrl',
            'getCloudFrontCookies',
            'sendCloudFrontCookies',
            'signCloudFrontUrl',
            'signS3Url',
        ], $names);

        // the core info plugin reads all four keys unconditionally
        foreach ($methods as $method) {
            foreach (['name', 'desc', 'params', 'return'] as $key) {
                $this->assertArrayHasKey($key, $method, $method['name'] ?? '?');
            }
            $this->assertNotEmpty($method['desc'], $method['name']);
            $this->assertIsArray($method['params'], $method['name']);
            $this->assertIsArray($method['return'], $method['name']);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose run --rm test --filter HelperApiTest`

Expected: FAIL — `Call to undefined method helper_plugin_s3presigned::signS3Url()`.

- [ ] **Step 3: Add the imports and option whitelists to helper.php**

At the top of `helper.php`, immediately after the `if (!defined('DOKU_INC')) die();` line, add:

```php
use dokuwiki\plugin\s3presigned\CloudFrontSigner;
use dokuwiki\plugin\s3presigned\S3Signer;
```

Then, as the first members inside `class helper_plugin_s3presigned`, add:

```php
    /** Option keys accepted by signS3Url() */
    protected const S3_OPTIONS = ['region', 'access_key', 'secret_key', 'expires'];

    /** Option keys accepted by every CloudFront method */
    protected const CF_OPTIONS = ['key_pair_id', 'private_key', 'private_key_file', 'expires'];

    /** Option keys accepted only by the cookie methods */
    protected const CF_COOKIE_OPTIONS = ['cookie_domain', 'cookie_path', 'secure', 'httponly', 'samesite'];
```

- [ ] **Step 4: Add the public API methods to helper.php**

Add these methods to the same class, before the existing `parseParams()`:

```php
    /**
     * Generate an AWS Signature V4 presigned GET URL for an S3 object.
     *
     * Intended for use by other plugins:
     *   $s3 = plugin_load('helper', 's3presigned');
     *   $url = $s3->signS3Url('my-bucket', 'docs/report.pdf');
     *
     * @param array $opts region, access_key, secret_key, expires; each falls
     *                    back to the plugin configuration when absent
     * @throws RuntimeException on unknown options or missing credentials
     */
    public function signS3Url($bucket, $objectKey, array $opts = [])
    {
        $this->rejectUnknownOptions($opts, self::S3_OPTIONS, 'signS3Url');

        $signer = new S3Signer(
            (string)($opts['region'] ?? $this->getConf('aws_region')),
            (string)($opts['access_key'] ?? $this->getConf('aws_access_key')),
            (string)($opts['secret_key'] ?? $this->getConf('aws_secret_key'))
        );

        return $signer->presign($bucket, $objectKey, $this->resolveExpires($opts, 'url_expiration'));
    }

    /**
     * Generate a CloudFront signed URL (canned policy, RSA-SHA1).
     *
     * @param array $opts key_pair_id, private_key, private_key_file, expires
     * @throws RuntimeException on unknown options or missing key material
     */
    public function signCloudFrontUrl($domain, $path, array $opts = [])
    {
        $this->rejectUnknownOptions($opts, self::CF_OPTIONS, 'signCloudFrontUrl');

        $expiresAt = time() + $this->resolveExpires($opts, 'cf_url_expiration');

        return $this->cloudFrontSigner($opts)->signUrl($domain, $path, $expiresAt);
    }

    /**
     * The unsigned CloudFront URL for an object, with each path segment encoded.
     *
     * Needed alongside the cookie methods: signed cookies carry the
     * authorisation, so the URL itself must stay bare. Uses no credentials and
     * therefore never throws for missing configuration.
     */
    public function cloudFrontUrl($domain, $path)
    {
        return CloudFrontSigner::url($domain, $path);
    }

    /**
     * Build CloudFront signed cookies without sending them, so the caller
     * decides when and how.
     *
     * The resource may contain a wildcard, e.g. 'videos/*'.
     *
     * @param array $opts the signCloudFrontUrl() options plus cookie_domain,
     *                    cookie_path, secure, httponly, samesite
     * @return array{cookies: array<string,string>, options: array}
     * @throws RuntimeException on unknown options or missing key material
     */
    public function getCloudFrontCookies($domain, $resource, array $opts = [])
    {
        $this->rejectUnknownOptions(
            $opts,
            array_merge(self::CF_OPTIONS, self::CF_COOKIE_OPTIONS),
            'getCloudFrontCookies'
        );

        $expiresAt = time() + $this->resolveExpires($opts, 'cf_url_expiration');
        $cookies = $this->cloudFrontSigner($opts)->cookiePolicy($domain, $resource, $expiresAt);

        $options = [
            'expires'  => $expiresAt,
            'path'     => (string)($opts['cookie_path'] ?? $this->getConf('cf_cookie_path')) ?: '/',
            'secure'   => (bool)($opts['secure'] ?? true),
            'httponly' => (bool)($opts['httponly'] ?? true),
            'samesite' => (string)($opts['samesite'] ?? 'None'),
        ];

        $cookieDomain = (string)($opts['cookie_domain'] ?? $this->getConf('cf_cookie_domain'));
        if ($cookieDomain !== '') {
            $options['domain'] = $cookieDomain;
        }

        return ['cookies' => $cookies, 'options' => $options];
    }

    /**
     * Build and send CloudFront signed cookies.
     *
     * @return bool false if headers were already sent, true once all three
     *              cookies have been issued
     * @throws RuntimeException on unknown options or missing key material
     */
    public function sendCloudFrontCookies($domain, $resource, array $opts = [])
    {
        if (headers_sent()) return false;

        $signed = $this->getCloudFrontCookies($domain, $resource, $opts);
        foreach ($signed['cookies'] as $name => $value) {
            setcookie($name, $value, $signed['options']);
        }

        return true;
    }

    /**
     * Describe the public API for the core info plugin, which renders this
     * with ~~INFO:helpermethods~~
     */
    public function getMethods()
    {
        return [
            [
                'name' => 'signS3Url',
                'desc' => 'Generate an AWS Signature V4 presigned GET URL for an S3 object. ' .
                    'Options: region, access_key, secret_key, expires (seconds).',
                'params' => [
                    'bucket' => 'string',
                    'objectKey' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['presigned URL' => 'string'],
            ],
            [
                'name' => 'signCloudFrontUrl',
                'desc' => 'Generate a CloudFront signed URL using a canned policy. ' .
                    'Options: key_pair_id, private_key, private_key_file, expires (seconds).',
                'params' => [
                    'domain' => 'string',
                    'path' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['signed URL' => 'string'],
            ],
            [
                'name' => 'cloudFrontUrl',
                'desc' => 'Build the unsigned CloudFront URL for an object, for use with signed cookies.',
                'params' => [
                    'domain' => 'string',
                    'path' => 'string',
                ],
                'return' => ['unsigned URL' => 'string'],
            ],
            [
                'name' => 'getCloudFrontCookies',
                'desc' => 'Build CloudFront signed cookies without sending them. ' .
                    'The resource may contain a wildcard. Returns the keys cookies and options.',
                'params' => [
                    'domain' => 'string',
                    'resource' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['cookies and cookie options' => 'array'],
            ],
            [
                'name' => 'sendCloudFrontCookies',
                'desc' => 'Build and send CloudFront signed cookies. False if headers were already sent.',
                'params' => [
                    'domain' => 'string',
                    'resource' => 'string',
                    'options (optional)' => 'array',
                ],
                'return' => ['sent' => 'bool'],
            ],
        ];
    }

    /**
     * Build a CloudFront signer from options merged over configuration
     */
    protected function cloudFrontSigner(array $opts)
    {
        return new CloudFrontSigner(
            (string)($opts['key_pair_id'] ?? $this->getConf('cf_key_pair_id')),
            $this->resolvePrivateKey($opts)
        );
    }

    /**
     * Read the RSA private key, preferring a file over a pasted PEM
     *
     * @throws RuntimeException if a configured file is missing or no key is set
     */
    protected function resolvePrivateKey(array $opts)
    {
        $keyFile = (string)($opts['private_key_file'] ?? $this->getConf('cf_private_key_file'));
        if ($keyFile !== '') {
            if (!file_exists($keyFile)) {
                throw new RuntimeException('CloudFront private key file not found: ' . $keyFile);
            }
            return (string)file_get_contents($keyFile);
        }

        $pem = (string)($opts['private_key'] ?? $this->getConf('cf_private_key_pem'));
        if ($pem === '') {
            throw new RuntimeException('CloudFront private key not configured (set file path or paste PEM)');
        }

        // the config UI stores newlines as literal \n
        return str_replace('\\n', "\n", $pem);
    }

    /**
     * Resolve the lifetime in seconds, falling back to config then to one hour
     */
    protected function resolveExpires(array $opts, $confKey)
    {
        $expires = (int)($opts['expires'] ?? $this->getConf($confKey));

        return $expires > 0 ? $expires : 3600;
    }

    /**
     * Reject a misspelled option rather than silently falling back to config,
     * which would sign with the wrong credentials and only fail at the CDN
     *
     * @throws RuntimeException
     */
    protected function rejectUnknownOptions(array $opts, array $allowed, $method)
    {
        $unknown = array_diff(array_keys($opts), $allowed);
        if ($unknown) {
            throw new RuntimeException(
                'Unknown option "' . reset($unknown) . '" for ' . $method . '(); allowed: ' .
                implode(', ', $allowed)
            );
        }
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker compose run --rm test --filter HelperApiTest`

Expected: PASS — 22 tests (3 from the unknown-option provider, 19 standalone).

- [ ] **Step 6: Run the whole group**

Run: `docker compose run --rm test`

Expected: PASS. The syntax and action components still use their own private
methods at this point; Task 5 rewires them.

- [ ] **Step 7: Document the API in README.md**

Insert this section into `README.md` between the "Usage" section and the
"AWS IAM Policy" section:

````markdown
## Calling from another plugin

Any DokuWiki plugin can use this plugin to sign URLs. Load the helper and call
it; every method falls back to this plugin's configuration, and every option can
be overridden per call.

```php
$s3 = plugin_load('helper', 's3presigned');
if (!$s3) return; // plugin not installed or disabled

// uses the configured region, credentials and expiry
$url = $s3->signS3Url('my-bucket', 'docs/report.pdf');

// or sign against a different account with a shorter lifetime
$url = $s3->signS3Url('other-bucket', 'export.zip', [
    'region'     => 'ap-southeast-1',
    'access_key' => $key,
    'secret_key' => $secret,
    'expires'    => 300,
]);
```

### Methods

| Method | Returns |
|---|---|
| `signS3Url($bucket, $objectKey, $opts = [])` | Presigned S3 URL (AWS Signature V4) |
| `signCloudFrontUrl($domain, $path, $opts = [])` | CloudFront signed URL (canned policy) |
| `cloudFrontUrl($domain, $path)` | Unsigned CloudFront URL, for use with cookies |
| `getCloudFrontCookies($domain, $resource, $opts = [])` | `['cookies' => [...], 'options' => [...]]` |
| `sendCloudFrontCookies($domain, $resource, $opts = [])` | `bool` — `false` if headers were already sent |

### Options

Every option falls back to the plugin configuration when omitted. `expires` is
always a duration in seconds.

| Option | Methods | Falls back to |
|---|---|---|
| `region` | S3 | `aws_region` |
| `access_key` | S3 | `aws_access_key` |
| `secret_key` | S3 | `aws_secret_key` |
| `expires` | all signing methods | `url_expiration` / `cf_url_expiration`, else 3600 |
| `key_pair_id` | CloudFront | `cf_key_pair_id` |
| `private_key` | CloudFront | `cf_private_key_pem` |
| `private_key_file` | CloudFront | `cf_private_key_file` |
| `cookie_domain` | cookies | `cf_cookie_domain` |
| `cookie_path` | cookies | `cf_cookie_path`, else `/` |
| `secure`, `httponly`, `samesite` | cookies | `true`, `true`, `None` |

An unrecognised option key throws, rather than silently falling back to
configuration and signing with the wrong credentials.

### Signed cookies

Cookies suit serving many files under one path, such as video segments. The
resource may contain a wildcard, and the URL you render stays unsigned because
the cookies carry the authorisation.

```php
$s3 = plugin_load('helper', 's3presigned');

$s3->sendCloudFrontCookies('d111abcdef8.cloudfront.net', 'videos/*');
$url = $s3->cloudFrontUrl('d111abcdef8.cloudfront.net', 'videos/lesson-1.m3u8');
```

To decide for yourself when the cookies are sent, use `getCloudFrontCookies()`,
which returns the three cookie values and the options to send them with.

### Errors

All failures throw `\RuntimeException`, including missing credentials, an
unreadable private key, and unrecognised options.

```php
try {
    $url = $s3->signS3Url('my-bucket', 'docs/report.pdf');
} catch (\Exception $e) {
    // handle a missing or invalid configuration
}
```

The methods are also listed inside the wiki itself on any page containing
`~~INFO:helpermethods~~`.
````

- [ ] **Step 8: Commit**

```bash
git add helper.php README.md _test/HelperApiTest.php
git commit -m "feat: expose URL and cookie signing on the helper

Other plugins can now load the helper and sign S3 or CloudFront URLs,
with every credential and expiry overridable per call. Unknown option
keys throw rather than silently falling back to config."
```

---

### Task 5: Rewire the syntax and action components

The last task deletes the now-duplicated signing code from the three components
so the helper is the single source of truth. Nothing user-visible changes, which
the regression test pins before the deletions happen.

**Files:**
- Create: `_test/RenderRegressionTest.php`
- Modify: `syntax.php` (rewrite `render()`, delete `generatePresignedUrl()`)
- Modify: `syntax/cloudfront.php` (rewrite `render()`, delete `generateSignedUrl()`, `loadPrivateKey()`, `rsaSign()`, `urlSafeBase64()`)
- Modify: `action.php` (rewrite `handleCookies()`, delete `loadPrivateKey()`, `urlSafeBase64()`)

**Interfaces:**
- Consumes: `signS3Url()`, `signCloudFrontUrl()`, `cloudFrontUrl()` and `sendCloudFrontCookies()` from Task 4, with the signatures listed there.
- Produces: no new interface. The components become callers only.

**Background the implementer needs:**

Write the regression test **first and run it against the current, unmodified
code**. It must pass before anything is deleted — that is what makes it a
regression test rather than a description of whatever the new code happens to do.

`$this->loadHelper('s3presigned')` returns the cached helper, which is correct in
production. Under test the cache can be stale for the reason described in Task 4,
so the regression test clears it in `setUp()`.

Three behaviours are easy to break and must survive:

1. `$renderer->info['cache'] = false` in both syntax components. Signed URLs
   expire, so pages containing them must never be cached.
2. The `metadata` render mode in `syntax/cloudfront.php`, which records
   `plugin_s3presigned_cf_cookies` for the action component to read later.
3. The `?cookies` branch renders an **unsigned** URL. The cookies carry the
   authorisation.

- [ ] **Step 1: Write the regression test**

Create `_test/RenderRegressionTest.php`:

```php
<?php

namespace dokuwiki\plugin\s3presigned\test;

use DokuWikiTest;

/**
 * Pins the rendered output of both syntax components so moving the signing
 * logic into the helper can be shown to change nothing a reader would see.
 *
 * Written and passing BEFORE the components are rewired.
 *
 * @group plugin_s3presigned
 * @group plugins
 */
class RenderRegressionTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['s3presigned'];

    protected const KEY_PAIR_ID = 'APKAIOSFODNN7EXAMPLE';

    public function setUp(): void
    {
        parent::setUp();

        $pem = '';
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($resource, $pem);

        global $conf;
        $conf['plugin']['s3presigned'] = [
            'aws_region' => 'us-east-1',
            'aws_access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'aws_secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'url_expiration' => 3600,
            'cf_key_pair_id' => self::KEY_PAIR_ID,
            'cf_private_key_file' => '',
            'cf_private_key_pem' => $pem,
            'cf_url_expiration' => 3600,
            'cf_cookie_domain' => '',
            'cf_cookie_path' => '/',
        ];

        // the components call loadHelper(), which returns the globally cached
        // instance; drop it so it reloads against the config set above
        global $DOKU_PLUGINS;
        unset($DOKU_PLUGINS['helper']['s3presigned']);
    }

    protected function render($text)
    {
        return p_render('xhtml', p_get_instructions($text), $info);
    }

    public function testS3ImageRendersAsALinkedImage()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="media"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('my-bucket.s3.us-east-1.amazonaws.com/images/photo.jpg', $html);
        $this->assertStringContainsString('X-Amz-Signature=', $html);
        $this->assertStringContainsString('alt="photo.jpg"', $html);
    }

    public function testS3DocumentRendersAsADownloadLink()
    {
        $html = $this->render('{{s3://my-bucket/docs/report.pdf|Download Report}}');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="s3-download"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('Download Report', $html);
        $this->assertStringContainsString('X-Amz-Signature=', $html);
    }

    public function testS3ImageHonoursSizeAndAlignment()
    {
        $html = $this->render('{{ s3://my-bucket/images/photo.jpg?300x200 |Caption}}');

        $this->assertStringContainsString('class="mediacenter"', $html);
        $this->assertStringContainsString('width="300"', $html);
        $this->assertStringContainsString('height="200"', $html);
        $this->assertStringContainsString('alt="Caption"', $html);
    }

    public function testS3ImageHonoursNolink()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg?nolink}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testS3ImageHonoursLinkonly()
    {
        $html = $this->render('{{s3://my-bucket/images/photo.jpg?linkonly|Photo}}');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('class="s3-download"', $html);
    }

    public function testS3ReportsAMissingConfigurationInline()
    {
        global $conf;
        $conf['plugin']['s3presigned']['aws_secret_key'] = '';

        $html = $this->render('{{s3://my-bucket/images/photo.jpg}}');

        $this->assertStringContainsString('class="s3-error"', $html);
        $this->assertStringContainsString('AWS credentials not configured', $html);
    }

    public function testCloudFrontImageIsSigned()
    {
        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}');

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('https://d111abcdef8.cloudfront.net/images/photo.jpg?', $html);
        $this->assertStringContainsString('Expires=', $html);
        $this->assertStringContainsString('Signature=', $html);
        $this->assertStringContainsString('Key-Pair-Id=' . self::KEY_PAIR_ID, $html);
    }

    public function testCloudFrontCookiesModeRendersAnUnsignedUrl()
    {
        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/videos/intro.mp4?cookies|Video}}');

        $this->assertStringContainsString('https://d111abcdef8.cloudfront.net/videos/intro.mp4', $html);
        $this->assertStringNotContainsString('Signature=', $html);
        $this->assertStringNotContainsString('Key-Pair-Id=', $html);
    }

    public function testCloudFrontReportsAMissingKeyInline()
    {
        global $conf;
        $conf['plugin']['s3presigned']['cf_key_pair_id'] = '';

        $html = $this->render('{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}');

        $this->assertStringContainsString('class="s3-error"', $html);
        $this->assertStringContainsString('CloudFront Key Pair ID not configured', $html);
    }

    /**
     * Exercises the same path action.php uses: metadata is rendered for a real
     * saved page and read back with p_get_metadata(). Calling p_render() with
     * the metadata mode directly would not work, since that renderer returns an
     * empty string and its document_end() expects the page to exist on disk.
     */
    public function testCloudFrontCookiesModeRecordsPageMetadata()
    {
        $id = 's3presigned_cookies_test';
        saveWikiText($id, '{{cf://d111abcdef8.cloudfront.net/videos/*?cookies}}', 'test setup');

        $entries = p_get_metadata($id, 'plugin_s3presigned_cf_cookies', METADATA_RENDER_UNLIMITED);

        $this->assertSame(
            [['domain' => 'd111abcdef8.cloudfront.net', 'path' => 'videos/*']],
            $entries
        );
    }

    public function testPagesWithSignedUrlsAreNotCached()
    {
        p_render('xhtml', p_get_instructions('{{s3://my-bucket/images/photo.jpg}}'), $info);

        $this->assertFalse($info['cache'], 'signed URLs expire, so the page must not be cached');
    }
}
```

- [ ] **Step 2: Run the regression test against the UNMODIFIED components**

Run: `docker compose run --rm test --filter RenderRegressionTest`

Expected: PASS — 12 tests, against the current code with its private signing
methods still in place. This is the whole point: the test describes today's
behaviour, so it can prove tomorrow's is the same.

If a test fails here, the *test* is wrong about current behaviour. Fix the test,
not the components.

- [ ] **Step 3: Commit the regression test on its own**

Committing it separately keeps the proof independent of the change it guards.

```bash
git add _test/RenderRegressionTest.php
git commit -m "test: pin rendered output of both syntax components

Written against the pre-refactor components so the rewiring that follows
can be shown to change nothing a reader sees."
```

- [ ] **Step 4: Rewire syntax.php**

Replace the `render()` method's `try` block and delete `generatePresignedUrl()`
entirely. The resulting file has no private methods left.

`render()` becomes:

```php
    public function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode != 'xhtml') return false;
        if ($data === false) return false;

        // Disable caching for pages with S3 presigned URLs
        // since URLs expire after a set time
        $renderer->info['cache'] = false;

        $helper = $this->loadHelper('s3presigned');

        try {
            $url = $helper->signS3Url($data['bucket'], $data['object']);
            $filename = basename($data['object']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }
```

Then delete the whole `private function generatePresignedUrl(...)` method, from
its opening line through its closing brace. It is the last method in the class.

- [ ] **Step 5: Run the regression test**

Run: `docker compose run --rm test --filter RenderRegressionTest`

Expected: PASS — the same 12 tests. The S3 tests now exercise the helper.

- [ ] **Step 6: Rewire syntax/cloudfront.php**

Replace the `?cookies` branch and the `try` block in `render()`, then delete the
four private methods.

The part of `render()` from the `?cookies` comment onward becomes:

```php
        $helper = $this->loadHelper('s3presigned');
        $filename = basename($data['path']);

        // If cookies mode, render unsigned URL (action plugin sets cookies)
        if ($data['params']['cookies']) {
            $url = $helper->cloudFrontUrl($data['domain'], $data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
            return true;
        }

        try {
            $url = $helper->signCloudFrontUrl($data['domain'], $data['path']);
            $helper->renderOutput($renderer, $url, $filename, $data['title'], $data['align'], $data['params']);
        } catch (Exception $e) {
            $renderer->doc .= '<span class="s3-error">Error: ' . hsc($e->getMessage()) . '</span>';
        }

        return true;
    }
```

Then delete these four methods in full: `generateSignedUrl()`,
`loadPrivateKey()`, `rsaSign()` and `urlSafeBase64()`. Leave `getType()`,
`getPType()`, `getSort()`, `connectTo()`, `handle()` and the `metadata` branch of
`render()` exactly as they are.

- [ ] **Step 7: Run the regression test**

Run: `docker compose run --rm test --filter RenderRegressionTest`

Expected: PASS — the same 12 tests, including the metadata and unsigned-URL
cases.

- [ ] **Step 8: Rewire action.php**

Replace `handleCookies()` and delete both private methods. The class becomes:

```php
class action_plugin_s3presigned extends DokuWiki_Action_Plugin {

    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, 'handleCookies');
    }

    /**
     * Set CloudFront signed cookies if the current page has cf:// cookie entries
     * in its metadata (stored by the syntax plugin during metadata render).
     */
    public function handleCookies(Doku_Event $event, $param) {
        global $ID;
        if (headers_sent()) return;

        $cookieEntries = p_get_metadata($ID, 'plugin_s3presigned_cf_cookies');
        if (empty($cookieEntries)) return;

        $helper = $this->loadHelper('s3presigned');
        if (!$helper) return;

        // Deduplicate by domain
        $domains = array();
        foreach ($cookieEntries as $entry) {
            $domains[$entry['domain']] = $entry;
        }

        foreach ($domains as $entry) {
            try {
                $helper->sendCloudFrontCookies($entry['domain'], $entry['path']);
            } catch (Exception $e) {
                // a misconfigured key must not break page rendering
                return;
            }
        }
    }
}
```

Delete `loadPrivateKey()` and `urlSafeBase64()`.

Note what this preserves: the `headers_sent()` guard, the metadata lookup, the
deduplication by domain, and swallowing configuration errors so a broken key
never stops a page from rendering. The old `function_exists('openssl_sign')` and
empty-key-pair-id guards are now enforced inside `CloudFrontSigner`, which throws
and is caught here.

- [ ] **Step 9: Run the whole test group**

Run: `docker compose run --rm test`

Expected: PASS — all four test classes.

- [ ] **Step 10: Confirm the duplication is gone**

Run:

```bash
grep -rn "openssl_sign\|urlSafeBase64\|loadPrivateKey\|hash_hmac" \
    syntax.php syntax/cloudfront.php action.php helper.php
```

Expected: no output. All cryptography now lives in the two signer classes.

- [ ] **Step 11: Verify in the running wiki**

Run:

```bash
docker compose up -d wiki
```

Then in a browser at `http://localhost:8080` (log in as `admin` / `admin`),
create a page containing:

```
{{s3://my-bucket/images/photo.jpg}}
{{cf://d111abcdef8.cloudfront.net/docs/report.pdf?linkonly|Report}}
~~INFO:helpermethods~~
```

Expected: the two entries render as a linked image and a download link whose
URLs carry signature parameters (the files do not exist, so the image itself
will not load — the generated URL is what matters), and the info block lists all
five helper methods with their parameters.

Then run `docker compose down`.

- [ ] **Step 12: Commit**

```bash
git add syntax.php syntax/cloudfront.php action.php
git commit -m "refactor: route all signing through the helper

The syntax and action components no longer sign anything themselves;
they resolve a URL through the helper and render it. Removes the
duplicated key loading and base64 helpers along with roughly 150 lines
of crypto from three files."
```

---

## Done

The plugin's signing is reachable by any other plugin through five documented
helper methods, the cryptography lives in two dependency-free classes covered by
differential tests against the pre-refactor implementation, and the whole thing
builds and runs in Docker without touching the host.
