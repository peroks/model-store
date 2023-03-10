<?php namespace Peroks\Model\Store;

use Generator;
use mysqli, mysqli_sql_exception;
use Peroks\Model\ModelInterface;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;

/**
 * Class for storing and retrieving models from a Mysql database.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */
class MysqlStore extends PdoStore implements StoreInterface {

	/**
	 * @var mysqli|object $db The database object.
	 */
	protected object $db;

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
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

		// Delete database.
		if ( false ) {
			$db = new mysqli( $connect->host, $connect->user, $connect->pass );
			$db->real_query( $this->dropDatabaseQuery( $connect->name ) );
		}

		try {
			$db = new mysqli( $connect->host, $connect->user, $connect->pass, $connect->name );
			$db->set_charset( 'utf8mb4' );
		} catch ( mysqli_sql_exception $e ) {
			$db = new mysqli( $connect->host, $connect->user, $connect->pass );
			$db->set_charset( 'utf8mb4' );
			$db->real_query( $this->createDatabaseQuery( $connect->name ) );
			$db->select_db( $connect->name );
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
		$this->db->real_query( $query );
		return $this->db->affected_rows;
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
	 * @return object A prepared query object.
	 */
	protected function prepare( string $query ): object {
		$params = static::stripQueryParams( $query );

		return (object) [
			'query'  => $this->db->prepare( $query ),
			'params' => $params,
		];
	}

	/**
	 * Executes a prepared select query and returns the result.
	 *
	 * @param object $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return array[] An array of database rows.
	 */
	protected function select( object $prepared, array $values = [] ): array {
		static::bindParams( $prepared, $values );
		$prepared->query->execute();
		return $prepared->query->get_result()->fetch_all( MYSQLI_ASSOC );
	}

	/**
	 * Executes a prepared insert, update or delete query and returns the number of affected rows.
	 *
	 * @param object $prepared A prepared query object.
	 * @param array $values Query parameter values.
	 *
	 * @return int The number of updated rows.
	 */
	protected function update( object $prepared, array $values = [] ): int {
		static::bindParams( $prepared, $values );
		$prepared->query->execute();
		return $prepared->query->affected_rows;
	}

	/**
	 * Initiates a transaction.
	 *
	 * @return bool True on success or false on failure.
	 */
	protected function beginTransaction(): bool {
		return $this->db->begin_transaction();
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
	protected function escape( $value ) {
		return is_string( $value ) ? "'" . $this->db->real_escape_string( $value ) . "'" : $value;
	}

	/**
	 * @param string $query
	 *
	 * @return Generator
	 */
	protected function multi( string $query ): Generator {
		$this->db->multi_query( $query );

		do {
			if ( $result = $this->db->use_result() ) {
				yield $result;
				$result->free();
			}
		} while ( $this->db->next_result() );
	}

	protected function fetch( object $result ): ?array {
		return $result->fetch_assoc() ?: null;
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Replaces named PDO placeholders with question mark placeholders in a query.
	 *
	 * @param string $query The query to modify.
	 *
	 * @return array The parameter names (array keys) in correct order for the placeholders.
	 */
	protected static function stripQueryParams( string &$query ): array {
		$pattern = '/:(\\w+)/';
		$params  = [];

		if ( preg_match_all( $pattern, $query, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$params[] = $match[1];
			}
		}

		$query = preg_replace( $pattern, '?', $query );
		return $params;
	}

	/**
	 * Binds parameter values to a prepared query object.
	 *
	 * @param object $prepared A prepared query object.
	 * @param array $params Key/value pairs of query parameters.
	 */
	protected static function bindParams( object $prepared, array $params ): void {
		if ( $params ) {
			$params = array_merge( array_flip( $prepared->params ), $params );
			$params = array_values( $params );
			$types  = '';

			foreach ( $params as $value ) {
				if ( is_string( $value ) ) {
					$types .= 's';
				} elseif ( is_int( $value ) ) {
					$types .= 'i';
				} elseif ( is_float( $value ) ) {
					$types .= 'd';
				} else {
					$types .= 'b';
				}
			}

			$prepared->query->bind_param( $types, ...$params );
		}
	}

	/**
	 * Completely restores an array of models including all sub-models.
	 *
	 * @param ModelInterface|string $class The model class name.
	 * @param ModelInterface[]|array[] $models An array of models of the given class.
	 *
	 * @return array An array of completely restored models.
	 * @ignore Alternative to restoreMulti()
	 */
	protected function altRestoreMulti( string $class, array $models ): array {
		if ( empty( $models ) ) {
			return $models;
		}

		if ( empty( $properties = static::getForeignProperties( $class::properties() ) ) ) {
			return $models;
		}

		// Temp variables.
		$targets  = [];
		$queries  = [];
		$children = [];

		// Loop over all models and their sub-model properties.
		foreach ( $models as $model ) {
			foreach ( $properties as $id => $property ) {
				$type  = $property[ PropertyItem::TYPE ];
				$child = $property[ PropertyItem::MODEL ];
				$value = $model[ $id ];

				// Create queries to fetch sub-models.
				if ( PropertyType::ARRAY === $type ) {
					$targets[] = (object) compact( 'model', 'child', 'id', 'type' );
					$queries[] = $this->selectChildrenQuery( $class, $child, $id, $model->id() );
				} elseif ( PropertyType::OBJECT === $type && isset( $value ) ) {
					$table     = $this->getTableName( $child );
					$targets[] = (object) compact( 'model', 'child', 'id', 'type' );
					$queries[] = $this->selectRowQuery( $table, $child::idProperty(), $value );
				}
			}
		}

		// Execute the queries and fetch the sub-model data from the db.
		if ( $queries ) {
			foreach ( $this->multi( join( ";\n", $queries ) ) as $result ) {
				$target = array_shift( $targets );

				while ( $row = $this->fetch( $result ) ) {
					$child = $children[ $target->child ][] = new $target->child( $row );

					// Assign sub-models to the parent model.
					if ( PropertyType::OBJECT === $target->type ) {
						$target->model[ $target->id ] = $child;
					} elseif ( PropertyType::ARRAY === $target->type ) {
						$target->model[ $target->id ][] = $child;
					}
				}
			}
		}

		// Recursively restore sub-models.
		foreach ( $children as $class => $collection ) {
			static::restoreMulti( $class, $collection );
		}

		return $models;
	}
}
