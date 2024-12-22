<?php
/**
 * PDO abstraction layer for storing and retrieving models.
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

/**
 * PDO abstraction layer for storing and retrieving models.
 */
trait PdoTrait {

	/**
	 * @var object<PDO> $db The database object.
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
	 * @return PDO A PDO db instance.
	 */
	protected function connect( object $connect ): object {
		$dsn = "mysql:charset=utf8mb4;host={$connect->host}";
		$db  = new PDO( $dsn, $connect->user, $connect->pass, [
			PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_PERSISTENT       => true,
			PDO::ATTR_EMULATE_PREPARES => false,
		] );

		if ( ! empty( $connect->name ) ) {
			$db->exec( "USE {$connect->name}" );
		}

		return $db;
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
}
