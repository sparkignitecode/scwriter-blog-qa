# Spark Ignite Blog QA

Spark Ignite Blog QA adds a post-level QA panel to the WordPress editor so editors can run a consistent set of content checks before publishing.

It is built for the `post` post type and focuses on blog-specific QA such as keyword placement, metadata, image coverage, location references, heading structure, and AI-reviewed strategy checks.

## What It Does

From the post edit screen, the plugin adds a **Blog QA** meta box where an editor can:

- Enter a required target location for the QA run
- Run a full QA pass for the current post
- Review pass, fail, and skipped results in the editor
- Re-run checks after making content changes

If no QA-specific location has been saved yet, the Location field defaults from the post's `brand_name` meta.

Checks are grouped into two broad categories:

- Programmatic checks for content structure and metadata
- AI-assisted checks for higher-level strategy review

## Requirements

- WordPress with access to edit posts
- The `scwriter-blog-qa` plugin installed and activated
- A valid location entered before running QA

For AI strategy checks, the OpenAI API key source depends on the install type:

- Single site: `SEO Blog Writer > Settings`
- Multisite: `Network Admin > Blog QA`

On single-site installs, Blog QA uses the OpenAI setting stored by the main SCwriter plugin. On multisite, Blog QA uses its own network-wide OpenAI key.

The key is stored encrypted in WordPress options using installation secrets from `wp-config.php`. If no OpenAI API key is configured, the meta box shows a warning and AI strategy checks are skipped.

## Installation

1. Place the plugin in `wp-content/plugins/scwriter-blog-qa`.
2. Activate **Spark Ignite Blog QA** from the WordPress plugins screen.
3. Configure OpenAI for the current install type:
4. Single site: save the OpenAI API key in `SEO Blog Writer > Settings`.
5. Multisite: save the OpenAI API key in `Network Admin > Blog QA`.

On multisite installs, only network administrators can save the Blog QA OpenAI key and the same key is used across all subsites.

## How To Use

1. Open a `post` in the WordPress editor.
2. Find the **Blog QA** meta box.
3. Enter the location you want the post evaluated against.
4. Click **Run QA**.
5. Review the returned results and update the post as needed.
6. Run QA again to confirm fixes.

## What Gets Checked

The plugin evaluates the post against a fixed checklist that includes:

- Keyword placement in title, introduction, headings, and body copy
- Content quality signals such as structure and coverage
- Meta title and meta description quality
- Image coverage and alt-text related checks
- Location usage and heading/count rules tied to the selected location
- Main keyword coverage within the post's `keywords` meta
- AI strategy feedback using the configured OpenAI model for title, keyword intent, and grammar on HTML-stripped post content

Some checks may be reported as skipped when their prerequisites are not available, such as missing SEO metadata or a missing OpenAI API key.

## Permissions

Only users who can edit the specific post can run QA for that post.

## Stored Data

The plugin stores QA data as post meta on the post being reviewed:

- Selected QA location
- Most recent QA results payload
- Timestamp of the last run

This allows the latest results to remain visible in the meta box after refresh.

When AI checks are enabled on multisite, the plugin stores one encrypted OpenAI API key in network `sitemeta`.

On single-site installs, Blog QA reads the OpenAI setting from the SCwriter plugin instead of storing a separate Blog QA key.

## REST Endpoint

The QA run is exposed through the WordPress REST API at:

`/wp-json/scwriter-blog-qa/v1/check/{post_id}`

The endpoint requires normal WordPress authentication and post-level edit permission.

## Troubleshooting

**QA run is blocked before starting**

The Location field is required. Add it and run the check again.

**AI strategy checks are skipped**

Make sure an administrator has saved a valid OpenAI API key in the correct location:

- Single site: `SEO Blog Writer > Settings`
- Multisite: `Network Admin > Blog QA`

On single-site installs, if SCwriter shows OpenAI as configured but Blog QA still skips AI checks, re-save the OpenAI key in `SEO Blog Writer > Settings` so Blog QA can refresh the usable value.

If WordPress salts or keys in `wp-config.php` change, save the OpenAI API key again so Blog QA can decrypt it with the new installation secrets.

If you see a 401-style OpenAI error in Blog QA on multisite, re-save the correct API key in `Network Admin > Blog QA`. Invalid submitted keys are rejected and the previous network key is preserved.

**The Blog QA box does not appear**

The plugin currently adds the meta box only on the `post` edit screen.

## Notes

- The plugin is intentionally scoped to editorial QA for blog posts.
- It does not automatically modify content.
- It is designed to support repeatable manual review before publish or update.
