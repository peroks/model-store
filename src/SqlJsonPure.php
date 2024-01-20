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
	 * @var string The column name where the model json is stored.
	 */
	protected string $modelColumn = '_model';

	/**
	 * @var string The db data type for the model column.
	 */
	protected string $modelType = 'json';

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
				$this->name( $class::idProperty() ),
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
				$this->name( $class::idProperty() ),
			] );

			$this->prepared[ $table ]['get'] = $this->prepare( $query );
		}

		// Get and execute prepared query.
		$prepared = $this->prepared[ $table ]['get'];
		$rows     = $this->select( $prepared, [ $id ] );

		if ( $rows ) {
			$row = current( $rows );
			return $this->join( $class, $row );
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
		if ( empty( $ids ) ) {
			return $this->all( $class );
		}

		if ( count( $ids ) === 1 ) {
			$model = $this->get( $class, current( $ids ) );
			return [ $model ];
		}

		$table   = $this->name( $this->getTableName( $class ) );
		$primary = $this->name( $class::idProperty() );
		$fill    = join( ', ', array_fill( 0, count( $ids ), '?' ) );
		$query   = "SELECT * FROM {$table} WHERE {$primary} IN ({$fill})";
		$rows    = $this->query( $query, $ids );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			return $this->join( $class, $row );
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
		if ( empty( $filter ) ) {
			return $this->all( $class );
		}

		$properties    = array_filter( $class::properties(), [ $this, 'isColumn' ] );
		$index_filter  = array_intersect_key( $filter, $properties );
		$json_filter   = array_diff_key( $filter, $index_filter );
		$scalar_filter = array_filter( $json_filter, 'is_scalar' );
		$rest_filter   = array_diff_key( $json_filter, $scalar_filter );
		$values        = [];

		$sql = array_map( function( string $key, mixed $value ) use ( &$values ): string {
			if ( is_array( $value ) ) {
				$values += $value;
				$fill   = join( ', ', array_fill( 0, count( $value ), '?' ) );
				return sprintf( '(%s IN (%s))', $this->name( $key ), $fill );
			}

			if ( $value instanceof Range ) {
				$values[] = $value->from;
				$values[] = $value->to;
				return sprintf( '(%s BETWEEN ? AND ?)', $this->name( $key ) );
			}

			$values[] = $value;
			return sprintf( '(%s = ?)', $this->name( $key ) );
		}, array_keys( $index_filter ), $index_filter );

		if ( $scalar_filter ) {
			$values[] = Utils::encode( $scalar_filter );
			$sql[]    = sprintf( 'JSON_CONTAINS(%s, ?)', $this->name( $this->modelColumn ) );
		}

		foreach ( $rest_filter as $key => $value ) {
			if ( is_array( $value ) ) {
				// Json values don't support comparison with the "IN" operator.
				$sql[] = vsprintf( 'JSON_CONTAINS(JSON_ARRAY(%s), JSON_EXTRACT(%s, "$.%s"))', [
					$this->escape( $value ),
					$this->name( $this->modelColumn ),
					$key,
				] );
			} elseif ( $value instanceof Range ) {
				// Json values don't support comparison with the "BETWEEN" operator.
				$values[] = $value->from;
				$values[] = $value->to;
				$sql[]    = sprintf( 'JSON_EXTRACT(%s, "$.%s") >= ?', $this->name( $this->modelColumn ), $key );
				$sql[]    = sprintf( 'JSON_EXTRACT(%s, "$.%s") <= ?', $this->name( $this->modelColumn ), $key );
			}
		}

		$table = $this->name( $this->getTableName( $class ) );
		$sql   = join( ' AND ', $sql );
		$query = "SELECT * FROM {$table} WHERE {$sql}";
		$rows  = $this->query( $query, $values );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			return $this->join( $class, $row );
		}, $rows );
	}

	/**
	 * Gets all models of the given class from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return ModelInterface[] An array of matching models.
	 */
	protected function all( string $class ): array {
		$table = $this->getTableName( $class );
		$query = sprintf( 'SELECT * FROM %s', $this->name( $table ) );

		// Set prepared query.
		if ( empty( $this->prepared[ $table ]['all'] ) ) {
			$this->prepared[ $table ]['all'] = $this->prepare( $query );
		}

		$prepared = $this->prepared[ $table ]['all'];
		$rows     = $this->select( $prepared );

		return array_map( function( array $row ) use ( $class ): ModelInterface {
			return $this->join( $class, $row );
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

		$class      = $model::class;
		$properties = array_filter( $model::properties(), [ $this, 'isColumn' ] );
		$properties = array_keys( $properties );
		$columns    = array_map( [ $this, 'name' ], $properties );
		$columns[]  = $this->name( $this->modelColumn );
		$values     = [];

		// Get the escaped values for multiple models.
		$insert = array_map( function( ModelInterface $model ) use ( $properties, &$values ): string {
			foreach ( $properties as $id ) {
				$value    = $model[ $id ];
				$values[] = match ( true ) {
					is_bool( $value ) => $value ? 1 : 0,
					default           => $value,
				};
				$sql[]    = '?';
			}
			$data     = $this->split( $model );
			$values[] = Utils::encode( $data );
			$sql[]    = '?';

			return sprintf( '(%s)', join( ', ', $sql ) );
		}, $models );

		// Assign insert values to update columns.
		// ToDo: This syntax is deprecated beginning with MySQL 8.0.20, use an alias for the value rows instead.
		$update = array_map( function( string $column ): string {
			return "{$column} = VALUES({$column})";
		}, $columns );

		$table   = $this->name( $this->getTableName( $class ) );
		$columns = join( ', ', $columns );
		$insert  = join( ', ', $insert );
		$update  = join( ', ', $update );

		$sql[] = "INSERT INTO {$table} ({$columns})";
		$sql[] = "VALUES {$insert}";
		$sql[] = "ON DUPLICATE KEY UPDATE {$update}";

		$query    = join( "\n", $sql );
		$prepared = $this->prepare( $query );
		$this->update( $prepared, $values );

		return $models;
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
			$count += $this->createTable( $name ) ?: $this->alterTable( $name );
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
			$sql   = join( ', ', $sql );
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
	 * @param array $column A table column.
	 *
	 * @return string A column definition query.
	 */
	protected function defineColumnQuery( array $column ): string {
		$type     = $column['type'];
		$required = $column['required'] ?? null;
		$default  = $column['default'] ?? null;

		// Cast default value.
		if ( isset( $default ) ) {
			$default = $this->escape( $default );
		} elseif ( empty( $required ) && 'TEXT' !== $type ) {
			$default = 'NULL';
		}

		return join( ' ', array_filter( [
			$this->name( $column['name'] ),
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
		$properties = $class::properties();
		$properties = array_filter( $properties, [ $this, 'isColumn' ] );

		$columns = array_map( function( Property | array $property ) use ( $class ): array {
			$id      = $property[ PropertyItem::ID ];
			$type    = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;
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

			return [
				'name'     => $id,
				'type'     => $this->getColumnType( $property ),
				'required' => $property[ PropertyItem::REQUIRED ] ?? false,
				'default'  => $default,
			];
		}, $properties );

		$columns[ $this->modelColumn ] = [
			'name'     => $this->modelColumn,
			'type'     => $this->modelType,
			'required' => true,
			'default'  => null,
		];

		return $columns;
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
		$max  = max( $property[ PropertyItem::MAX ] ?? 255, 255 );

		return match ( $type ) {
			PropertyType::UUID     => 'char(36)',
			PropertyType::BOOL     => 'tinyint(1)',
			PropertyType::INTEGER  => 'bigint(20)',
			PropertyType::DATETIME => 'varchar(32)',
			PropertyType::DATE     => 'varchar(16)',
			PropertyType::TIME     => 'varchar(8)',
			PropertyType::STRING,
			PropertyType::URL,
			PropertyType::EMAIL    => sprintf( 'varchar(%d)', $max ),
		};
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
			if ( $name = $property[ PropertyItem::INDEX ] ?? null ) {
				$result[ $name ]['name']      = $name;
				$result[ $name ]['type']      = 'INDEX';
				$result[ $name ]['columns'][] = $id;
			}

			if ( $name = $property[ PropertyItem::UNIQUE ] ?? null ) {
				$result[ $name ]['name']      = $name;
				$result[ $name ]['type']      = 'UNIQUE';
				$result[ $name ]['columns'][] = $id;
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

	protected function isColumn( Property | array $property ): bool {
		$index = ( $property[ PropertyItem::PRIMARY ] ?? false )
			|| ( $property[ PropertyItem::UNIQUE ] ?? '' )
			|| ( $property[ PropertyItem::INDEX ] ?? '' );

		return $index && empty( $property[ PropertyType::FUNCTION ] ?? false );
	}

	/**
	 * Replaces sub-model ids with the sub-model itself.
	 *
	 * @param class-string<ModelInterface> $class The class name to join.
	 * @param array $row The model db row.
	 */
	protected function join( string $class, array $row ): ModelInterface {
		$properties = $class::properties();
		$data       = json_decode( $row[ $this->modelColumn ], true );

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

			if ( $property[ PropertyType::FUNCTION ] ?? null ) {
				continue;
			}

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
