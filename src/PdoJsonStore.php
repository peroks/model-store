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

use PDO, PDOException, PDOStatement;
use Peroks\Model\ModelData;
use Peroks\Model\Property;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;
use Throwable;

class PdoJsonStore implements StoreInterface {

	/**
	 * @var object<PDO|> $db The database object.
	 */
	protected object $db;

	/**
	 * @var string The database name for this store.
	 */
	protected string $dbname;

	/**
	 * @var array An array of prepared query statements.
	 */
	protected array $queries = [];

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
	protected function connect( object $connect ): bool {
		$args = [
			PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_PERSISTENT       => true,
			PDO::ATTR_EMULATE_PREPARES => false,
		];

		// Delete database.
		if ( false ) {
			$dsn = "mysql:charset=utf8mb4;host={$connect->host}";
			$db  = new PDO( $dsn, $connect->user, $connect->pass, $args );
			$db->exec( $this->dropDatabaseQuery( $connect->name ) );
		}

		try {
			$dsn = "mysql:charset=utf8mb4;host={$connect->host};dbname={$connect->name}";
			$db  = new PDO( $dsn, $connect->user, $connect->pass, $args );
		} catch ( PDOException ) {
			$dsn = "mysql:charset=utf8mb4;host={$connect->host}";
			$db  = new PDO( $dsn, $connect->user, $connect->pass, $args );

			$db->exec( $this->createDatabaseQuery( $connect->name ) );
			$db->exec( "USE {$connect->name}" );
		}

		$this->db = $db;
		return true;
	}

	/**
	 * Executes a single query and returns the number of affected rows.
	 *
	 * @param string $query A query statement.
	 *
	 * @return int The number of affected rows.
	 */
	protected function exec( string $query ): int {
		return $this->db->exec( $query );
	}

	/**
	 * Executes a single query and returns the result.
	 *
	 * @param string $query A query statement.
	 * @param array $values The values of a prepared query statement.
	 *
	 * @return array[] The query result.
	 */
	protected function query( string $query, array $values = [] ): array {
		$prepared = $this->prepare( $query );
		return $this->select( $prepared, $values );
	}

	/**
	 * Prepares a statement for execution and returns a prepared query object.
	 *
	 * @param string $query A valid sql statement template.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function prepare( string $query ): object {
		return $this->db->prepare( $query );
	}

	/**
	 * Executes a prepared select query and returns the result.
	 *
	 * @param object<PDOStatement> $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return array[] An array of database rows.
	 */
	protected function select( object $prepared, array $values = [] ): array {
		$values = array_filter( $values, 'is_scalar' );
		$prepared->execute( $values );
		return $prepared->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Executes a prepared insert, update or delete query and returns the number of affected rows.
	 *
	 * @param object<PDOStatement> $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return int The number of updated rows.
	 */
	protected function update( object $prepared, array $values = [] ): int {
		$prepared->execute( $values );
		return $prepared->rowCount();
	}

	/**
	 * Initiates a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	protected function beginTransaction(): bool {
		return $this->db->beginTransaction();
	}

	/**
	 * Commits a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	protected function commit(): bool {
		return $this->db->commit();
	}

	/**
	 * Rolls back a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	protected function rollBack(): bool {
		return $this->db->rollBack();
	}

	/**
	 * Quotes db, table, column and index names.
	 *
	 * @param string $name The name to quote.
	 *
	 * @return string The quoted name.
	 */
	protected function name( string $name ): string {
		return '`' . trim( trim( $name ), '`' ) . '`';
	}

	/**
	 * If necessary, escapes and quotes a variable before use in a sql statement.
	 *
	 * @param mixed $value The variable to be used in a sql statement.
	 *
	 * @return mixed A safe variable to be used in a sql statement.
	 */
	protected function escape( mixed $value ): mixed {
		return match ( true ) {
			is_string( $value ) => $this->db->quote( $value ),
			is_array( $value )  => join( ', ', array_map( [ $this, 'escape' ], $value ) ),
			is_null( $value )   => 'NULL',
			default             => $value,
		};
	}

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
		$query  = $this->hasRowStatement( $class );
		$result = $this->select( $query, [ $id ] );
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
		$query = $this->getRowStatement( $class );
		$rows  = $this->select( $query, [ $id ] );

		if ( $rows ) {
			$data = $this->joinModel( $class, current( $rows ) );
			return new $class( $data );
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
		if ( $ids ) {
			$filter = [ $class::idProperty() => $ids ];
			return $this->filter( $class, $filter );
		}
		return $this->filter( $class );
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
		$query = $this->filterRowsStatement( $class, $filter );
		$rows  = $this->select( $query, $filter );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			$data = $this->joinModel( $class, $row );
			return new $class( $data );
		}, $rows );
	}

	/**
	 * Replaces sub-model ids with the sub-model itself.
	 *
	 * @param class-string<ModelInterface> $class The class name to join.
	 * @param array $data The model data.
	 */
	protected function joinModel( string $class, array $data ): array {
		$properties = $class::properties();

		foreach ( $data as $key => &$value ) {
			$property = $properties[ $key ];

			if ( $class = $property[ PropertyItem::MODEL ] ?? null ) {
				if ( $value && $class::idProperty() ) {
					$value = match ( $property[ PropertyItem::TYPE ] ) {
						PropertyType::OBJECT => $this->get( $class, $value ),
						PropertyType::ARRAY  => $this->list( $class, json_decode( $value, true ) ),
					};
				}
			}
		}

		return $data;
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
			$this->setMulti( [ $model ] );
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
		$class = get_class( $model );
		$query = $this->has( $class, $model->id() )
			? $this->updateRowStatement( $class )
			: $this->insertRowStatement( $class );

		$values = $this->splitModel( $model );
		$rows   = $this->update( $query, $values );

		return $model;
	}

	/**
	 * @param ModelInterface[] $models
	 *
	 * @return ModelInterface[]
	 */
	protected function setMulti( array $models ): array {
		if ( $models ) {
			$query = $this->setMultiQuery( $models );
			$this->exec( $query );
		}
		return $models;
	}

	/**
	 * Splits a model into separate sub-models and stores them.
	 *
	 * @param ModelInterface $model The model instance to be stored.
	 *
	 * @return array The model data to be stored on the db.
	 */
	protected function splitModel( ModelInterface $model ): array {
		foreach ( $model::properties() as $id => $property ) {
			$result[ $id ] = $this->splitModelValue( $model[ $id ], $property );
		}
		return $result ?? [];
	}

	/**
	 * Gets the value of the given property to be store in a database column.
	 *
	 * @param mixed $value The property value.
	 * @param Property|array $property The model property.
	 *
	 * @return mixed A value to be store in a database column.
	 */
	protected function splitModelValue( mixed $value, Property | array $property ): mixed {
		$type  = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;
		$child = $property[ PropertyItem::MODEL ] ?? null;

		// Short-circuit null values.
		if ( is_null( $value ) ) {
			return null;
		}

		// Transform boolean values.
		if ( is_bool( $value ) ) {
			return (int) $value;
		}

		// Transform objects.
		if ( PropertyType::OBJECT === $type ) {
			if ( $value instanceof ModelInterface ) {
				if ( $value::idProperty() ) {
					return $this->setSingle( $value )->id();
				}
				return Utils::encode( $value->data( ModelData::COMPACT ) );
			}
			return Utils::encode( $value );
		}

		// Transform arrays.
		if ( PropertyType::ARRAY === $type ) {
			if ( Utils::isModel( $child ) ) {
				if ( $child::idProperty() ) {
					$this->setMulti( $value );
					$callback = fn( $item ) => $item->id();
				} else {
					$callback = fn( $item ) => $item->data( ModelData::COMPACT );
				}
				return Utils::encode( array_map( $callback, $value ) );
			}
			return Utils::encode( $value );
		}

		return $value;
	}

	/**
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
			$result = $this->deleteInternal( $class, $id );
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
	protected function deleteInternal( string $class, int | string $id ): bool {
		$query = $this->deleteRowStatement( $class );
		return (bool) $this->update( $query, [ $id ] );
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
	 * Select, insert, update and delete rows.
	 * ---------------------------------------------------------------------- */

	/**
	 * Generates a query to check if a model exists in the data store.
	 *
	 * @param string $table The table name.
	 * @param string $primary The name of the primary key column.
	 * @param string|int|null $value The model id to check for.
	 *
	 * @return string A query to check if a model exists.
	 */
	protected function hasRowQuery( string $table, string $primary, string | int | null $value = null ): string {
		return vsprintf( 'SELECT 1 FROM %s WHERE %s = %s LIMIT 1', [
			$this->name( $table ),
			$this->name( $primary ),
			isset( $value ) ? $this->escape( $value ) : '?',
		] );
	}

	/**
	 * Gets a prepared statement to check if a model exists.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function hasRowStatement( string $class ): object {
		$table = $this->getTableName( $class );

		if ( empty( $this->queries[ $table ]['exists'] ) ) {
			$query = $this->hasRowQuery( $table, $class::idProperty() );
			return $this->queries[ $table ]['exists'] = $this->prepare( $query );
		}
		return $this->queries[ $table ]['exists'];
	}

	/**
	 * Generates a query for selecting a single model from the data store.
	 *
	 * @param string $table The table name.
	 * @param string $primary The name of the primary key column.
	 * @param string|int|null $value The model id to select.
	 *
	 * @return string A query for selecting a single model.
	 */
	protected function getRowQuery( string $table, string $primary, string | int | null $value = null ): string {
		return vsprintf( 'SELECT * FROM %s WHERE %s = %s', [
			$this->name( $table ),
			$this->name( $primary ),
			isset( $value ) ? $this->escape( $value ) : '?',
		] );
	}

	/**
	 * Gets a prepared query for selecting a single model from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function getRowStatement( string $class ): object {
		$table = $this->getTableName( $class );

		if ( empty( $this->queries[ $table ]['select'] ) ) {
			$primary = $class::idProperty();
			$query   = $this->getRowQuery( $table, $primary );
			return $this->queries[ $table ]['select'] = $this->prepare( $query );
		}
		return $this->queries[ $table ]['select'];
	}

	/**
	 * Generates a query for a filtered list of models from the data store.
	 *
	 * @param string $table The table name.
	 * @param array $filter Key/value pairs to match model property values.
	 *
	 * @return string A query for a filtered list of models.
	 */
	protected function filterRowsQuery( string $table, array $filter ): string {
		$table = $this->name( $table );

		if ( empty( $filter ) ) {
			return "SELECT * FROM {$table}";
		}

		$sql = array_map( function( string $key, mixed $value ): string {
			return match ( true ) {
				is_scalar( $value )     => sprintf( '(%s = :%s)', $this->name( $key ), $key ),
				is_array( $value )      => sprintf( '(%s IN (%s))', $this->name( $key ), $this->escape( $value ) ),
				$value instanceof Range => sprintf( '(%s BETWEEN %s AND %s)',
					$this->name( $key ),
					$this->escape( $value->from ),
					$this->escape( $value->to )
				),
			};
		}, array_keys( $filter ), $filter );

		$sql = join( ' AND ', $sql );
		return "SELECT * FROM {$table} WHERE {$sql}";
	}

	/**
	 * Gets a prepared query for a filtered list of models from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param array $filter Key/value pairs to match model property values.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function filterRowsStatement( string $class, array $filter ): object {
		$table = $this->getTableName( $class );
		$query = $this->filterRowsQuery( $table, $filter );
		return $this->prepare( $query );
	}

	/**
	 * Generates a query for inserting a model into the given table.
	 *
	 * @param string $table The table name.
	 * @param Property[]|array[] $properties An array of model properties.
	 * @param array $values The model property values as key/value pairs.
	 *
	 * @return string A query for inserting a model into the given table.
	 */
	protected function insertRowQuery( string $table, array $properties, array $values = [] ): string {
		$columns = [];

		foreach ( $properties as $id => $property ) {
			$value          = $values[ $id ] ?? null;
			$columns[ $id ] = $this->name( $id );
			$values[ $id ]  = isset( $value ) ? $this->escape( $value ) : ':' . $id;
		}

		$table   = $this->name( $table );
		$columns = join( ', ', $columns );
		$values  = join( ', ', $values );

		return "INSERT INTO {$table} ({$columns}) VALUES ({$values})";
	}

	/**
	 * Gets a prepared query for adding a model to the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function insertRowStatement( string $class ): object {
		$table = $this->getTableName( $class );

		if ( empty( $this->queries[ $table ]['insert'] ) ) {
			$properties = $class::properties();
			$query      = $this->insertRowQuery( $table, $properties );
			return $this->queries[ $table ]['insert'] = $this->prepare( $query );
		}
		return $this->queries[ $table ]['insert'];
	}

	/**
	 * Generates a query for updating a model.
	 *
	 * @param string $table The table name.
	 * @param Property[]|array[] $properties An array of model properties.
	 * @param string $primary The name of the primary key column.
	 * @param array $values The model property values as key/value pairs.
	 *
	 * @return string A query for updating a model.
	 */
	protected function updateRowQuery( string $table, array $properties, string $primary, array $values = [] ): string {
		$sql = [];

		foreach ( $properties as $id => $property ) {
			if ( $id !== $primary ) {
				$value = $values[ $id ] ?? null;
				$value = isset( $value ) ? $this->escape( $value ) : ':' . $id;
				$name  = $this->name( $id );
				$sql[] = "{$name} = {$value}";
			}
		}

		$value = $values[ $primary ] ?? null;
		$key   = isset( $value ) ? $this->escape( $value ) : ':' . $primary;

		$table   = $this->name( $table );
		$primary = $this->name( $primary );
		$sql     = "\n\t" . join( ",\n\t", $sql ) . "\n";

		return "UPDATE {$table} SET {$sql}WHERE {$primary} = {$key}";
	}

	/**
	 * Gets a prepared a query for updating a model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function updateRowStatement( string $class ): object {
		$table = $this->getTableName( $class );

		if ( empty( $this->queries[ $table ]['update'] ) ) {
			$properties = $class::properties();
			$primary    = $class::idProperty();
			$query      = $this->updateRowQuery( $table, $properties, $primary );
			return $this->queries[ $table ]['update'] = $this->prepare( $query );
		}
		return $this->queries[ $table ]['update'];
	}

	/**
	 * @param ModelInterface[] $models
	 *
	 * @return string
	 */
	protected function setMultiQuery( array $models ): string {
		if ( empty( $model = current( $models ) ) ) {
			return '';
		}

		$class      = $model::class;
		$properties = $model::properties();
		$columns    = array_map( [ $this, 'name' ], array_keys( $properties ) );

		// Get the escaped values for multiple models.
		$values = array_map( function( ModelInterface $model ): string {
			$data = $this->splitModel( $model );
			return sprintf( '(%s)', $this->escape( $data ) );
		}, $models );

		// Assign insert values to update columns.
		// ToDo: This syntax is deprecated beginning with MySQL 8.0.20, use an alias for the value rows instead.
		$update = array_map( function( string $column ): string {
			return "{$column}=VALUES({$column})";
		}, $columns );

		$table   = $this->name( $this->getTableName( $class ) );
		$columns = join( ', ', $columns );
		$values  = join( ', ', $values );
		$update  = join( ', ', $update );

		$sql[] = "INSERT INTO {$table} ({$columns})";
		$sql[] = "VALUES {$values}";
		$sql[] = "ON DUPLICATE KEY UPDATE {$update}";

		return join( "\n", $sql );
	}

	/**
	 * Generates a query for deleting a model from the given table.
	 *
	 * @param string $table The table name.
	 * @param string $primary The name of the primary key column.
	 * @param string|int|null $value The model id to delete.
	 *
	 * @return string A query for inserting a model into the given table.
	 */
	protected function deleteRowQuery( string $table, string $primary, string | int | null $value = null ): string {
		return vsprintf( 'DELETE FROM %s WHERE %s = %s', [
			$this->name( $table ),
			$this->name( $primary ),
			isset( $value ) ? $this->escape( $value ) : '?',
		] );
	}

	/**
	 * Gets a prepared query for deleting a model from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return object<PDOStatement> A prepared query object.
	 */
	protected function deleteRowStatement( string $class ): object {
		$table = $this->getTableName( $class );

		if ( empty( $this->queries[ $table ]['delete'] ) ) {
			$primary = $class::idProperty();
			$query   = $this->deleteRowQuery( $table, $primary );
			return $this->queries[ $table ]['delete'] = $this->prepare( $query );
		}
		return $this->queries[ $table ]['delete'];
	}

	/* -------------------------------------------------------------------------
	 * Create and drop databases
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

		// Remove surplus foreign keys.
		foreach ( $classes as $name ) {
			$count += $this->alterForeign( $name, false );
		}

		// Create or alter model tables (columns + indexes).
		foreach ( $classes as $name ) {
			$count += $this->createTable( $name ) ?: $this->alterTable( $name );
		}

		// Set foreign keys after all tables, columns and indexes are in place.
		foreach ( $classes as $name ) {
			$count += $this->alterForeign( $name );
		}

		return $count;
	}

	/* -------------------------------------------------------------------------
	 * Show, create and alter tables
	 * ---------------------------------------------------------------------- */

	/**
	 * Gets a query to show all tables in the current database.
	 *
	 * @return string A query to show all database tables.
	 */
	protected function showTablesQuery(): string {
		return 'SHOW TABLES';
	}

	/**
	 * Gets all tables in the current database.
	 *
	 * @return array[] An array of all database tables.
	 */
	protected function showTables(): array {
		$query = $this->showTablesQuery();
		return $this->query( $query );
	}

	/**
	 * Gets all tables names in the current database.
	 *
	 * @return string[] An array of all database table names.
	 */
	protected function showTableNames(): array {
		foreach ( $this->showTables() as $table ) {
			$result[] = current( $table );
		}
		return $result ?? [];
	}

	/**
	 * Generates a query to create a database table for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model to create a database table for.
	 *
	 * @return string A query to create a database table.
	 */
	protected function createTableQuery( string $class ): string {
		$columns = $this->getModelColumns( $class );
		$indexes = $this->getModelIndexes( $class );

		// Create columns.
		foreach ( $columns as $column ) {
			$sql[] = $this->defineColumnQuery( $column );
		}

		// Create indexes.
		foreach ( $indexes as $index ) {
			$sql[] = $this->defineIndexQuery( $index );
		}

		if ( isset( $sql ) ) {
			$sql   = "\n\t" . join( ",\n\t", $sql ) . "\n";
			$table = $this->name( $this->getTableName( $class ) );
			return sprintf( 'CREATE TABLE IF NOT EXISTS %s (%s)', $table, $sql );
		}

		$table = $this->name( $this->getTableName( $class ) );
		return sprintf( 'CREATE TABLE IF NOT EXISTS %s', $table );
	}

	/**
	 * Creates a database table for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model to create a database table for.
	 *
	 * @return int The number of created database tables.
	 */
	protected function createTable( string $class ): int {
		$query = $this->createTableQuery( $class );
		return $this->exec( $query );
	}

	/**
	 * Generates a query to alter a database table to fit the given model.
	 *
	 * @param class-string<ModelInterface> $class The model to alter a database table for.
	 *
	 * @return string A query to alter a database table.
	 */
	protected function alterTableQuery( string $class ): string {
		$columns = $this->calcDeltaColumns( $class );
		$indexes = $this->calcDeltaIndexes( $class );

		// Drop indexes.
		foreach ( array_keys( $indexes['drop'] ) as $name ) {
			$sql[] = sprintf( 'DROP INDEX %s', $this->name( $name ) );
		}

		// Drop columns.
		foreach ( array_keys( $columns['drop'] ) as $name ) {
			$sql[] = sprintf( 'DROP COLUMN %s', $this->name( $name ) );
		}

		// Alter columns.
		foreach ( $columns['alter'] as $old => $column ) {
			$sql[] = $old === $column['name']
				? sprintf( 'MODIFY COLUMN %s', $this->defineColumnQuery( $column ) )
				: sprintf( 'CHANGE COLUMN %s %s', $this->name( $old ), $this->defineColumnQuery( $column ) );
		}

		// Create columns.
		foreach ( $columns['create'] as $column ) {
			$sql[] = sprintf( 'ADD COLUMN %s', $this->defineColumnQuery( $column ) );
		}

		// Create indexes.
		foreach ( $indexes['create'] as $index ) {
			$sql[] = sprintf( 'ADD %s', $this->defineIndexQuery( $index ) );
		}

		if ( isset( $sql ) ) {
			$sql   = "\n" . join( ",\n", $sql );
			$table = $this->name( $this->getTableName( $class ) );
			return sprintf( 'ALTER TABLE %s %s', $table, $sql );
		}

		return '';
	}

	/**
	 * Alters a database table to match the given model.
	 *
	 * @param class-string<ModelInterface> $class The model to alter a database table for.
	 *
	 * @return int The number of altered database tables.
	 */
	protected function alterTable( string $class ): int {
		if ( $query = $this->alterTableQuery( $class ) ) {
			return $this->exec( $query );
		}
		return 0;
	}

	/* -------------------------------------------------------------------------
	 * Show and define table columns.
	 * ---------------------------------------------------------------------- */

	/**
	 * Generates a query to show all columns in the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return string A query to show all columns in the given table.
	 */
	protected function showColumnsQuery( string $table ): string {
		return sprintf( 'SHOW COLUMNS FROM %s', $this->name( $table ) );
	}

	/**
	 * Gets all columns in the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of column definitions.
	 */
	protected function showColumns( string $table ): array {
		$query = $this->showColumnsQuery( $table );
		return $this->query( $query );
	}

	/**
	 * Generates a column definition query for the given model property.
	 *
	 * @param Property|array $property A model property.
	 *
	 * @return string A column definition query.
	 */
	protected function defineColumnQuery( Property | array $property ): string {
		$type     = $property['type'];
		$required = $property['required'] ?? null;
		$default  = $property['default'] ?? null;

		// Cast default value.
		if ( isset( $default ) ) {
			$default = $this->escape( $default );
		} elseif ( empty( $required ) && 'TEXT' !== $type ) {
			$default = 'NULL';
		}

		return join( ' ', array_filter( [
			$this->name( $property['name'] ),
			$type,
			$required ? 'NOT NULL' : null,
			isset( $default ) ? "DEFAULT {$default}" : null,
		] ) );
	}

	/**
	 * Gets column definitions for the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of column definitions.
	 */
	protected function getTableColumns( string $table ): array {
		$columns = $this->showColumns( $table );
		$result  = [];

		foreach ( $columns as $column ) {
			$name    = $column['Field'];
			$default = $column['Default'];

			// Normalise default values.
			if ( isset( $default ) ) {
				if ( 'decimal(32,10)' === $column['Type'] ) {
					$default = (float) $default;
				}
			}

			$result[ $name ] = [
				'name'     => $name,
				'type'     => $column['Type'],
				'required' => $column['Null'] === 'NO',
				'default'  => $default,
			];
		}

		return $result;
	}

	/**
	 * Gets column definitions for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of column definition.
	 */
	protected function getModelColumns( string $class ): array {
		return array_map( function( Property | array $property ) use ( $class ): array {
			$id      = $property[ PropertyItem::ID ];
			$type    = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;
			$child   = $property[ PropertyItem::MODEL ] ?? null;
			$default = $property[ PropertyItem::DEFAULT ] ?? null;

			if ( empty( is_scalar( $default ) ) ) {
				$default = null;
			}

			if ( PropertyType::UUID === $type && true === $default ) {
				$default = null;
			}

			if ( is_bool( $default ) ) {
				$default = (int) $default;
			}

			$column = [
				'name'     => $id,
				'type'     => $this->getColumnType( $property ),
				'required' => $property[ PropertyItem::REQUIRED ] ?? false,
				'default'  => $default,
			];

			// Replace sub-models with foreign keys.
			if ( PropertyType::OBJECT === $type && Utils::isModel( $child ) ) {
				if ( $primary = $child::getProperty( $child::idProperty() ) ) {
					$column['type'] = $this->getColumnType( $primary );
				}
			}

			return $column;
		}, $class::properties() );
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

		switch ( $type ) {
			case PropertyType::MIXED:
				return 'varbinary(255)';
			case PropertyType::BOOL:
				return 'tinyint(1)';
			case PropertyType::INTEGER:
				return 'bigint(20)';
			case PropertyType::FLOAT:
			case PropertyType::NUMBER:
				return 'decimal(32,10)';
			case PropertyType::UUID:
				return 'char(36)';
			case PropertyType::STRING:
			case PropertyType::URL:
				$unique  = $property[ PropertyItem::UNIQUE ] ?? null;
				$index   = $property[ PropertyItem::INDEX ] ?? null;
				$default = $property[ PropertyItem::DEFAULT ] ?? null;
				$max     = $property[ PropertyItem::MAX ] ?? PHP_INT_MAX;
				$max     = isset( $unique ) || isset( $index ) || isset( $default ) ? min( 255, $max ) : $max;

				return ( $max <= 255 ) ? sprintf( 'varchar(%d)', $max ) : 'text';
			case PropertyType::EMAIL:
				return 'varchar(255)';
			case PropertyType::DATETIME:
				return 'varchar(32)';
			case PropertyType::DATE:
				return 'varchar(10)';
			case PropertyType::TIME:
				return 'varchar(8)';
			case PropertyType::OBJECT:
			case PropertyType::ARRAY:
				return 'json';
		}
		return '';
	}

	/**
	 * Calculates the delta between old and new columns for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of column deltas: drop, alter and create.
	 */
	protected function calcDeltaColumns( string $class ): array {
		$tableColumns = $this->getTableColumns( $this->getTableName( $class ) );
		$modelColumns = $this->getModelColumns( $class );

		$common = array_intersect_key( $modelColumns, $tableColumns );
		$drop   = array_diff_key( $tableColumns, $modelColumns );
		$create = array_diff_key( $modelColumns, $tableColumns );
		$alter  = [];

		// Get altered columns.
		foreach ( $common as $name => $column ) {
			if ( array_diff_assoc( $column, $tableColumns[ $name ] ) ) {
				$alter[ $name ] = $column;
			}
		}

		// Get renamed columns.
		// There is no safe way to know if a model property was replaced or renamed.
		// Here we assume that if the column type remains the same, then the property was renamed.
		foreach ( $create as $name => $modelColumn ) {
			foreach ( $drop as $old => $tableColumn ) {
				if ( $modelColumn['type'] === $tableColumn['type'] ) {
					$alter[ $old ] = $modelColumn;
					unset( $create[ $name ] );
					unset( $drop[ $old ] );
					break;
				}
			}
		}

		return compact( 'drop', 'alter', 'create' );
	}

	/* -------------------------------------------------------------------------
	 * Show and define table indexes
	 * ---------------------------------------------------------------------- */

	/**
	 * Generates a query to show all indexes on the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return string A query to show all indexes on the given table.
	 */
	protected function showIndexesQuery( string $table ): string {
		return sprintf( 'SHOW INDEXES FROM %s', $this->name( $table ) );
	}

	/**
	 * Gets all indexes on the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of index definitions.
	 */
	protected function showIndexes( string $table ): array {
		$query = $this->showIndexesQuery( $table );
		return $this->query( $query );
	}

	/**
	 * Generates an index definition query for the given index.
	 *
	 * @param array $index An index definition.
	 *
	 * @return string An index definition query.
	 */
	protected function defineIndexQuery( array $index ): string {
		$name    = $this->name( $index['name'] );
		$columns = join( ', ', array_map( [ $this, 'name' ], $index['columns'] ) );

		return match ( $index['type'] ?? 'INDEX' ) {
			'PRIMARY' => sprintf( 'PRIMARY KEY (%s)', $columns ),
			'UNIQUE'  => sprintf( 'UNIQUE %s (%s)', $name, $columns ),
			default   => sprintf( 'INDEX %s (%s)', $name, $columns ),
		};
	}

	/**
	 * Gets index definitions for the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of index definitions.
	 */
	protected function getTableIndexes( string $table ): array {
		$indexes = $this->showIndexes( $table );
		$indexes = Utils::group( $indexes, 'Key_name' );
		$result  = [];

		foreach ( $indexes as $name => $index ) {
			if ( 'PRIMARY' === $index[0]['Key_name'] ) {
				$type = 'PRIMARY';
			} else {
				$type = $index[0]['Non_unique'] ? 'INDEX' : 'UNIQUE';
			}

			$result[ $name ] = [
				'name'    => $name,
				'type'    => $type,
				'columns' => array_column( $index, 'Column_name' ),
			];
		}

		return $result;
	}

	/**
	 * Gets index definitions for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of index definitions.
	 */
	protected function getModelIndexes( string $class ): array {
		$properties = $class::properties();
		$primary    = $class::idProperty();
		$result     = [];

		// Set primary key.
		if ( $primary && array_key_exists( $primary, $properties ) ) {
			$result['PRIMARY'] = [
				'name'    => 'PRIMARY',
				'type'    => 'PRIMARY',
				'columns' => [ $primary ],
			];
		}

		foreach ( $properties as $id => $property ) {
			if ( Utils::isColumn( $property ) ) {

				// Set index.
				if ( $name = $property[ PropertyItem::INDEX ] ?? null ) {
					$result[ $name ]['name']      = $name;
					$result[ $name ]['type']      = 'INDEX';
					$result[ $name ]['columns'][] = $id;
				}

				// Set unique index.
				if ( $name = $property[ PropertyItem::UNIQUE ] ?? null ) {
					$result[ $name ]['name']      = $name;
					$result[ $name ]['type']      = 'UNIQUE';
					$result[ $name ]['columns'][] = $id;
				}

				// Set indexes for foreign keys.
				if ( Utils::needsForeignKey( $property ) ) {
					$result[ $id ] = $result[ $id ] ?? [
						'name'    => $id,
						'type'    => 'INDEX',
						'columns' => [ $id ],
					];
				}
			}
		}

		return $result;
	}

	/**
	 * Calculates the delta between old and new indexes for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of index deltas: drop, alter and create.
	 */
	protected function calcDeltaIndexes( string $class ): array {
		$tableIndexes = $this->getTableIndexes( $this->getTableName( $class ) );
		$modelIndexes = $this->getModelIndexes( $class );

		$common = array_intersect_key( $modelIndexes, $tableIndexes );
		$drop   = array_diff_key( $tableIndexes, $modelIndexes );
		$create = array_diff_key( $modelIndexes, $tableIndexes );

		// Check for differences.
		foreach ( $common as $name => $modelIndex ) {
			$tableIndex = $tableIndexes[ $name ];
			$modelJson  = Utils::encode( $modelIndex );
			$tableJson  = Utils::encode( $tableIndex );

			if ( $modelJson !== $tableJson ) {
				$drop[ $name ]   = $tableIndex;
				$create[ $name ] = $modelIndex;
			}
		}

		return compact( 'drop', 'create' );
	}

	/* -------------------------------------------------------------------------
	 * Show and define foreign keys
	 * ---------------------------------------------------------------------- */

	/**
	 * Generates a query to show all foreign keys on the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return string A query to show all foreign keys on the given table.
	 */
	protected function showForeignQuery( string $table = '' ): string {
		$schema = $this->escape( $this->dbname );
		$table  = $table ? $this->escape( $table ) : '?';

		$sql[] = 'SELECT * FROM information_schema.KEY_COLUMN_USAGE as kcu';
		$sql[] = 'JOIN   information_schema.REFERENTIAL_CONSTRAINTS as rc';
		$sql[] = 'ON     kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME';
		$sql[] = "WHERE  kcu.TABLE_SCHEMA = {$schema} AND rc.CONSTRAINT_SCHEMA = {$schema}";
		$sql[] = "AND    kcu.TABLE_NAME = {$table} AND rc.TABLE_NAME = {$table}";

		return join( "\n", $sql );
	}

	/**
	 * Gets all foreign keys on the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of foreign key definitions.
	 */
	protected function showForeign( string $table ): array {
		$query = $this->prepare( $this->showForeignQuery() );
		return $this->select( $query, [ $table, $table ] );
	}

	/**
	 * Generates queries to alter foreign keys to fit the given model.
	 *
	 * @param class-string<ModelInterface> $class The model alter foreign keys for.
	 *
	 * @return string[] Queries to alter foreign keys.
	 */
	protected function alterForeignQuery( string $class ): array {
		$foreign = $this->calcDeltaForeign( $class );

		// Drop foreign keys.
		foreach ( array_keys( $foreign['drop'] ) as $name ) {
			$drop[] = sprintf( 'DROP FOREIGN KEY %s', $this->name( $name ) );
		}

		// Create foreign keys.
		foreach ( $foreign['create'] as $index ) {
			$create[] = sprintf( 'ADD %s', $this->defineForeignQuery( $index ) );
		}

		if ( isset( $drop ) ) {
			$drop  = "\n" . join( ",\n", $drop );
			$table = $this->name( $this->getTableName( $class ) );
			$query = $sql['drop'] = sprintf( 'ALTER TABLE %s %s', $table, $drop );
		}

		if ( isset( $create ) ) {
			$create = "\n" . join( ",\n", $create );
			$table  = $this->name( $this->getTableName( $class ) );
			$query  = $sql['create'] = sprintf( 'ALTER TABLE %s %s', $table, $create );
		}

		return $sql ?? [];
	}

	/**
	 * Alters foreign keys to fit the given model.
	 *
	 * @param class-string<ModelInterface> $class The model alter foreign keys for.
	 *
	 * @return bool True if any foreign keys were altered, false otherwise.
	 */
	protected function alterForeign( string $class, $create = true ): bool {
		$count = 0;

		foreach ( $this->alterForeignQuery( $class ) as $type => $query ) {
			if ( $create || 'create' !== $type ) {
				$count += $this->exec( $query );
			}
		}

		return (bool) $count;
	}

	/**
	 * Generates a foreign key definition query for the given index.
	 *
	 * @param array $index A foreign key definition.
	 *
	 * @return string A foreign key definition query.
	 */
	protected function defineForeignQuery( array $index ): string {

		// Index name and columns.
		$name    = $this->name( $index['name'] );
		$columns = join( ', ', array_map( [ $this, 'name' ], $index['columns'] ) );

		// Reference table and columns.
		$table  = $this->name( $index['table'] );
		$fields = join( ', ', array_map( [ $this, 'name' ], $index['fields'] ) );

		// ON UPDATE / DELETE actions.
		$update = $index['update'] ?? null;
		$delete = $index['delete'] ?? null;

		$sql[] = sprintf( 'CONSTRAINT %s FOREIGN KEY (%s)', $name, $columns );
		$sql[] = sprintf( 'REFERENCES %s (%s)', $table, $fields );

		if ( $update ) {
			$sql[] = sprintf( 'ON UPDATE %s', $update );
		}

		if ( $delete ) {
			$sql[] = sprintf( 'ON DELETE %s', $delete );
		}

		return join( ' ', $sql );
	}

	/**
	 * Gets foreign key definitions for the given table.
	 *
	 * @param string $table The table name.
	 *
	 * @return array[] An array of foreign key definitions.
	 */
	protected function getTableForeign( string $table ): array {
		$constraints = $this->showForeign( $table );
		$constraints = array_column( $constraints, null, 'CONSTRAINT_NAME' );
		$result      = [];

		foreach ( $constraints as $name => $constraint ) {
			$result[ $name ] = [
				'name'    => $name,
				'type'    => 'FOREIGN',
				'columns' => [ $constraint['COLUMN_NAME'] ],
				'table'   => $constraint['REFERENCED_TABLE_NAME'],
				'fields'  => [ $constraint['REFERENCED_COLUMN_NAME'] ],
				'update'  => $constraint['UPDATE_RULE'],
				'delete'  => $constraint['DELETE_RULE'],
			];
		}

		return $result;
	}

	/**
	 * Gets foreign key definitions for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of foreign key definitions.
	 */
	protected function getModelForeign( string $class ): array {
		foreach ( $class::properties() as $id => $property ) {
			if ( Utils::needsForeignKey( $property ) ) {
				$model    = $property[ PropertyItem::MODEL ] ?? null;
				$foreign  = $property[ PropertyItem::FOREIGN ] ?? $model;
				$required = $property[ PropertyItem::REQUIRED ] ?? false;
				$name     = $this->getTableName( $this->getRelationName( $class, $id ) );

				$result[ $name ] = [
					'name'    => $name,
					'type'    => 'FOREIGN',
					'columns' => [ $id ],
					'table'   => $this->getTableName( $foreign ),
					'fields'  => [ $foreign::idProperty() ],
					'update'  => 'CASCADE',
					'delete'  => $required ? 'CASCADE' : 'SET NULL',
				];
			}
		}

		return $result ?? [];
	}

	/**
	 * Calculates the delta between old and new foreign keys for the given model.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return array[] An array of index deltas: drop and create.
	 */
	protected function calcDeltaForeign( string $class ): array {
		$tableConstraints = $this->getTableForeign( $this->getTableName( $class ) );
		$modelConstraints = $this->getModelForeign( $class );

		$common = array_intersect_key( $modelConstraints, $tableConstraints );
		$drop   = array_diff_key( $tableConstraints, $modelConstraints );
		$create = array_diff_key( $modelConstraints, $tableConstraints );

		// Check for differences.
		foreach ( $common as $name => $modelConstraint ) {
			$tableConstraint = $tableConstraints[ $name ];
			$modelJson       = Utils::encode( $modelConstraint );
			$tableJson       = Utils::encode( $tableConstraint );

			if ( $modelJson !== $tableJson ) {
				$drop[ $name ]   = $modelConstraint;
				$create[ $name ] = $tableConstraint;
			}
		}

		return compact( 'drop', 'create' );
	}

	protected function getRelationName( string $class, string $id ): string {
		return $class . '\\_' . $id;
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
	 * Extract all sub-models from the given models.
	 *
	 * @param ModelInterface[]|string[] $models An array of model class names.
	 * @param ModelInterface[]|string[] $result An array of all model and sub-model class names.
	 *
	 * @return ModelInterface[]|string[] An array of all model and sub-model class names.
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
