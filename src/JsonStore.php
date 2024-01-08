<?php
/**
 * Class for storing and retrieving models from a JSON data store.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

use Peroks\Model\ModelData;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;

class JsonStore implements StoreInterface {

	/**
	 * @var array Stored data.
	 */
	protected array $data = [];

	/**
	 * @var array Changed data
	 */
	protected array $changed = [];

	/**
	 * @var array Deleted models
	 */
	protected array $deleted = [];

	/**
	 * @var object Global options.
	 */
	protected object $options;

	/**
	 * @var string JSON source file.
	 */
	protected string $source;

	/* -------------------------------------------------------------------------
	 * Constructor and destructor
	 * ---------------------------------------------------------------------- */

	public static function load( $source, $options = [] ): self {
		return new static( $source, $options );
	}

	public function __construct( $source, $options = [] ) {
		$this->source = $source;
		$this->init( $options );
		$this->open();
	}

	public function __destruct() {
		$this->save();
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
		return isset( $this->data[ $class ][ $id ] )
			|| isset( $this->changed[ $class ][ $id ] );
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
		if ( $this->has( $class, $id ) ) {
			$data = array_replace( $this->data[ $class ][ $id ] ?? [], $this->changed[ $class ][ $id ] ?? [] );
			$data = $this->join( $class, $data );
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
		$result = array_replace( $this->data[ $class ] ?? [], $this->changed[ $class ] ?? [] );

		if ( $ids ) {
			$result = array_intersect_key( $result, array_flip( $ids ) );
		}

		$result = array_map( function( array $data ) use ( $class ): ModelInterface {
			return new $class( $this->join( $class, $data ) );
		}, $result );

		return array_values( $result );
	}

	/**
	 * Gets a filtered list of models from the data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param array $filter Properties (key/value pairs) to match the stored models.
	 *
	 * @return ModelInterface[] An array of models.
	 */
	public function filter( string $class, array $filter = [] ): array {
		$result = array_replace( $this->data[ $class ] ?? [], $this->changed[ $class ] ?? [] );

		if ( $filter ) {
			$scalar_filter = array_filter( $filter, 'is_scalar' );
			$rest_filter   = array_diff_key( $filter, $scalar_filter );

			$result = array_filter( $result, function( array $data ) use ( $scalar_filter, $rest_filter ): bool {
				if ( $scalar_filter && array_intersect_assoc( $scalar_filter, $data ) !== $scalar_filter ) {
					return false;
				}
				foreach ( $rest_filter as $key => $value ) {
					if ( is_array( $value ) ) {
						if ( empty( in_array( $data[ $key ], $value, true ) ) ) {
							return false;
						}
					} elseif ( $value instanceof Range ) {
						if ( $value->from > $data[ $key ] || $data[ $key ] > $value->to ) {
							return false;
						}
					}
				}
				return true;
			} );
		}

		$result = array_map( function( array $data ) use ( $class ): ModelInterface {
			return new $class( $this->join( $class, $data ) );
		}, $result );

		return array_values( $result );
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
		$id    = $model->id();
		$class = get_class( $model );
		$data  = $model->validate( true )->data( ModelData::COMPACT );
		$data  = $this->split( $model, $data );

		$this->changed[ $class ][ $id ] = $data;
		unset( $this->deleted[ $class ][ $id ] );
		return $model;
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
		if ( $this->has( $class, $id ) ) {
			unset( $this->data[ $class ][ $id ] );
			unset( $this->changed[ $class ][ $id ] );
			$this->deleted[ $class ][ $id ] = null;
			return true;
		}
		return false;
	}

	/* -------------------------------------------------------------------------
	 * Joining and splitting models.
	 * ---------------------------------------------------------------------- */

	/**
	 * Replaces sum-model ids with the sub-model itself.
	 *
	 * @param class-string<ModelInterface> $class The class name to join.
	 * @param array $data The model data.
	 */
	protected function join( string $class, array $data ): array {
		$properties = $class::properties();

		foreach ( $data as $key => &$value ) {
			$property = $properties[ $key ];

			if ( $class = $property[ PropertyItem::MODEL ] ?? null ) {
				if ( $value && $class::idProperty() ) {
					$get   = fn( int | string $id ): ModelInterface => $this->get( $class, $id );
					$value = match ( $property[ PropertyItem::TYPE ] ) {
						PropertyType::OBJECT => $this->get( $class, $value ),
						PropertyType::ARRAY  => array_filter( array_map( $get, $value ) ),
					};
				}
			}
		}

		return $data;
	}

	/**
	 * Replaces sub-models with their ids before storing.
	 *
	 * @param ModelInterface $model The model to split.
	 * @param array $data The model data.
	 */
	protected function split( ModelInterface $model, array $data ): array {
		$properties = $model::properties();

		foreach ( $data as $id => &$value ) {
			$property = $properties[ $id ];

			if ( $class = $property[ PropertyItem::MODEL ] ?? null ) {
				if ( $value && $class::idProperty() ) {
					$set   = fn( ModelInterface $item ): int | string => $this->set( $item )->id();
					$value = match ( $property[ PropertyItem::TYPE ] ) {
						PropertyType::OBJECT => $this->set( $model[ $id ] )->id(),
						PropertyType::ARRAY  => array_map( $set, $model[ $id ] )
					};
				}
			}
		}

		return $data;
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
		return true;
	}

	/**
	 * Flushes model data to permanent storage if necessary.
	 *
	 * @return bool True if data changes exists and were saved, false otherwise.
	 */
	public function flush(): bool {
		return $this->save();
	}

	/* -------------------------------------------------------------------------
	 * Class initialization
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array|object $options
	 */
	public function init( array | object $options ): void {
		$default = [
			'force_add'    => false,
			'force_update' => false,
			'json_encode'  => JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
			'json_decode'  => JSON_THROW_ON_ERROR,
		];

		$this->options = (object) array_replace( $default, (array) $options );
	}

	/* -------------------------------------------------------------------------
	 * File handling
	 * ---------------------------------------------------------------------- */

	/**
	 * Reads a JSON source file into the data store.
	 *
	 * @return array The JSON decoded data.
	 */
	public function read( $source ): array {
		if ( is_readable( $source ) ) {
			if ( $content = file_get_contents( $source ) ) {
				return json_decode( $content, true, 64, $this->options->json_decode );
			}
		}
		return [];
	}

	/**
	 * Imports data from a source
	 *
	 * @param JsonStore|array|string $source The source containing the data to import.
	 */
	public function import( mixed $source ): void {
		if ( is_array( $source ) ) {
			$data = $source;
		} elseif ( $source instanceof self ) {
			$data = $source->export();
		} elseif ( is_readable( $source ) ) {
			$data = $this->read( $source );
		} elseif ( is_string( $source ) ) {
			if ( $result = json_decode( $source, true ) && JSON_ERROR_NONE == json_last_error() ) {
				$data = $result;
			}
		}

		/** @var ModelInterface $class */
		foreach ( $data ?? [] as $class => $pairs ) {
			foreach ( $pairs as $inst ) {
				$this->set( $class::create( $inst ) );
			}
		}
	}

	public function export(): array {
		return $this->merge( $this->data );
	}

	/**
	 * Writes the data store to a JSON file.
	 *
	 * @param array $data The data to be stored as a JSON source.
	 *
	 * @return bool True if successful, false otherwise.
	 */
	public function write( $source, array $data ): bool {
		if ( $content = json_encode( $data, $this->options->json_encode, 64 ) ) {
			return is_int( file_put_contents( $source, $content, LOCK_EX ) );
		}
		return false;
	}

	/**
	 * Merges the data with the changes.
	 *
	 * @param array $data The data to be merged.
	 */
	public function merge( array $data ): array {
		foreach ( $this->changed as $class => $models ) {
			foreach ( $models as $id => $model ) {
				$data[ $class ][ $id ] = $model;
			}
		}

		foreach ( $this->deleted as $class => $models ) {
			foreach ( $models as $id => $model ) {
				unset( $data[ $class ][ $id ] );
			}
		}

		return $data;
	}

	public function open(): void {
		$this->data = $this->read( $this->source );
	}

	/**
	 * Saves the updated data to a JSON file.
	 *
	 * @return bool True if data changes exists and were saved, false otherwise.
	 */
	public function save(): bool {
		if ( empty( $this->changed ) && empty( $this->deleted ) ) {
			return false;
		}

		$data = $this->read( $this->source );
		$data = $this->merge( $data );
		$this->write( $this->source, $data );

		$this->data    = $data;
		$this->changed = [];
		$this->deleted = [];

		return true;
	}

	/* -------------------------------------------------------------------------
	 * Utils
	 * ---------------------------------------------------------------------- */

	public static function sort( array &$data ): bool {
		return ksort( $data, SORT_NATURAL );
	}
}
