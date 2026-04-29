=== Habit Creator ===
Contributors: annemccarthy
Tags: dashboard, blogging, patterns, ai
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Spots recurring patterns in your blogging history and nudges you to write the next installment.

== Description ==

Habit Creator adds a dashboard widget that detects when you have written about
the same topic, in the same tag/category, or used the same recurring phrase
across multiple years. It surfaces upcoming "anniversary" patterns and lets
you spin up a pre-populated draft with one click — automatically linking back
to the previous post and carrying over its tags and categories.

= How it works without AI =

A daily cron job scans each author's published posts, buckets them by ISO
week-of-year, and looks for signals that recur across two or more distinct
years:

* Shared tags
* Shared categories
* Shared 2-word title phrases (with stopwords filtered)

Each pattern is scored by years-of-recurrence and reader engagement (comment
count). Patterns whose anniversary window is within the next three weeks are
surfaced first. Results are cached in a user-scoped transient.

= How it works with AI =

If a connector with `type === 'ai_provider'` is registered (WordPress 7.0
Connectors API), Habit Creator uses the AI Client to:

* Rewrite the nudge headline in a warmer, more personal voice
* Generate a draft opening paragraph that frames the new post as a continuation

Detection is a soft check on `wp_get_connectors()` plus
`wp_ai_client_prompt()`. If anything fails, the deterministic copy is used
instead — the widget never breaks because of an AI outage or missing key.

== Changelog ==

= 0.1.0 =
* Initial release.
