# customfield_keywords

## Overview

`customfield_keywords` is a Moodle **custom course field** plugin (`customfield_*`, part of the
`core_customfield` framework — the same subsystem behind `customfield_text`, `customfield_select`,
etc.). It adds a "Keywords" field that admins can attach to courses from **Site administration >
Courses > Custom fields**.

Unlike a plain text or select field, this one behaves like Moodle's native **course Tags**
field: teachers can enter multiple free-text values, and the field suggests values already used
on other courses via the same ajax autocomplete widget Moodle uses for tags everywhere else
(forum posts, blog posts, questions, etc.).

**It intentionally reuses Moodle's own tagging subsystem (`core_tag`) instead of inventing a
parallel "keywords" table.** The custom-field UI is just the entry point; the actual storage,
autocomplete search, and admin management ("standardise a tag", "combine tags", "rename a
tag") are 100% the same `core_tag_tag` API and `{tag}` / `{tag_instance}` tables that already
power core tagging. In other words: **same functionality as core tags, wired into a course
custom field instead of the course edit form's built-in "Tags" section.**

## Why not just use the customfield "select"/"multiselect" type?

Select-style custom fields need the admin to pre-define a fixed list of options. That does not
give the "type a new value, or reuse one that already exists elsewhere on the site" behaviour
that was asked for. Only Moodle's tag autocomplete widget provides that, so this plugin bridges
the two systems: a `core_customfield` field controller on the outside, `core_tag_tag` storage
on the inside.

## Why not just use the customfield "checkbox"/native course "Tags" field?

The native course "Tags" field (`component=core`, `itemtype=course`) already does almost this,
but it is a fixed part of the course edit form, not a configurable custom field, and its
suggestions come from the site's single default tag collection (mixed with tags from forums,
blogs, questions, etc). This plugin registers its **own tag area and its own tag collection**
(see `db/tag.php`), so "Keywords" suggestions only ever come from other courses' keywords —
never from unrelated tags elsewhere on the site — while still living alongside every other
admin-configurable course custom field.

## How it works

- **`classes/field_controller.php`** — the field definition (admin side). No extra
  configuration beyond what `core_customfield` already provides (name, description, required,
  visibility); keyword values are free text, not admin-defined options.
- **`classes/data_controller.php`** — the core of the plugin. `core_customfield`'s
  `{customfield_data}` table only allows **one value per (field, course)**, so it cannot hold a
  genuinely multi-value field by itself. This controller:
  - injects Moodle's native `tags` form element (`itemtype=course_keyword`,
    `component=customfield_keywords`) into the course edit form — this is what gives the
    multi-value input and ajax autocomplete for free;
  - on save, calls `core_tag_tag::set_item_tags()` to persist the actual keyword list in
    `{tag_instance}`, instead of writing to `{customfield_data}`, and additionally mirrors the
    same list as JSON in `{customfield_data}.value` (see "Backup and restore" below for why);
  - additionally marks newly-created keywords as **standard tags** (`isstandard=1`), scoped to
    this plugin's own tag collection only, so a keyword becomes an autocomplete suggestion for
    every other course as soon as it's used once — without an admin having to flip "Standard"
    manually on `/tag/manage.php`;
  - on load/export/delete, reads/removes from `{tag_instance}` the same way — `{tag_instance}` is
    always the live source of truth for reads and autocomplete, never `{customfield_data}`.
- **`db/tag.php`** — registers the tag area (`component=customfield_keywords`,
  `itemtype=course_keyword`) under its own tag collection, so keyword suggestions never mix
  with the standard course "Tags" field or any other tag area on the site.
- **`lib.php`** — the tag area callback (`customfield_keywords_get_tagged_courses`), used by
  `/tag/index.php` to list courses tagged with a given keyword. It does **not** delegate to
  `core_course_category::search_courses()`/`search_courses_count()` the way core's own
  `course_get_tagged_courses()` does: those methods' `tagid` search criterion hardcodes
  `component=core, itemtype=course` internally (`course/classes/category.php`), so they can
  never find rows tagged under our own component/itemtype. This callback queries
  `{tag_instance}` directly instead, and renders the result with the same `core_tag/tagfeed`
  template core itself falls back to in non-exclusive mode (the nicer card-style listing core
  uses for its native Tags area, `core_course_renderer::coursecat_courses()`, is a protected
  method with no public equivalent, so it isn't reused here).
- **`classes/privacy/provider.php`** — declared as a `null_provider`: keywords are course-level
  classification metadata, not personal data, and `set_item_tags()` is always called without a
  `tiuserid`, so the resulting `tag_instance` rows are never attributed to an individual user.

### Multiple Keywords fields

An admin can configure more than one custom field of type Keywords (e.g. "Categories" and a
second, separately-named field) — each behaves independently and doesn't share keyword data
with the others.

All fields of this type share the same `component`/`itemtype` (`customfield_keywords`/
`course_keyword`), since that's fixed per plugin type, not per field instance — Moodle only
reads `db/tag.php` once, at install/upgrade time, so a dynamic itemtype per field id can't be
registered there. `set_item_tags()` is destructive per `component+itemtype+itemid+context`, so
if two Keywords fields both used the course id as `itemid`, saving one field would silently wipe
out the other's tags.

Instead, the `tag_instance.itemid` used here is this field **instance's own**
`{customfield_data}.id` — a real, persisted row unique per (field, course) — rather than the
shared course id. This keeps each field's keywords in their own non-colliding namespace, the same
way core's `question` tag area uses the real question id as `itemid` instead of a shared one
(see `core_tag_area::allows_tagging_in_multiple_contexts()` for that precedent). Practical
consequence: `data_controller::instance_form_save()` must persist the `customfield_data` row
first (so a brand new field instance gets an id) before it links any keywords in `tag_instance`.

**Autocomplete suggestions, however, are shared across every Keywords field on the site** —
that part is *not* isolated per field, only per-course storage is. If two fields are both used
across many courses, typing in either one will suggest keywords used anywhere via any Keywords
field, not just "its own". This was investigated and deliberately left as-is: isolating
suggestions per field would need Moodle to resolve a distinct tag collection per field id, but
`core_tag_area::get_collection()` always resolves the one fixed collection registered for this
plugin's `component`/`itemtype` in `db/tag.php`, and that file is only read once, at
install/upgrade — there's no supported way to register a tag_area (and thus a tag collection)
per field id at runtime. Passing a distinct `tagcollid` straight to the `tags` form element
(which does accept one) was tried, but `set_item_tags()` — used for actually saving keywords —
always keeps writing into the fixed shared collection regardless, with no override available; a
distinct per-field collection would only ever contain tags nobody had written to it, so
suggestions there would always come back empty. There is no known way to fix this without
Moodle core changes.

### Cosmetic note: the "Manage standard tags" link

The tags form element shows a "Manage standard tags" link next to the field, pointing at
`/tag/manage.php?tc=<our tag collection id>` — clicking it only ever manages *this* field's
keywords (each tag collection is independent), so it's functionally correct. The wording itself
is generic and can't be customised per plugin: `lib/form/tags.php` always calls
`get_string('managestandardtags', 'tag')`, the same core string used by every other tags element
on the site (course Tags, forum posts, blogs...). Overriding that string would rename the link
everywhere, not just here, so this is left as-is.

## Where the data lives

| Table            | What's stored                                                          |
|-------------------|-------------------------------------------------------------------------|
| `customfield_data` | One row per course, `value` = JSON-encoded array of that course's keywords (e.g. `["hola","hola2"]`). Written on every save purely so course backup/restore has something to round-trip (see below) — never read from directly at runtime. |
| `tag`              | One row per distinct keyword name, in the `customfield_keywords` tag collection. |
| `tag_instance`     | Links a keyword (`tagid`) to `itemid = this field instance's own customfield_data.id` (**not** the course id — see "Multiple Keywords fields" below), under `component=customfield_keywords`, `itemtype=course_keyword`. This is the live source of truth: all reads, the course edit form, and autocomplete suggestions come from here, not from `customfield_data`. |

## Installation

This plugin lives at `customfield/field/keywords` and is installed the same way as any other
`customfield_*` subplugin: copy/symlink it into your Moodle codebase and run the upgrade
(`admin/cli/upgrade.php` or Site administration > Notifications). Once installed, create a
field of type **Keywords** under **Site administration > Courses > Custom fields**.

## Backup and restore

Course backup, restore, and "Copy course" all carry keyword data over correctly — verified
end-to-end (backup → restore as new course, and the one-step "Copy course" tool both checked
against the database and the UI).

This needed explicit handling: Moodle's course backup only copies `tag_instance` rows for the
native `component=core, itemtype=course` tag area, never third-party tag areas like this one's
(`component=customfield_keywords, itemtype=course_keyword`). It also turns out a
`data_controller` has **no way to add its own XML nodes** to the backup/restore file — the course
backup XML's `<customfield>` element structure, and the set of restore paths Moodle parses it
with, are both fixed by core (`backup_course_structure_step` /
`restore_course_structure_step::define_structure()`); a `data_controller` callback can't extend
either one. The only thing it can influence is the existing `<customfield><value>` node, since
`core_course\customfield\course_handler::get_instance_data_for_backup()` always writes
`data_controller::get_value()`'s return value there.

So that's the mechanism this plugin relies on:
- **`get_value()`** returns the keyword list as a JSON string, which is what ends up in
  `<customfield><value>` during backup (mirrored, on every save, into
  `customfield_data.value` — see the table above).
- **`backup_define_structure()`** is a deliberate no-op: nothing more is needed once `get_value()`
  is backed up through the standard node.
- **`restore_define_structure()`** reads that same JSON back from the just-restored
  `customfield_data` row (by the time this runs, `$newid` already points at a persisted row) and
  calls `set_item_tags()` to rebuild the `tag_instance` rows against the new/duplicated course's
  id and context — since, per above, that's the one thing core's restore never does for
  third-party tag areas on its own.

## License

GNU GPL v3 or later, same as Moodle core.
