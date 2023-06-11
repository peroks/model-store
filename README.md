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

### The Store interface

You can of course access a JSON file or MySql database directly, but the
recommended way it to create a `Store instance` and use the shared
[StoreInterface](src/StoreInterface.php).

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

### Creating and Updating database schemas

Before you can start using a database model store, you need to build the
`database schema` based on your models. Fortunately, you don't have to do this
manually. To create (and update) your database schema, you call the `build`
method with an array of all the models you want to store.

    use Peroks\Model\Store\MysqlStore;
    $store = new MysqlStore( $connection );
    $store->build( [
        MyModelOne::class,
        MyModelTwo::class,
        MyModelThree::class,
    ] );

Is a model contains [sub-models](https://github.com/peroks/model#nested-models),
database tables are automatically created for the sub-models.
You do not have to include sub-models in the `build` method.
So, if you have a hierarchy of models, you only have to provide
your **top-level** models.

You should only call the `build` method when you create a new model store or
when your models have changed. Do **not** call `build` every time you connect to
the store.

### Examples

All examples below assume that a model store instance has already been created,
i.e. with

    use Peroks\Model\Store\MysqlStore;
    $store = new MysqlStore( [
        'host'   => 'localhost|<host name>|<ip address>',
        'name'   => '<db name>',
        'user'   => '<db username>',
        'pass'   => '<db password>',
        'port'   => '<port>',
        'socket' => '<socket>',
    ] );

#### Check if a model exists in the store

    $exists = $store->exists( MyModelOne::class, 123 );
    $exists = $store->exists( MyModelOne::class, 'abc' );

#### Get a single model by id

    $stored_model = $store->get( MyModelOne::class, 123 );
    $stored_model = $store->get( MyModelOne::class, 'abc' );

#### Get a list of models by their ids

    $stored_models = $store->list( MyModelOne::class, [123, 'abc', 'xyz'] );

#### Get models by their property values

The `filter` method returns all models of the given **class name** matching
pairs of property ids and their values, i.e.

    $stored_models = $store->filter( Artist::class, [
        'first_name' => 'Tom',
        'last_name'  => 'Waits',
    ] );

#### Get all models by their class name

    $stored_models = $store->all( Artist::class );

#### Add or update a model in the store

    $model = new Artist( [ 'first_name' => 'Tom', 'last_name'  => 'Waits' ] );
    $store->set( $model );

#### Delete a model from the store

    $store->delete( MyModelOne::class, 123 );
    $store->delete( MyModelOne::class, 'abc' );

## Installing

You need **composer** to download and install this
[package](https://packagist.org/packages/peroks/model-store).
Just run `composer require peroks/model-store` in your project.
