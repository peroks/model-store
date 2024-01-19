<?php
/**
 * Class for storing and retrieving models from a SQL database.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 *
 * @noinspection PhpComposerExtensionStubsInspection, SqlDialectInspection
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

use Peroks\Model\Property;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;
use Throwable;

abstract class SqlJsonPure implements StoreInterface {

	/**
	 * @var object $db The database object.
	 */
	protected object $db;

	/**
	 * @var string The database name for this store.
	 */
	protected string $dbname;

	/**
	 * @var array An array of prepared query statements.
	 */
	protected array $prepared = [];

	/**
	 * Constructor.
	 *
	 * @param array|object $connect Connections parameters: host, user, pass, name, port, socket.
	 */
	public function __construct( array | object $connect ) {
		$this->dbname = $connect->name;
		$this->connect( (object) $connect );
	}

	/* -------------------------------------------------------------------------
	 * Database abstraction layer
	 * ---------------------------------------------------------------------- */

	/**
	 * Creates a database connection.
	 *
	 * @param object $connect Connections parameters: host, user, pass, name, port, socket.
	 *
	 * @return bool True on success, null on failure to create a connection.
	 */
	abstract protected function connect( object $connect ): bool;

	/**
	 * Executes a single query and returns the number of affected rows.
	 *
	 * @param string $query A query statement.
	 *
	 * @return int The number of affected rows.
	 */
	abstract protected function exec( string $query ): int;

	/**
	 * Executes a single query and returns the result.
	 *
	 * @param string $query A query statement.
	 * @param array $values The values of a prepared query statement.
	 *
	 * @return array[] The query result.
	 */
	abstract protected function query( string $query, array $values = [] ): array;

	/**
	 * Prepares a statement for execution and returns a prepared query object.
	 *
	 * @param string $query A valid sql statement template.
	 *
	 * @return object A prepared query object.
	 */
	abstract protected function prepare( string $query ): object;

	/**
	 * Executes a prepared select query and returns the result.
	 *
	 * @param object $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return array[] An array of database rows.
	 */
	abstract protected function select( object $prepared, array $values = [] ): array;

	/**
	 * Executes a prepared insert, update or delete query and returns the number of affected rows.
	 *
	 * @param object $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return int The number of updated rows.
	 */
	abstract protected function update( object $prepared, array $values = [] ): int;

	/**
	 * Initiates a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	abstract protected function beginTransaction(): bool;

	/**
	 * Commits a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	abstract protected function commit(): bool;

	/**
	 * Rolls back a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	abstract protected function rollBack(): bool;

	/**
	 * Quotes db, table, column and index names.
	 *
	 * @param string $name The name to quote.
	 *
	 * @return string The quoted name.
	 */
	abstract protected function name( string $name ): string;

	/**
	 * If necessary, escapes and quotes a variable before use in a sql statement.
	 *
	 * @param mixed $value The variable to be used in a sql statement.
	 *
	 * @return mixed A safe variable to be used in a sql statement.
	 */
	abstract protected function escape( mixed $value ): mixed;

	/* -------------------------------------------------------------------------
	 * Retrieving models.
	 * ---------------------------------------------------------------------- */

	/**
	 * Checks if a model with the given id exists in the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return bool True if the model exists, false otherwise.
	 */
	public function has( string $class, int | string $id ): bool {
		$table = $this->getTableName( $class );

		// Set prepared query.
		if ( empty( $this->prepared[ $table ]['has'] ) ) {
			$query = vsprintf( 'SELECT 1 FROM %s WHERE %s = ? LIMIT 1', [
				$this->name( $table ),
				$this->name( 'id' ),
			] );

			$this->prepared[ $table ]['has'] = $this->prepare( $query );
		}

		// Get and execute prepared query.
		$prepared = $this->prepared[ $table ]['has'];
		$result   = $this->select( $prepared, [ $id ] );
		return (bool) $result;
	}

	/**
	 * Gets a model matching the given id from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return ModelInterface|null The matching model or null if not found.
	 */
	public function get( string $class, int | string $id ): ModelInterface | null {
		$table = $this->getTableName( $class );

		// Set prepared query.
		if ( empty( $this->prepared[ $table ]['get'] ) ) {
			$query = vsprintf( 'SELECT * FROM %s WHERE %s = ?', [
				$this->name( $table ),
				$this->name( 'id' ),
			] );

			$this->prepared[ $table ]['get'] = $this->prepare( $query );
		}

		// Get and execute prepared query.
		$prepared = $this->prepared[ $table ]['get'];
		$rows     = $this->select( $prepared, [ $id ] );

		if ( $rows ) {
			$row  = current( $rows );
			$data = json_decode( $row['model'], true );
			return $this->join( $class, $data );
		}

		return null;
	}

	/**
	 * Gets a list of models matching the given ids from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int[]|string[] $ids An array of model ids.
	 *
	 * @return ModelInterface[] An array of matching models.
	 */
	public function list( string $class, array $ids = [] ): array {
		if ( count( $ids ) === 1 ) {
			$model = $this->get( $class, current( $ids ) );
			return [ $model ];
		}

		$table = $this->name( $this->getTableName( $class ) );
		$query = "SELECT * FROM {$table}";

		if ( $ids ) {
			$primary = $this->name( 'id' );
			$values  = join( ', ', array_fill( 0, count( $ids ), '?' ) );
			$query   = "SELECT * FROM {$table} WHERE {$primary} IN ({$values})";
		}

		$rows = $this->query( $query, $ids );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			$data = json_decode( $row['model'], true );
			return $this->join( $class, $data );
		}, $rows );
	}

	/**
	 * Gets a filtered list of models from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param array $filter Key/value pairs to match model property values.
	 *
	 * @return ModelInterface[] An array of models.
	 */
	public function filter( string $class, array $filter = [] ): array {
		if ( $filter ) {
			$scalar_filter = array_filter( $filter, 'is_scalar' );
			$rest_filter   = array_diff_key( $filter, $scalar_filter );

			if ( $scalar_filter ) {
				$json  = $this->escape( Utils::encode( $scalar_filter ) );
				$sql[] = sprintf( 'JSON_CONTAINS(model, %s)', $json );
			}

			foreach ( $rest_filter as $key => $value ) {
				if ( is_array( $value ) ) {
					// Json values don't support comparison with the "IN" operator.
					$sql[] = sprintf( 'JSON_CONTAINS(JSON_ARRAY(%s), JSON_EXTRACT(model, "$.%s"))', $this->escape( $value ), $key );
				} elseif ( $value instanceof Range ) {
					// Json values don't support comparison with the "BETWEEN" operator.
					$sql[] = sprintf( 'JSON_EXTRACT(model, "$.%s") >= %s', $key, $this->escape( $value->from ) );
					$sql[] = sprintf( 'JSON_EXTRACT(model, "$.%s") <= %s', $key, $this->escape( $value->to ) );
				}
			}
		}

		$table = $this->name( $this->getTableName( $class ) );
		$query = "SELECT * FROM {$table}";

		if ( isset( $sql ) ) {
			$sql   = join( ' AND ', $sql );
			$query = "SELECT * FROM {$table} WHERE {$sql}";
		}

		$rows = $this->query( $query );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			$data = json_decode( $row['model'], true );
			return $this->join( $class, $data );
		}, $rows );
	}

	/* -------------------------------------------------------------------------
	 * Updating and deleting models
	 * ---------------------------------------------------------------------- */

	/**
	 * Saves and validates a model in the data store.
	 *
	 * @param ModelInterface $model The model to store.
	 *
	 * @return ModelInterface The stored model.
	 */
	public function set( ModelInterface $model ): ModelInterface {
		$model->validate( true );
		$this->beginTransaction();

		try {
			$this->setSingle( $model );
			$this->commit();
		} catch ( Throwable $e ) {
			$this->rollBack();
			throw $e;
		}

		return $model;
	}

	/**
	 * Internally saves and validates a model in the data store.
	 *
	 * Same as external set(), but without model validation and transactions.
	 *
	 * @param ModelInterface $model The model to store.
	 *
	 * @return ModelInterface The stored model.
	 */
	protected function setSingle( ModelInterface $model ): ModelInterface {
		$this->setMulti( [ $model ] );
		return $model;
	}

	/**
	 * @param ModelInterface[] $models
	 *
	 * @return ModelInterface[]
	 */
	protected function setMulti( array $models ): array {
		if ( empty( $model = current( $models ) ) ) {
			return $models;
		}

		$class   = $model::class;
		$columns = array_map( [ $this, 'name' ], [ 'id', 'model' ] );

		// Get the escaped values for multiple models.
		$values = array_map( function( ModelInterface $model ): string {
			$data = $this->split( $model );
			$json = $this->escape( Utils::encode( $data ) );
			return sprintf( '(%s, %s)', $this->escape( $model->id() ), $json );
		}, $models );

		// Assign insert values to update columns.
		// ToDo: This syntax is deprecated beginning with MySQL 8.0.20, use an alias for the value rows instead.
		$update = array_map( function( string $column ): string {
			return "{$column} = VALUES({$column})";
		}, $columns );

		$table   = $this->name( $this->getTableName( $class ) );
		$columns = join( ', ', $columns );
		$values  = join( ', ', $values );
		$update  = join( ', ', $update );

		$sql[] = "INSERT INTO {$table} ({$columns})";
		$sql[] = "VALUES {$values}";
		$sql[] = "ON DUPLICATE KEY UPDATE {$update}";

		$query = join( "\n", $sql );
		$this->exec( $query );

		return $models;
	}

	/**
	 * /**
	 * Deletes a model from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return bool True if the model existed, false otherwise.
	 */
	public function delete( string $class, int | string $id ): bool {
		$this->beginTransaction();

		try {
			$result = $this->deleteSingle( $class, $id );
			$this->commit();
			return $result;
		} catch ( Throwable $e ) {
			$this->rollBack();
			throw $e;
		}
	}

	/**
	 * Deletes a model from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return bool True if the model existed, false otherwise.
	 */
	protected function deleteSingle( string $class, int | string $id ): bool {
		$table = $this->getTableName( $class );

		if ( empty( $this->prepared[ $table ]['delete'] ) ) {
			$query = vsprintf( 'DELETE FROM %s WHERE %s = ?', [
				$this->name( $table ),
				$this->name( $class::idProperty() ),
			] );

			$this->prepared[ $table ]['delete'] = $this->prepare( $query );
		}

		$prepared = $this->prepared[ $table ]['delete'];
		return (bool) $this->update( $prepared, [ $id ] );
	}

	/* -------------------------------------------------------------------------
	 * Data store handling
	 * ---------------------------------------------------------------------- */

	/**
	 * Builds a data store if necessary.
	 *
	 * @param array $models The models to add to the data store.
	 * @param array $options An assoc array of options.
	 *
	 * @return bool
	 */
	public function build( array $models, array $options = [] ): bool {
		$models = $this->getAllModels( $models );
		return (bool) $this->buildDatabase( $models );
	}

	/**
	 * Flushes model data to permanent storage if necessary.
	 *
	 * @return bool True if data changes exists and were saved, false otherwise.
	 */
	public function flush(): bool {
		return true;
	}

	/* -------------------------------------------------------------------------
	 * Create, drop and build databases
	 * ---------------------------------------------------------------------- */

	/**
	 * Generates a query to create a database of the given name.
	 *
	 * @param string $name The database name.
	 *
	 * @return string Sql query to create a database.
	 */
	protected function createDatabaseQuery( string $name ): string {
		$name  = $this->name( $name );
		$sql[] = "CREATE DATABASE IF NOT EXISTS {$name}";
		$sql[] = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		return join( "\n", $sql );
	}

	/**
	 * Creates a database with the given name.
	 *
	 * @param string $name The database name.
	 *
	 * @return int The number affected databases.
	 */
	protected function createDatabase( string $name ): int {
		$query = $this->createDatabaseQuery( $name );
		return $this->exec( $query );
	}

	/**
	 * Generates a query to delete the database with the given name.
	 *
	 * @param string $name The name of the database to delete.
	 *
	 * @return string Sql query to delete a database.
	 */
	protected function dropDatabaseQuery( string $name ): string {
		return sprintf( 'DROP DATABASE IF EXISTS %s', $this->name( $name ) );
	}

	/**
	 * Deletes the database with the given name.
	 *
	 * @param string $name The name of the database to delete.
	 *
	 * @return int The number affected databases.
	 */
	protected function dropDatabase( string $name ): int {
		$query = $this->dropDatabaseQuery( $name );
		return $this->exec( $query );
	}

	/**
	 * Creates or updates database tables for the given models and their sub-models.
	 *
	 * @param ModelInterface[]|string[] $classes An array of model class names.
	 *
	 * @return int The number of created or updated tables.
	 */
	protected function buildDatabase( array $classes ): int {
		$count = 0;

		// Create or alter model tables (columns + indexes).
		foreach ( $classes as $name ) {
			$count += $this->createTable( $name );
		}

		return $count;
	}

	/**
	 * Creates a database table for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model to create a database table for.
	 *
	 * @return int The number of created database tables.
	 */
	protected function createTable( string $class ): int {
		$primary  = $class::idProperty();
		$property = $class::getProperty( $primary );
		$columns  = [
			[
				'name'     => 'id',
				'type'     => $this->getColumnType( $property ),
				'required' => true,
				'primary'  => true,
			],
			[
				'name'     => 'model',
				'type'     => 'json',
				'required' => true,
			],
		];

		// Create columns.
		$sql = array_map( function( array $column ): string {
			$required = $column['required'] ?? false;
			$primary  = $column['primary'] ?? false;

			return join( ' ', array_filter( [
				$this->name( $column['name'] ),
				$column['type'],
				$required ? 'NOT NULL' : null,
				$primary ? 'PRIMARY KEY' : null,
			] ) );
		}, $columns );

		$sql   = join( ', ', $sql );
		$table = $this->name( $this->getTableName( $class ) );
		$query = sprintf( 'CREATE TABLE IF NOT EXISTS %s (%s)', $table, $sql );

		return $this->exec( $query );
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Gets the table name corresponding to the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return string The corresponding table name.
	 */
	protected function getTableName( string $class ): string {
		return str_replace( '\\', '_', $class );
	}

	/**
	 * Gets the correct column data type for the given model property.
	 *
	 * @param Property|array $property The model property.
	 *
	 * @return string The column data type.
	 */
	protected function getColumnType( Property | array $property ): string {
		$type = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;
		$max  = $property[ PropertyItem::MAX ] ?? 255;

		return match ( $type ) {
			PropertyType::UUID    => 'char(36)',
			PropertyType::INTEGER => 'bigint(20)',
			PropertyType::STRING  => sprintf( 'varchar(%d)', $max ),
		};
	}

	/**
	 * Replaces sub-model ids with the sub-model itself.
	 *
	 * @param class-string<ModelInterface> $class The class name to join.
	 * @param array $data The model data.
	 */
	protected function join( string $class, array $data ): ModelInterface {
		$properties = $class::properties();

		foreach ( $data as $id => &$value ) {
			$property = $properties[ $id ];

			if ( $child = $property[ PropertyItem::MODEL ] ?? null ) {
				if ( $value && $child::idProperty() ) {
					$value = match ( $property[ PropertyItem::TYPE ] ) {
						PropertyType::OBJECT => $this->get( $child, $value ),
						PropertyType::ARRAY  => $this->list( $child, $value ),
					};
				}
			}
		}

		return new $class( $data );
	}

	/**
	 * Splits a model into separate sub-models and stores them.
	 *
	 * @param ModelInterface $model The model instance to be stored.
	 *
	 * @return array The model data to be stored on the db.
	 */
	protected function split( ModelInterface $model ): array {
		$properties = $model::properties();
		$result     = [];

		foreach ( $model as $id => $value ) {
			if ( is_null( $value ) ) {
				continue;
			}

			$property = $properties[ $id ];

			if ( $class = $property[ PropertyItem::MODEL ] ?? null ) {
				if ( $class::idProperty() ) {
					$value = match ( $property[ PropertyItem::TYPE ] ) {
						PropertyType::OBJECT => $this->setSingle( $value )->id(),
						PropertyType::ARRAY  => array_map( fn( $item ) => $item->id(), $this->setMulti( $value ) ),
					};
				}
			}
			$result[ $id ] = $value;
		}

		return $result;
	}

	/**
	 * Extract all sub-models from the given models.
	 *
	 * @param class-string<ModelInterface>[] $models An array of model class names.
	 * @param class-string<ModelInterface>[] $result An array of all model and sub-model class names.
	 *
	 * @return class-string<ModelInterface>[] An array of all model and sub-model class names.
	 */
	protected function getAllModels( array $models, array &$result = [] ): array {
		$result = $result ?: $models;

		foreach ( $models as $class ) {
			foreach ( $class::properties() as $property ) {
				$foreign = $property[ PropertyItem::FOREIGN ] ?? null;
				$model   = $property[ PropertyItem::MODEL ] ?? $foreign;

				if ( Utils::isModel( $model ) && $model::idProperty() ) {
					if ( empty( in_array( $model, $result, true ) ) ) {
						$result[] = $model;
						$this->getAllModels( [ $model ], $result );
					}
				}
			}
		}

		return $result;
	}
}
