# DokuWiki S3 Presigned URL Plugin

A DokuWiki plugin that allows embedding S3 files and images using presigned URLs with the familiar `{{s3://bucket/path}}` syntax.

## Features

- Generate presigned S3 URLs for private bucket objects
- Embed images directly in wiki pages
- Support for DokuWiki-style image parameters (sizing, alignment, linking options)
- Custom display names for links
- AWS Signature V4 authentication (no SDK required)

## Installation

1. Copy the plugin folder to `lib/plugins/s3presigned/`
2. The directory structure should be:
   ```
   lib/plugins/s3presigned/
   ├── syntax.php
   ├── plugin.info.txt
   └── conf/
       ├── default.php
       └── metadata.php
   ```

## Configuration

Configure the plugin in **Admin > Configuration Settings > s3presigned**:

| Setting | Description |
|---------|-------------|
| `aws_region` | AWS region (e.g., `us-west-2`) |
| `aws_access_key` | AWS access key ID |
| `aws_secret_key` | AWS secret access key |
| `url_expiration` | URL expiration time in seconds (default: 3600) |

### Content Security Policy

If images fail to load due to CSP restrictions, add your S3 domain to `conf/local.php`:

```php
$conf['plugin']['cspheader']['imgsrcValue'] = '\'self\' https://*.amazonaws.com data:';
```

## Usage

### Files (Download Links)

```
{{s3://my-bucket/documents/report.pdf}}
{{s3://my-bucket/documents/report.pdf|Download Report}}
```

### Images

Images are auto-detected by extension (png, jpg, jpeg, gif, webp, svg, bmp, ico).

```
{{s3://my-bucket/images/photo.jpg}}
{{s3://my-bucket/images/photo.jpg|Alt text}}
```

### Image Sizing

```
{{s3://my-bucket/images/photo.jpg?200}}         // Width 200px
{{s3://my-bucket/images/photo.jpg?200x150}}     // Width 200px, height 150px
```

### Image Alignment

Alignment is determined by spaces before the `|` (or `}}` if no title):

```
{{s3://my-bucket/images/photo.jpg |Caption}}    // Left aligned (space before |)
{{ s3://my-bucket/images/photo.jpg|Caption}}    // Right aligned (space after {{)
{{ s3://my-bucket/images/photo.jpg |Caption}}   // Centered (both spaces)
```

### Image Options

```
{{s3://my-bucket/images/photo.jpg?nolink}}      // No clickable link
{{s3://my-bucket/images/photo.jpg?direct}}      // Direct link to image
{{s3://my-bucket/images/photo.jpg?linkonly}}    // Text link instead of embedded image
```

### Combined Parameters

Use `&` to combine multiple parameters:

```
{{s3://my-bucket/images/photo.jpg?200&nolink}}
{{ s3://my-bucket/images/photo.jpg?300x200&nolink |Photo caption}}   // Centered
```

## AWS IAM Policy

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

## Security Notes

- Use an IAM user with minimal permissions (only `s3:GetObject` on specific buckets)
- Set appropriate URL expiration times
- Pages with S3 syntax are not cached to ensure fresh presigned URLs

## License

GPL 2 - http://www.gnu.org/licenses/gpl-2.0.html
