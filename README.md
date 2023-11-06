# A Laravel package for cloning medium-size databases

This package was created as a solution to the problem of cloning medium/large databases between connections, for example pulling in a live database (or a subet of one) to staging or development environments where there is a need to play with real data. Large sql dump files are often too big to import in one go and can hit issues with memory limits (packet size), especially on micro servers. While [this is an old problem](https://stackoverflow.com/questions/13717277/how-can-i-import-a-large-14-gb-mysql-dump-file-into-a-new-mysql-database), there are still surprisingly few straightforward ways to go about it. This package is an attempt at an out-of-the-box solution in the form of a Laravel package which provides a new artisan command for cloning one database to another.

- Attempts to preserve data integrity and avoid foreign-key constraint errors by automatically ordering tables by foreign key dependencies, creating the 'master' tables first, and dependent tables sequentially afterwards, ignoring new rows added to the latter tables since the script was initiated. (Note that mutually-dependent data sets are not supported).
- Avoids php/mysql memory limits by 'chunking' data, i.e. selecting & inserting in configurable batch sizes.

Obviously, running this script is destructive. Please ensure backups of all relevant databases have been taken before starting.

# Instructions

`composer require inigopascall/clone-db`

`php artisan vendor:publish --provider="InigoPascall\CloneDB\Providers\CloneDBProvider"`

### 1. Configure the config file as needed

**app/config/clone-db.php**

Sensible defaults have been pre-set, with examples given in the comments in this file of how to fine-tune things to suit your needs.

### 2. Make sure the source & target databases are configured correctly in your app's config

**app/config/database.php**

```
'my_source_db_connection' => [
	'driver' => 'mysql',
	'host' => env('SOURCE_DB_HOST', '127.0.0.1'),
	'port' => env('SOURCE_DB_PORT', '3306'),
	'database' => env('SOURCE_DB_DATABASE', 'forge'),
	'username' => env('SOURCE_DB_USERNAME', 'forge'),
	'password' => env('SOURCE_DB_PASSWORD', '')
],
'my_target_db_connection' => [
	'driver' => 'mysql',
	'host' => env('TARGET_DB_HOST', '127.0.0.1'),
	'port' => env('TARGET_DB_PORT', '3306'),
	'database' => env('TARGET_DB_DATABASE', 'forge'),
	'username' => env('TARGET_DB_USERNAME', 'forge'),
	'password' => env('TARGET_DB_PASSWORD', '')
]
```

**.env**

```
SOURCE_DB_HOST=localhost
SOURCE_DB_PORT=3306
SOURCE_DB_DATABASE=source_database_name
SOURCE_DB_USERNAME=mysqlusername
SOURCE_DB_PASSWORD=mysluserpass

TARGET_DB_HOST=localhost
TARGET_DB_PORT=3306
TARGET_DB_DATABASE=target_database_name
TARGET_DB_USERNAME=mysqlusername
TARGET_DB_PASSWORD=mysqluserpass
```

# 3. run the command with the source & target connection names as parameters. Check & confirm output.

`php artisan db:clone my_source_db_connection my_target_db_connection`


## Cloning a remote database via SSH

SSH config for your server pointing to the private key location

**~/.ssh/config**

```
Host remoteservername
Hostname {server ip address}
User username
IdentityFile ~/.ssh/remoteservernameprivatekey
```

Open SSH connection with `ssh remoteservername -N -L 13306:localhost:3306 username@{server ip address}`

`app/config/database.php` entry for the connection would then be set with localhost and the local port number specified in the above command (13306):

```
'my_source_db_connection' => [
    'driver' => 'mysql',
    'host' => env('SOURCE_DB_HOST', '127.0.0.1'),
    'port' => env('SOURCE_DB_PORT', '13306'),
    'database' => env('SOURCE_DB_DATABASE', 'remotedb'),
    'username' => env('SOURCE_DB_USERNAME', 'remotedbuser'),
    'password' => env('SOURCE_DB_PASSWORD', 'remotedbpass')
]
```
