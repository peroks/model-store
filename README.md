# Model Store: Permanent data store for models.

**IMPORTANT**: This project is still **experimental** and can change without notice!

## Reason why

The purpose of this package is to store models **permanently**. Currently,
JSON files and MySql databases (mysqli and pdo-mysql) are supported.

The Model Store is an abstraction layer on top of the permanent store.
It automatically creates **JSON files** or **database schemas** for you based
on your models. They are also updated when your models change.

The Model Store provides a **simple interface** for reading model from and
writing models to the permanents store.    

## How to use

### Connecting to a model store

In order to connect to a model store, you first create a new store instance.
Currently, three different stores are supported:
- `JsonStore`: JSON file store
- `PdoStore`: PDO MySql store
- `MysqlStore`: Native MySql (mysqli) store

You can also create your own implementation of the [StoreInterface](src/StoreInterface.php).

#### JSON file store

Storing your models in a JSON file is only recommended for a very limited amount
of data, no more than a few MB. For each PHP request the complete JSON file is 
loaded into memory, and will require more and more **rem** and **cpu** as the
file grows.

To connect to a JSON file store, just provide the path and file name
to the JSON file which contains your models. If the file does not
exist, it will be crated.

    use Peroks\Model\Store\JsonStore;
    $store = new JsonStore( '/<path>/<filename>.json' );

#### PDO MySql store

To connect to a PDO MySql store, just provide the connection info for the MySQL
database. Read more about [MySql connections](https://www.php.net/manual/en/mysqli.quickstart.connections.php).
If the database with the given name does not exist, an **empty** database will be created.

All connection properties below are required, except for `port` and `socket`,
which are mutually exclusive. If the host is `localhost`, a `socket` is expected.
The connection info can be an `array` or an `object`.

    use Peroks\Model\Store\PdoStore;
    $store = new PdoStore( [
        'host'   => 'localhost|<host name>|<ip address>',
        'name'   => '<db name>',
        'user'   => '<db username>',
        'pass'   => '<db password>',
        'port'   => '<port>',
        'socket' => '<socket>',
    ] );

#### Native MySql (mysqli) store

You can also connect to a MySql database using the native `mysqli` driver
if you prefer. Just replace the store class `PdoStore` with `MysqlStore`.

    use Peroks\Model\Store\MysqlStore;
    $store = new MysqlStore( [
        'host'   => 'localhost|<host name>|<ip address>',
        'name'   => '<db name>',
        'user'   => '<db username>',
        'pass'   => '<db password>',
        'port'   => '<port>',
        'socket' => '<socket>',
    ] );

### The Store interface

You can of course access a JSON file or MySql database directly, but the
recommended way it to create a `Store instance` and use the shared
[StoreInterface](src/StoreInterface.php).

## Installing

You need **composer** to download and install this
[package](https://packagist.org/packages/peroks/model-store).
Just run `composer require peroks/model-store` in your project.
