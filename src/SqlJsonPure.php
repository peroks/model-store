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

abstract class SqlJsonPure extends SqlJsonStore implements StoreInterface {

	/**
	 * @var string The column name where the model json is stored.
	 */
	protected string $modelColumn = '_model';

	/**
	 * @var string The db data type for the model column.
	 */
	protected string $modelType = 'json';

	/* -------------------------------------------------------------------------
	 * Retrieving models.
	 * ---------------------------------------------------------------------- */

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

	/* -------------------------------------------------------------------------
	 * Create, drop and build databases
	 * ---------------------------------------------------------------------- */

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
	 * Show and define table columns.
	 * ---------------------------------------------------------------------- */

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

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	protected function isColumn( Property | array $property ): bool {
		$index = ( $property[ PropertyItem::PRIMARY ] ?? false )
			|| ( $property[ PropertyItem::UNIQUE ] ?? '' )
			|| ( $property[ PropertyItem::INDEX ] ?? '' );

		return $index && parent::isColumn( $property );
	}

	/**
	 * @param class-string<ModelInterface> $class The model class name.
	 *
	 * @return string[] The db row columns.
	 */
	protected function getRowColumns( string $class ): array {
		$properties = $class::properties();
		$properties = array_filter( $properties, [ $this, 'isColumn' ] );
		$columns    = array_keys( $properties );
		$columns[]  = $this->modelColumn;
		return $columns;
	}

	/**
	 * Checks if a model property needs a foreign key.
	 *
	 * @param Property|array $property The property.
	 *
	 * @return bool
	 */
	protected function needsForeignKey( Property | array $property ): bool {
		return false;
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
			$property = $properties[ $id ];

			if ( $property[ PropertyType::FUNCTION ] ?? null ) {
				continue;
			}

			if ( is_null( $value ) ) {
				$result[ $id ] = $value;
				continue;
			}

			// Transform boolean values.
			if ( is_bool( $value ) ) {
				$result[ $id ] = (int) $value;
				continue;
			}

			// Transform models.
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

		$json       = Utils::encode( $result );
		$properties = array_filter( $properties, [ $this, 'isColumn' ] );
		$result     = array_intersect_key( $result, $properties );

		$result[ $this->modelColumn ] = $json;
		return $result;
	}
}
