# Model Store: Permanent data store for models.

## Reason why

The purpose of this package is to store models **permanently**. Currently,
**JSON files** and **MySql databases** (mysqli and pdo-mysql) are supported.

The Model Store is an abstraction layer on top of the permanent store.
It automatically creates **JSON files** or **database schemas** for you based
on your models.

The Model Store provides a simple [interface](src/StoreInterface.php) for
reading models from and writing models to the permanents store.

## How to use

### The Store interface

You can of course access a database directly, but the
recommended way it to create a `Store instance` and use the
[StoreInterface](src/StoreInterface.php).

### Connecting to a model store

In order to connect to a model store, you must create a new **model store instance**.
Currently, these model stores are supported:

- `FileStore`: JSON file store
- `MysqlStore`: Native MySql store (mysqli)
- `MysqlJsonStore`: Native MySql (mysqli) store with JSON support
- `PdoStore`: PDO MySql store (pdo-mysqli)
- `PdoJsonStore`: PDO MySql (pdo-mysqli) store with JSON support

The JsonStore classes stores models in MySQL `json` columns with
additional columns for indices and constraints.

You can also create your own implementation of the
[StoreInterface](src/StoreInterface.php).

#### File store

Storing your models in a JSON file is only recommended for **very small** data
stores, no more than a few MBs. It's intended for use in **development**
and **rapid prototyping**, but not in **production**. For each PHP request
the complete JSON file is loaded into memory, and it will consume more
and more **ram** and **cpu** as the file grows.

To connect to a JSON file store, just provide the full path and file name
to the JSON file which contains your models. If the file does not
exist, it will be created.

```php
use Peroks\Model\Store\FileStore;
$store = new FileStore( '/<path>/<filename>.json' );
```

#### PDO MySql store

To connect to a PDO MySql store, just provide the
[connection info](https://www.php.net/manual/en/mysqli.quickstart.connections.php)
for the MySQL database.

All connection properties below are required, except for `port` and `socket`,
which are mutually exclusive. If the host is `localhost`, a `socket` is expected.
The connection info can be an `array` or an `object`.

```php
use Peroks\Model\Store\PdoStore;
$store = new PdoStore( [
    'host'   => 'localhost|<host name>|<ip address>',
    'name'   => '<db name>',
    'user'   => '<db username>',
    'pass'   => '<db password>',
    'port'   => '<port>',
    'socket' => '<socket>',
] );
```

Alternatively, you can use the `PdoJsonStore` class, which stores the
models in MySql `json` columns. Additional columns are only created for
primary, index and constraint properties.

#### Native MySql (mysqli) store

You can also connect to a MySql database using the native `mysqli` driver
if you prefer. Just replace the store class `PdoStore` with `MysqlStore`.

```php
use Peroks\Model\Store\MysqlStore;
$store = new MysqlStore( [
    'host'   => 'localhost|<host name>|<ip address>',
    'name'   => '<db name>',
    'user'   => '<db username>',
    'pass'   => '<db password>',
    'port'   => '<port>',
    'socket' => '<socket>',
] );
```

Alternatively, you can use the `MysqlJsonStore` class, which stores the
models in MySql `json` columns. Additional columns are only created for
primary, index and constraint properties.

### Creating and Updating database schemas

Before you can start using a database store, you need to build the
**database schema** based on your models. Fortunately, you don't need to do this
manually. To create (and update) your database schema, call the `build()`
method with an array of the model **class names** that you want to store.
This will also create a new database if it doesn't already exist.

You should only call the `build()` method when you create a new model store or
when your models have changed. Do **not** call `build()` every time you connect to
the store. You can use `info( 'ready' )` to check if the db is ready for use or not.

```php
use Peroks\Model\Store\MysqlStore;
$store = new MysqlStore( $connection );

if ( ! $store->info( 'ready' ) ) {
    $store->build( [
        MyModelOne::class,
        MyModelTwo::class,
        MyModelThree::class,
    ] );
}

```

If a model contains [sub-models](https://github.com/peroks/model#nested-models),
database tables are automatically created for the sub-models.
You do not need to include sub-models in the `build()` method.
So, if you have a hierarchy of models, you only need to provide
your **top-level** models.

## Caching

You can cache query results in memory with the special `Cache` store.
The constructor takes another store instance as the only argument.
Calls to `has()`, `get()`, `list()` and `filter()` return cached results when available.
The cache is cleared every time `set()`, `delete()` or `build()` are called.

```php
use Peroks\Model\Store\Cache;
use Peroks\Model\Store\PdoJsonStore;

$store = new Cache( new PdoJsonStore( $connection ) );
$model = $store->get( SomeClass::class, 'someId' );
$model = $store->get( SomeClass::class, 'someId' ); // Cached result.
```

## Examples

The below examples assume that a model store instance has already been created,
i.e. like this

```php
use Peroks\Model\Store\MysqlStore;
$store = new MysqlStore( [
    'host'   => 'localhost|<host name>|<ip address>',
    'name'   => '<db name>',
    'user'   => '<db username>',
    'pass'   => '<db password>',
    'port'   => '<port>',
    'socket' => '<socket>',
] );
```

All methods accept the **model class name** as the first argument.
The only exception is `set`, since the class name can be derived from the
model instance.

#### Check if a model exists in the store

```php
$exists = $store->has( MyModelOne::class, 123 );
$exists = $store->has( MyModelOne::class, 'abc' );
```

#### Get a single model by id

```php
$stored_model = $store->get( MyModelOne::class, 123 );
$stored_model = $store->get( MyModelOne::class, 'abc' );
```

#### Get an array of models by their ids

```php
$some_models = $store->list( MyModelOne::class, [123, 'abc', 'xyz'] );
$all_models  = $store->list( MyModelOne::class );
```

If no ids are provided, all models of the given class are returned.

#### Get models by their property values

The `filter` method returns all models of the given **class name** matching
pairs of property ids and their values, i.e.

```php
$some_artists = $store->filter( Artist::class, [
    'first_name' => 'Tom',
    'last_name'  => 'Waits',
] );

$all_artists = $store->filter( Artist::class );
```

If no property filter is provided, all models of the given class are returned
(same as `list()`).

#### Add or update a model in the store

```php
$model = new Artist( [ 'first_name' => 'Tom', 'last_name' => 'Waits' ] );
$store->set( $model );
```

#### Delete a model from the store

```php
$store->delete( MyModelOne::class, 123 );
$store->delete( MyModelOne::class, 'abc' );
```

## Installing

You need **composer** to download and install this
[package](https://packagist.org/packages/peroks/model-store).
Just run `composer require peroks/model-store` in your project.
