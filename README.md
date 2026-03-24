# SCwriter Blog QA

SCwriter Blog QA adds a post-level QA panel to the WordPress editor so editors can run a consistent set of content checks before publishing.

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
For AI strategy checks:

- Add an OpenAI API key to `wp-content/plugins/scwriter-blog-qa/env.php`
- Start from `wp-content/plugins/scwriter-blog-qa/env.example.php`

If no OpenAI API key is configured in `env.php`, the meta box shows a warning and AI strategy checks are skipped.

## Installation

1. Place the plugin in `wp-content/plugins/scwriter-blog-qa`.
2. Activate **SCwriter Blog QA** from the WordPress plugins screen.
3. Copy `env.example.php` to `env.php`.
4. Add your real OpenAI API key to `env.php` if you want AI strategy checks.

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
- AI strategy feedback using `gpt-5-mini`

Some checks may be reported as skipped when their prerequisites are not available, such as missing SEO metadata or a missing OpenAI API key.

## Permissions

Only users who can edit the specific post can run QA for that post.

## Stored Data

The plugin stores QA data as post meta on the post being reviewed:

- Selected QA location
- Most recent QA results payload
- Timestamp of the last run

This allows the latest results to remain visible in the meta box after refresh.

## REST Endpoint

The QA run is exposed through the WordPress REST API at:

`/wp-json/scwriter-blog-qa/v1/check/{post_id}`

The endpoint requires normal WordPress authentication and post-level edit permission.

## Troubleshooting

**QA run is blocked before starting**

The Location field is required. Add it and run the check again.

**AI strategy checks are skipped**

Make sure `wp-content/plugins/scwriter-blog-qa/env.php` exists and defines `BLOGQA_OPENAI_API_KEY` with a real value.

**The Blog QA box does not appear**

The plugin currently adds the meta box only on the `post` edit screen.

## Notes

- The plugin is intentionally scoped to editorial QA for blog posts.
- It does not automatically modify content.
- It is designed to support repeatable manual review before publish or update.
