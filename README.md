# DokuWiki S3 Presigned URL Plugin

A DokuWiki plugin that allows embedding S3 and CloudFront files/images using signed URLs with `{{s3://bucket/path}}` and `{{cf://domain/path}}` syntax.

## Features

- Generate presigned S3 URLs for private bucket objects
- Generate CloudFront signed URLs (RSA-SHA1) for CloudFront distributions
- CloudFront signed cookies for serving multiple files under a path
- Embed images directly in wiki pages
- Support for DokuWiki-style image parameters (sizing, alignment, linking options)
- Custom display names for links
- Pure PHP implementation (no SDK required)

## Installation

1. Copy the plugin folder to `lib/plugins/s3presigned/`
2. The directory structure should be:
   ```
   lib/plugins/s3presigned/
   ├── syntax.php
   ├── syntax/
   │   └── cloudfront.php
   ├── helper.php
   ├── action.php
   ├── plugin.info.txt
   └── conf/
       ├── default.php
       └── metadata.php
   ```

## Configuration

Configure the plugin in **Admin > Configuration Settings > s3presigned**:

### S3 Settings

| Setting | Description |
|---------|-------------|
| `aws_region` | AWS region (e.g., `us-west-2`) |
| `aws_access_key` | AWS access key ID |
| `aws_secret_key` | AWS secret access key |
| `url_expiration` | URL expiration time in seconds (default: 3600) |

### CloudFront Settings

| Setting | Description |
|---------|-------------|
| `cf_key_pair_id` | CloudFront Key Pair ID (from CloudFront public key) |
| `cf_private_key_file` | Path to RSA private key PEM file on server (recommended) |
| `cf_private_key_pem` | Direct-paste PEM private key (fallback; use `\n` for newlines) |
| `cf_url_expiration` | Signed URL/cookie expiration in seconds (default: 3600) |
| `cf_cookie_domain` | Domain for signed cookies (e.g., `.example.com`) |
| `cf_cookie_path` | Path scope for signed cookies (default: `/`) |

> **Note:** `cf_private_key_file` takes priority over `cf_private_key_pem`. Storing the key as a file is recommended for security. The CloudFront feature requires the PHP `openssl` extension.

### Content Security Policy

If images fail to load due to CSP restrictions, add your S3/CloudFront domain to `conf/local.php`:

```php
$conf['plugin']['cspheader']['imgsrcValue'] = '\'self\' https://*.amazonaws.com https://*.cloudfront.net data:';
```

## Usage

### S3 Presigned URLs

#### Files (Download Links)

```
{{s3://my-bucket/documents/report.pdf}}
{{s3://my-bucket/documents/report.pdf|Download Report}}
```

#### Images

Images are auto-detected by extension (png, jpg, jpeg, gif, webp, svg, bmp, ico).

```
{{s3://my-bucket/images/photo.jpg}}
{{s3://my-bucket/images/photo.jpg|Alt text}}
```

#### Image Sizing

```
{{s3://my-bucket/images/photo.jpg?200}}         // Width 200px
{{s3://my-bucket/images/photo.jpg?200x150}}     // Width 200px, height 150px
```

#### Image Alignment

Alignment is determined by spaces before the `|` (or `}}` if no title):

```
{{s3://my-bucket/images/photo.jpg |Caption}}    // Left aligned (space before |)
{{ s3://my-bucket/images/photo.jpg|Caption}}    // Right aligned (space after {{)
{{ s3://my-bucket/images/photo.jpg |Caption}}   // Centered (both spaces)
```

#### Image Options

```
{{s3://my-bucket/images/photo.jpg?nolink}}      // No clickable link
{{s3://my-bucket/images/photo.jpg?direct}}      // Direct link to image
{{s3://my-bucket/images/photo.jpg?linkonly}}    // Text link instead of embedded image
```

#### Combined Parameters

Use `&` to combine multiple parameters:

```
{{s3://my-bucket/images/photo.jpg?200&nolink}}
{{ s3://my-bucket/images/photo.jpg?300x200&nolink |Photo caption}}   // Centered
```

### CloudFront Signed URLs

The `cf://` syntax works identically to `s3://` but generates CloudFront signed URLs instead.

```
{{cf://d111abcdef8.cloudfront.net/images/photo.jpg}}
{{cf://d111abcdef8.cloudfront.net/images/photo.jpg|Alt text}}
{{cf://d111abcdef8.cloudfront.net/images/photo.jpg?200x150}}
{{ cf://d111abcdef8.cloudfront.net/images/photo.jpg |Centered}}
{{cf://d111abcdef8.cloudfront.net/documents/report.pdf?linkonly|Download}}
```

All S3 parameters (sizing, alignment, nolink, direct, linkonly) work with CloudFront URLs.

### CloudFront Signed Cookies

Use the `?cookies` parameter to set CloudFront signed cookies instead of generating a signed URL. This is useful when serving multiple files under a path pattern (e.g., video segments, image galleries).

```
{{cf://d111abcdef8.cloudfront.net/videos/*?cookies}}
```

When `?cookies` is used:
- The rendered URL is unsigned (the browser uses cookies for authentication)
- Three cookies are set: `CloudFront-Policy`, `CloudFront-Signature`, `CloudFront-Key-Pair-Id`
- Cookies are configured with `Secure`, `HttpOnly`, and `SameSite=None` flags
- Configure `cf_cookie_domain` and `cf_cookie_path` in plugin settings

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

## AWS IAM Policy

### S3 Direct Access

Minimum required permissions for the IAM user:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::your-bucket-name/*"
        }
    ]
}
```

### CloudFront Setup

1. Create a CloudFront distribution with your S3 bucket as origin
2. Create a CloudFront public key and key group
3. Associate the key group with your distribution's cache behavior
4. Configure the plugin with the Key Pair ID and the corresponding RSA private key

## Security Notes

- Use an IAM user with minimal permissions (only `s3:GetObject` on specific buckets)
- For CloudFront, store the RSA private key file outside the web root
- Set appropriate URL/cookie expiration times
- Pages with S3/CloudFront syntax are not cached to ensure fresh signed URLs

## License

GPL 2 - http://www.gnu.org/licenses/gpl-2.0.html
