<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | The old site's database
    |--------------------------------------------------------------------------
    |
    | A connection name from config/database.php pointing at the Bitrix
    | database. The migrator only reads from it.
    |
    */
    'connection' => env('BITRIX_DB_CONNECTION', 'bitrix'),

    /*
    |--------------------------------------------------------------------------
    | The old site's upload folder
    |--------------------------------------------------------------------------
    |
    | Bitrix's /upload directory, copied next to the new site (or fetched with
    | atrium:bitrix:download:upload). Files are taken from here.
    |
    */
    'upload_path' => env('BITRIX_UPLOAD_PATH'),

    // Where atrium:bitrix:download:upload fetches /upload from.
    'ftp' => [
        'host' => env('BITRIX_FTP_HOST'),
        'user' => env('BITRIX_FTP_USER'),
        'password' => env('BITRIX_FTP_PASSWORD'),
        'root' => env('BITRIX_FTP_ROOT', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the generated code goes
    |--------------------------------------------------------------------------
    */
    'model_namespace' => 'App\\Models',
    'reference_namespace' => 'App\\Models\\References',
    'model_path' => null, // null = the folder PSR-4 gives the namespace
    'reference_path' => null,
    'migration_path' => null, // null = database_path('migrations')

    /*
    |--------------------------------------------------------------------------
    | Prefixes cut from names
    |--------------------------------------------------------------------------
    |
    | Bitrix solutions prefix their iblock codes with the theme name
    | (`mytheme_news`, `mytheme_catalog`). List those prefixes here and the
    | tables and models are called `news` / `News` instead of
    | `mytheme_news` / `MythemeNews`.
    |
    */
    'strip_prefixes' => [],

    /*
    |--------------------------------------------------------------------------
    | Iblocks to leave behind
    |--------------------------------------------------------------------------
    |
    | Iblock IDs or codes the migrator does not touch.
    |
    */
    'skip_iblocks' => [],

    /*
    |--------------------------------------------------------------------------
    | Names of your own
    |--------------------------------------------------------------------------
    |
    | 'news' => ['table' => 'articles', 'model' => 'Article'].
    |
    */
    'naming' => [
        'mappings' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Columns Bitrix does not have
    |--------------------------------------------------------------------------
    |
    | Columns your project added with migrations of its own. The generator
    | builds models from the iblocks and knows nothing about them, so without
    | this list every regeneration would drop them along with their fields.
    |
    | Table => [column => description]:
    |
    |   'news' => [
    |       'show_on_home' => [
    |           'cast' => 'boolean',
    |           'tab' => 'params',
    |           'after' => 'active',
    |           'field' => "CheckboxField::make('show_on_home')->label('On the home page')",
    |           'column' => "BooleanColumn::make('show_on_home')->label('Home')",
    |       ],
    |   ],
    |
    */
    'extra_fields' => [],

    /*
    |--------------------------------------------------------------------------
    | List columns
    |--------------------------------------------------------------------------
    |
    | List columns come from the layout saved in Bitrix (b_user_option), the
    | same columns the editors saw. Hide some of them, or add columns Bitrix
    | did not show (they appear right after the name). Table => [columns].
    |
    */
    'hidden_list_columns' => [],
    'extra_list_columns' => [],

    /*
    |--------------------------------------------------------------------------
    | "Link to elements" with no iblock
    |--------------------------------------------------------------------------
    |
    | Bitrix lets a link property leave its iblock empty: any element of any
    | iblock may be picked. Eloquent needs one class. Where the property is
    | filled in, the target is found from the values; for empty ones say it
    | here. Iblock code => [property code => target iblock code].
    |
    */
    'element_link_targets' => [],

    /*
    |--------------------------------------------------------------------------
    | What the catch-up sync never overwrites
    |--------------------------------------------------------------------------
    |
    | atrium:bitrix:sync only touches an element edited in Bitrix after it was
    | copied. Columns listed here keep the new site's value even then.
    | Table => [columns].
    |
    */
    'sync_protected' => [],

    'migration' => [
        'chunk_size' => 100,
        'with_timestamps' => true,
        'with_soft_deletes' => true,
    ],
];
