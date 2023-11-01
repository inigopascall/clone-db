<?php

namespace InigoPascall\CloneDB\Commands;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\TableCell;

class CloneDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clone:db {from} {to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clone a database with respect to foreign key dependencies and memory limits';

    private $start_time;
    private $errors = [];

    private $tables = [];
    private $table_dependencies = [];

    // source & target db configs as referenced in config/database.php
    private $db_connection_from;
    private $db_connection_to;

    // names of source & target dbs
    private $db_from_name;
    private $db_to_name;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->start_time = Carbon::now();

        $style = new OutputFormatterStyle('yellow', 'default', ['bold']);
        $this->output->getFormatter()->setStyle('highlight', $style);

        if($this->validateConnections() && $this->validateTables())
        {
            $confirm = $this->confirm("<highlight>This will completely destroy and overwrite all data in the target database '$this->db_to_name'. Are you SURE you want to continue?</>");

            if($confirm)
            {
                try {
                    $this->dropAllTables();

                    $this->line('The script will now clone the following tables in reverse order of foreign key dependencies: ' . PHP_EOL . PHP_EOL . '● ' . $this->tables->reverse()->implode('table_name', ', ' . PHP_EOL . '● ') . PHP_EOL);

                    $this->line('Building new table schema from source...');
                    $this->output->newLine();

                    if(config('clone-db.disable_foreign_key_checks')){
                        DB::connection($this->db_connection_to)->statement('SET FOREIGN_KEY_CHECKS=0;');
                        $this->warn('Disabled foreign key constraints on target DB.');
                    }

                    $changes = [];

                    // *Main process thread*: create each table and insert data by chunk.

                    $this->tables->reverse()->each(function($table) use (&$changes)
                    {
                        $table_name = $table['table_name'];

                        // Fetch the source table structure
                        $source_tbl_structure = DB::connection($this->db_connection_from)->select('SHOW CREATE TABLE ' . $table_name);

                        // Create the table in the target database using the fetched structure
                        DB::connection($this->db_connection_to)->statement(
                            $source_tbl_structure[0]->{'Create Table'}
                        );

                        $this->info("✓ Created new table: '$table_name'. Beginning data insertion.");

                        // check if row count has changed; give warning about difference
                        $current_count = DB::connection($this->db_connection_from)->table($table_name)->count();
                        $initial_count = $table['record_count'];
                        $diff = $current_count - $initial_count;

                        if($diff !== 0)
                        {
                            $this->output->newLine();
                            $this->warn("The row count has changed in '$table_name' since the cloning process started (Difference of: $diff rows). Any new rows will be ignored.");
                            $changes[] = [
                                'table' => $table_name,
                                'difference' => $diff
                            ];
                        }

                        if(!$this->insertData($table)){
                            throw new \Exception("data insertion on table '$table_name' failed.");
                        }
                    });

                    if(config('clone-db.disable_foreign_key_checks')){
                        DB::connection($this->db_connection_to)->statement('SET FOREIGN_KEY_CHECKS=1;');
                    }

                    if(!empty($changes))
                    {
                        $this->warn('The following tables changed their row count since the cloning process was started. Any new rows in the source database have been ignored.');
                        $headers = [
                            new TableCell("<highlight>Table</>"),
                            new TableCell("<highlight>Difference</>"),
                        ];
                        $this->table($headers, $changes);
                    }

                    $now = Carbon::now();

                    $options = [
                        'join' => ', ',
                        'parts' => 3,
                        'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                    ];

                    $readable_duration = $now->diffForHumans($this->start_time, $options);

                    $this->output->newLine();
                    $this->info("★ Operation complete in $readable_duration. ★");
                    $this->output->newLine();

                } catch (\Exception $e) {

                    $this->error('Database cloning failed: ' . substr($e->getMessage(), 0, 500));
                }

            }else {
                $this->error('Process terminated by user.');
            }
        }

        return 1;
    }

    /**
     * Validate database configurations
     * @return bool
     */
    private function validateConnections()
    {
        $this->db_connection_from = $this->argument('from');
        $this->db_connection_to = $this->argument('to');

        // validate source config is set correctly in config/database.php
        if(!config("database.connections.$this->db_connection_from"))
        {
            $this->error('Source DB connection does not exist: ' . $this->db_connection_from);
            return false;
        }

        // validate source connection actually works
        try {
            DB::connection($this->db_connection_from)->getPdo();
        } catch (\Exception $e) {
            $this->error('Source DB connection is not configured properly: ' . $this->db_connection_from);
            return false;
        }

        $this->db_from_name = config("database.connections.$this->db_connection_from.database");

        $this->output->newLine();
        $this->info('✓ Validated <highlight>SOURCE</> connection:');
        $this->line('● Connection config: `<fg=yellow>' . $this->db_connection_from . '</>`');
        $this->line('● Database name: `<fg=yellow>' . $this->db_from_name . '</>`');

        // validate target config is set correctly in config/database.php
        if(!config("database.connections.$this->db_connection_to"))
        {
            $this->error('Target DB connection does not exist: ' . $this->db_connection_to);
            return false;
        }

        // validate target connection actually works
        try {
            DB::connection($this->db_connection_to)->getPdo();
        } catch (\Exception $e) {
            $this->error('Target DB connection is not configured properly: ' . $this->db_connection_to);
            return false;
        }

        $this->db_to_name   = config("database.connections.$this->db_connection_to.database");

        $this->output->newLine();
        $this->info('✓ Validated <highlight>TARGET</> connection:');
        $this->line('● Connection config: `<fg=yellow>' . $this->db_connection_to . '</>`');
        $this->line('● Database name: `<fg=yellow>' . $this->db_to_name . '</>`');
        $this->output->newLine();

        return true;
    }

    /**
     * Filter tables by included/excluded configs if applicable
     * Validate each table has order by cols, order by foreign key, attach relevant configs
     * @return bool
     */
    private function validateTables()
    {
        // filter tables by included/excluded configs
        $filtered_tables = $this->getSortedTableOrder();

        if(config('clone-db.include_only_tables')){
            $filtered_tables = array_values(array_intersect($filtered_tables, config('clone-db.include_only_tables')));
        }
        if(config('clone-db.exclude_tables')){
            $filtered_tables = array_values(array_diff($filtered_tables, config('clone-db.exclude_tables')));
        }

        // Ensure each table either has an order by column set in config, or otherwise has an ID or created_at column (mandatory for `orderBy` clause for DB::chunk method)
        $i = 0;
        foreach($filtered_tables as $table)
        {
            $this->tables[$i] = ['table_name' => $table];

            // set in config?
            if(config("clone-db.table_order_by_cols.$table") && Schema::connection($this->db_connection_from)->hasColumn($table, config("clone_-b.table_order_by_cols.$table")))
            {
                $this->tables[$i]['order_by'] = config("clone-db.table_order_by_cols.$table");

                // or does it have an id col?
            }elseif(Schema::connection($this->db_connection_from)->hasColumn($table, 'id'))
            {
                $this->tables[$i]['order_by'] = 'id';

                // or does it have a created_at col?
            }elseif(Schema::connection($this->db_connection_from)->hasColumn($table, 'created_at'))
            {
                $this->tables[$i]['order_by'] = 'created_at';

            }else {
                $this->error("Table '$table' does not contain a valid order by column. Each table needs either an id or created_at column or otherwise one that is specified in the configuration file.");
                return false;
            }

            try{
                // set batch/chunk size for each table according to either config or otherwise revert to defaults
                $this->tables[$i]['batch_size'] = config("clone-db.table_specific_batch_sizes.$table") ?? config('clone-db.batch_size', 1000);

                // take a snapshot of the no. of records at time of initiation. We'll only insert this many records as an attempt to preserve data integrity in case new foreign-key master & dependent records are added during the cloning process. This is not foolproof but adds an additional safeguard.
                $this->tables[$i]['record_count'] = DB::connection($this->db_connection_from)->table($table)->count();

                $this->tables[$i]['max_record'] = DB::connection($this->db_connection_from)->table($table)->max($this->tables[$i]['order_by']);

                $this->tables[$i]['chunk_count'] = ceil($this->tables[$i]['record_count'] / $this->tables[$i]['batch_size']);

            }catch(\Exception $e){

                $this->error("An error occurred while setting configs for table: $table: " . $e->getMessage());
                return false;
            }

            $this->tables[$i]['foreign_table_dependencies'] = $this->table_dependencies[$table];

            $i++;
        }

        $this->tables = collect($this->tables);

        $this->line('The following tables & data will be cloned:');

        $headers = [
            [new TableCell("<highlight>$this->db_connection_from|$this->db_from_name > $this->db_connection_to|$this->db_to_name</>", ['colspan' => 5])],
            [
                'table',
                'orderBy col',
                'batch size',
                'no. records',
                'max',
                'foreign table dependencies'
            ]
        ];

        $this->table($headers, $this->tables->map(function($table){
            return array_filter($table, fn($key) => in_array($key, [
                'table_name',
                'order_by',
                'batch_size',
                'record_count',
                'max_record',
                'foreign_table_dependencies'
            ]), ARRAY_FILTER_USE_KEY
            );
        }));

        return true;
    }

    /**
     * Drop tables in the target database, following confirmation from user
     * @return void
     */
    private function dropAllTables()
    {
        $this->warn('Attempting to drop tables in the target database...');
        $this->output->newLine();

        $tables = config('clone-db.only_drop_cloned_tables') ? $this->tables->pluck('table_name')->toArray() : $this->getSortedTableOrder();

        if(config('clone-db.disable_foreign_key_checks')){
            DB::connection($this->db_connection_to)->statement('SET FOREIGN_KEY_CHECKS=0;');
        }
        foreach ($tables as $table) {
            DB::connection($this->db_connection_to)->statement("DROP TABLE IF EXISTS $table");
            $this->warn('Dropped table: ' . $table);
        }
        if(config('clone-db.disable_foreign_key_checks')){
            DB::connection($this->db_connection_to)->statement('SET FOREIGN_KEY_CHECKS=1;');
        }
        $this->output->newLine();
        $this->info('✓ Tables in the target database have been dropped.');
        $this->output->newLine();
    }

    /**
     * Sort tables by order of foreign-key dependencies
     * @param $reverse
     * @return array
     */
    private function getSortedTableOrder()
    {
        $tables = collect(DB::connection($this->db_connection_from)->select('SHOW TABLES'))->pluck('Tables_in_' . $this->db_from_name);

        $dependency_graph = [];

        foreach($tables as $table)
        {
            $foreign_keys = DB::connection($this->db_connection_from)->getDoctrineSchemaManager()->listTableDetails($table)->getForeignKeys();

            $dependencies = [];

            $foreign_table_dependencies = '';
            foreach ($foreign_keys as $foreign_key){
                $dependencies[] = $foreign_key->getForeignTableName();
                $foreign_table_dependencies .= $foreign_key->getForeignTableName() . ', ';
            }

            $dependency_graph[$table] = $dependencies;

            $this->table_dependencies[$table] = rtrim($foreign_table_dependencies, ', ');
        }

        $visited = [];
        $result = [];

        foreach ($tables as $table) {
            if (!isset($visited[$table]) || !$visited[$table]){
                $this->topologicalSort($table, $visited, $result, $dependency_graph);
            }
        }

        return $result;
    }

    /**
     * @param $table
     * @param $visited
     * @param $result
     * @param $dependency_graph
     * @return void
     */
    private function topologicalSort($table, &$visited, &$result, $dependency_graph)
    {
        $visited[$table] = true;

        foreach ($dependency_graph[$table] as $dependency) {
            if (!isset($visited[$dependency]) || !$visited[$dependency]) {
                $this->topologicalSort($dependency, $visited, $result, $dependency_graph);
            }
        }

        array_unshift($result, $table);
    }

    /**
     * Take data from source table and insert into target in chunks
     * @param $table
     * @return true|void
     */
    private function insertData($table)
    {
        $table_name = $table['table_name'];

        $this->line(
            PHP_EOL .
            '● Chunk size: ' . $table['batch_size'] . PHP_EOL .
            '● Total records: ' . $table['record_count'] . PHP_EOL .
            '● Total chunks: ' . $table['chunk_count'] . PHP_EOL
        );

        $bar = $this->output->createProgressBar($table['chunk_count']);

        $query = DB::connection($this->db_connection_from)->table($table_name);

        // if the source table was empty this will be null
        if($table['max_record']){
            $query->where($table['order_by'], '<=', $table['max_record']);
        }
        $query->orderBy($table['order_by']);

        $query->chunk($table['batch_size'], function($records) use ($table_name, $bar)
        {
            $insertData = [];
            foreach($records as $record){
                $insertData[] = (array) $record;
            }

            try{
                DB::connection($this->db_connection_to)->table($table_name)->insert($insertData);

            }catch (\Exception $e){

                $this->output->newLine();
                $this->error('Insertion failure: ' . substr($e->getMessage(), 0, 150));

                $this->errors[] = $e->getMessage();

                if(count($this->errors) >= config('clone-db.max_allowed_errors')){

                    $this->output->newLine();

                    foreach($this->errors as $error){
                        Log::error($error);
                    }
                    $this->error('Too many errors. Script terminated. Check log file.');

                    exit();
                }
            }
            $bar->advance();
        });

        $bar->finish();

        $this->info(" ✓");
        $this->output->newLine();
        return true;
    }
}
