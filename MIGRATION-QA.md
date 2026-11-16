# QA checklist: moving a Bitrix site to Laravel

A checklist for verifying a migration done with this package, so the same
mistakes are not repeated from one project to the next.

## Main principle

**The reference is the live old site, not "did the data get transferred".** We
don't check "is there a row in the database"; we check "does every URL of the old
site open on the new one AND with the same content". A local copy of the database
can be out of sync with production, so compare **the live old site against the
live new site** only.

Static pages (hand-written `.php` files outside infoblocks) are out of scope for
this package: it migrates infoblock data. Pages that exist only as files on the
old server have to be carried over separately; this checklist helps you find them.

---

## Steps

### 1. Build a COMPLETE URL inventory from TWO sources, not one

- The old site's **sitemap.xml** (what search engines know about).
  Warning: it may be stale and miss pages that were added later.
- A **full FTP walk** of the old site (the real `.php` files on the server).
- Merge the two lists. The sitemap catches infoblock pages, the file walk catches
  static pages; each one alone is incomplete.

### 2. Run every URL against the new site

Use `curl` with status codes, following redirects (`-L`), and split the results
into 200 / 301 / 404. As a control, request a URL that certainly does not exist
on the old site: if it returns 404, the 200 answers from the other URLs are real
(Bitrix often serves a soft 404 with status 200).

### 3. For every 404, check the CONTENT of the old page, not just the status

This is the main trap. The page on the old site returns 200, but is empty inside:
- A placeholder such as `Type text here...`: an unfilled Bitrix stub (there was
  never any content).
- "Element not found" or an empty-list message: a broken URL or an empty
  component.

Empty on the old site means there is nothing to migrate, so it is NOT a
regression. Real content on the old site plus 404 on the new one is the real gap.

Extract the content from the content block with nested `<div>`s taken into
account (a regex that doesn't count nesting lies).

### 4. Tell static pages and dynamic sections apart

Sections such as `/catalog`, `/news`, `/search`, `/calendar` are served by
controllers from infoblock data, not as static pages. A file walk will find their
`.php` files, but they must not be imported as static content.

### 5. For every infoblock, check the whole chain, not just the data

**The data migrated does not mean the section works.** You need the model, a
public route, a controller and a view. If any of them is missing, the section
returns 404 even though the data is in the database.

Also, exceptions in a catch-all route (e.g. `info/faq`, `company/reviews`) orphan
a page when no dedicated route exists for it: it drops out of the catch-all and
has no handler of its own, so it returns 404. When you reserve a prefix,
write its handler at the same time.

### 6. Check data integrity, not just presence

- **Counters:** the number of elements in a section vs the old site.
- **Active flags:** with `active = NULL` the list is empty although the rows exist.
- **Non-deterministic sorting:** if every row has the same `sort` value (e.g. 500),
  MySQL returns rows in arbitrary order when paginating (duplicates on some
  pages, gaps on others). Fix it with a tiebreaker, `->orderBy('id')` or
  `->orderByDesc('id')`, in ALL lists.

### 7. Decide the policy for empty pages UP FRONT

For unfilled placeholders on the old site, should the new site return a clean
**404**, or an **empty 200 page** (URL parity for SEO, editable in the admin)?
Pick one deliberately, at least for pages the menu links to.

### 8. Dead links in the menu

After the migration, walk through the menu items and check that none of them
leads to a 404 or an empty page. Also check call-to-action buttons on the home
page: they are often the last thing to point at a removed section.

---

## Worth automating

Steps 1 to 4 are a good candidate for an artisan command, for example
`atrium:bitrix:verify-migration`: collect URLs (sitemap + FTP), run them against
the new site, classify the results (OK / missing with content / missing but
empty / dynamic) and produce a report. Manual `curl` over 200+ URLs does not
scale, and migrations will be repeated.
