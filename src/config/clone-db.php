<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clone Database tables to another database.
    |--------------------------------------------------------------------------
    |
    */

    /**
     * Default: insert in batches of this size
     */
    'batch_size' => 1000,

    /**
     * Otherwise set per table
     */
    'table_specific_batch_sizes' => [
//        'my_table' => 2500,
    ],

    /**
     * Chunk method requires an `orderBy` column, typically the `id` or primary key auto-incrementing column. Will default to `id` or otherwise `created_at`. Set overrides here.
     */
    'table_order_by_cols' => [
//        'my_table' => 'last_touched_at'
    ],

    /**
     * Don't clone these tables
     */
    'exclude_tables' => [
//        'excluded_table'
    ],

    /**
     * Only clone these tables
     */
    'include_only_tables' => [
//        'included_table'
    ],

    /**
     * If set to true, then only the tables being cloned, as specified by `exclude_tables` and/or `include_only_tables` above, will be dropped from the target DB. Note: you may need to set `disable_foreign_key_checks = true`. Leave as false to completely wipe the target database.
     */
    'only_drop_cloned_tables' => false,

    /**
     * Batch inserts are wrapped in try/catch. Allow this many failures before terminating the script completely. 0 = terminate on first insertion failure.
     */
    'max_allowed_errors' => 0,

    /**
     * Disable foreign key constraints. Not recommended.
     */
    'disable_foreign_key_checks' => false,
];
