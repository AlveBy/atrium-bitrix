# Atrium Bitrix migrator

Moves a 1C-Bitrix site to Laravel and the [Atrium](https://github.com/AlveBy/atrium) admin panel: models, migrations, admin forms, data, files, SEO and the media library, then proves nothing was lost.

The Bitrix database is only read. Works with MySQL, PostgreSQL and SQLite on the Laravel side.

## Install

```bash
composer require alveby/atrium-bitrix
php artisan vendor:publish --tag=atrium-bitrix-config
```

Add a connection to the Bitrix database in `config/database.php` (the default name is `bitrix`, see `BITRIX_DB_CONNECTION`) and copy Bitrix's `/upload` folder into `public/upload`, or fetch it over FTP with `atrium:bitrix:download:upload`.

## Move a site

```bash
php artisan atrium:bitrix:analyze               # what the old database holds
php artisan atrium:bitrix:generate:migrations
php artisan migrate
php artisan atrium:bitrix:generate:models
php artisan atrium:bitrix:migrate:data
php artisan atrium:bitrix:migrate:seo
php artisan atrium:bitrix:migrate:medialibrary
php artisan atrium:bitrix:parity                # before against after
```

`atrium:bitrix:migrate` runs the whole sequence. Every command asks which iblocks to take; without a terminal (`--no-interaction`) it takes all of them.

## What moves

- Iblocks become models and tables: base fields, every property type (strings, numbers, lists, files, links to elements and sections, highload directories, HTML, dates, maps), multiple values, sections with their tree and `UF_*` fields, highload blocks.
- The admin form looks like the one editors had: tabs and field order from the saved Bitrix form layout, list columns from the saved list settings, captions such as "Add product" from the iblock messages.
- SEO: meta title, description, keywords, page heading, picture ALT and TITLE, tags.
- The media library with its collections.

## Checking

`atrium:bitrix:parity` compares every iblock and property with the generated models and the copied rows: counts, columns, widgets, required flags, list options, form tabs and order, list columns. An empty report means nothing was lost. `--format=json` or `--format=markdown` writes it to a file; the command fails when something is missing.

`atrium:bitrix:verify` spot-checks records and files.

## While the old site is still live

`atrium:bitrix:sync` copies only what changed in Bitrix since the move: new elements, elements edited later than our copy, and with `--prune` removes what Bitrix deleted. `--audit` compares everything and writes nothing. Columns listed in `sync_protected` keep the new site's value.

## Configuration

`config/atrium-bitrix.php`:

- `strip_prefixes`: theme prefixes to cut from iblock codes, so `mytheme_news` becomes the `news` table and the `News` model;
- `model_namespace`, `model_path`, `migration_path`: where the generated code goes;
- `extra_fields`: columns your project added itself, so regeneration keeps them;
- `hidden_list_columns`, `extra_list_columns`: list columns that differ from Bitrix on purpose;
- `element_link_targets`: the target iblock of a link property that allows "any iblock";
- `skip_iblocks`, `naming.mappings`, `migration.chunk_size`.

After regenerating models, look at `git diff`: generated files are meant to be regenerated, not edited.

[MIGRATION-QA.md](MIGRATION-QA.md) is a checklist for accepting a moved site.

## License

MIT
