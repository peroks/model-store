<?php
/**
 * Store Cache.
 *
 * This class can be used as a caching middleware between an application and a
 * data store. Calls to "get" and "list" will be delivered from cache if
 * available. Calls to "set" will be dropped if the model has not changed.
 * Setting and deleting models will automatically clear the complete cache.
 *
 * Models are cached as json strings. This makes them easy to compare, serialize
 * and deserialize. It also ensures that changes to the model don't affect the
 * cached version.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

/**
 * Store Cache.
 */
class Cache implements StoreInterface {
	/**
	 * @var string Hash separator.
	 */
	public const SEPARATOR = '|';

	/**
	 * @var StoreInterface The actual data store containing the models.
	 */
	public StoreInterface $store;

	/**
	 * @var array[] The internal cache.
	 */
	protected array $cache = [];

	/**
	 * @var array[] Cached model ids from method calls.
	 */
	protected array $hash = [];

	/**
	 * Constructor
	 *
	 * @param StoreInterface $store The actual data store.
	 */
	public function __construct( StoreInterface $store ) {
		$this->store = $store;
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
	public function has( string $class, int|string $id ): bool {
		if ( isset( $this->cache[ $class ][ $id ] ) ) {
			return true;
		}
		return (bool) $this->setCache( $this->store->get( $class, $id ) );
	}

	/**
	 * Gets a model matching the given id from the cache or data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return ModelInterface|null The matching model or null if not found.
	 */
	public function get( string $class, int|string $id ): ModelInterface|null {
		if ( $cached = $this->cache[ $class ][ $id ] ?? null ) {
			return new $class( $cached );
		}
		return $this->setCache( $this->store->get( $class, $id ) );
	}

	/**
	 * Gets a list of models matching the given ids from the cache or data store.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int[]|string[] $ids An array of model ids.
	 *
	 * @return ModelInterface[] An array of matching models.
	 */
	public function list( string $class, array $ids = [] ): array {
		sort( $ids );
		$parts  = array_merge( [ 'list', $class ], array_values( $ids ) );
		$hash   = md5( join( static::SEPARATOR, $parts ) );
		$hashed = $this->hash[ $hash ] ?? null;

		if ( isset( $hashed ) && empty( $hashed ) ) {
			return [];
		}

		if ( empty( $ids ) ) {
			$ids = $hashed ?? [];
		}

		if ( $ids ) {
			$cached = array_keys( $this->cache[ $class ] ?? [] );

			if ( count( $ids ) === count( array_intersect( $ids, $cached ) ) ) {
				$result = array_map( function ( int|string $id ) use ( $class ) {
					return new $class( $this->cache[ $class ][ $id ] );
				}, $ids );
			}
		}

		if ( ! isset( $result ) ) {
			$result = array_map( [ $this, 'setCache' ], $this->store->list( $class, $ids ) );
		}

		$this->hash[ $hash ] = array_keys( $result );
		return $result;
	}

	/**
	 * Gets a filtered list of models from the data store and adds them to the cache.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param array $filter Properties (key/value pairs) to match the stored models.
	 *
	 * @return ModelInterface[] An array of models.
	 */
	public function filter( string $class, array $filter = [] ): array {
		if ( empty( $filter ) ) {
			return $this->list( $class );
		}

		ksort( $filter );
		$parts  = array_merge( [ 'filter', $class ], array_keys( $filter ), array_values( $filter ) );
		$hash   = md5( join( static::SEPARATOR, $parts ) );
		$hashed = $this->hash[ $hash ] ?? null;
		$ids    = $hashed ?? [];

		if ( isset( $hashed ) && empty( $hashed ) ) {
			return [];
		}

		if ( $ids ) {
			return $this->list( $class, $ids );
		}

		$result = array_map( [ $this, 'setCache' ], $this->store->filter( $class, $filter ) );

		$this->hash[ $hash ] = array_keys( $result );
		return $result;
	}

	/* -------------------------------------------------------------------------
	 * Updating and deleting models
	 * ---------------------------------------------------------------------- */

	/**
	 * Saves and validates changed models in the data store and clears the cache.
	 *
	 * If the model is identical to a cached model, it's just returned without
	 * hitting the data store. In this case, the cache is not cleared.
	 *
	 * @param ModelInterface $model The model to store.
	 *
	 * @return ModelInterface The stored model.
	 */
	public function set( ModelInterface $model ): ModelInterface {
		if ( $cached = $this->cache[ $model::class ][ $model->id() ] ?? null ) {
			if ( $cached === (string) $model ) {
				return $model;
			}
		}

		$this->clearCache();
		$this->setCache( $model );
		return $this->store->set( $model );
	}

	/**
	 * Deletes a model from the data store and clears the cache.
	 *
	 * @param class-string<ModelInterface> $class The model class name.
	 * @param int|string $id The model id.
	 *
	 * @return bool True if the model existed, false otherwise.
	 */
	public function delete( string $class, int|string $id ): bool {
		$this->clearCache();
		return $this->store->delete( $class, $id );
	}

	/* -------------------------------------------------------------------------
	 * Data store handling
	 * ---------------------------------------------------------------------- */

	/**
	 * Gets information about the model store.
	 *
	 * @param string $name The property name to get information about.
	 */
	public function info( string $name ): mixed {
		return $this->store->info( $name );
	}

	/**
	 * Builds a data store if necessary and clears the cache.
	 *
	 * @param array $models The models to add to the data store.
	 * @param array $options An assoc array of options.
	 *
	 * @return bool
	 */
	public function build( array $models, array $options = [] ): bool {
		$this->clearCache();
		return $this->store->build( $models, $options );
	}

	/**
	 * Flushes model data to permanent storage if necessary.
	 *
	 * @return bool True if data changes exists and were saved, false otherwise.
	 */
	public function flush(): bool {
		return $this->store->flush();
	}

	/* -------------------------------------------------------------------------
	 * Cache methods.
	 * ---------------------------------------------------------------------- */

	/**
	 * Clears the cache.
	 */
	public function clearCache(): void {
		$this->cache = [];
		$this->hash  = [];
	}

	/**
	 * Adds a model to the cache or updates it if already cached.
	 *
	 * @param ModelInterface|null $model The model to cache.
	 *
	 * @return ModelInterface|null The cached model.
	 */
	public function setCache( ModelInterface|null $model ): ModelInterface|null {
		if ( $model ) {
			$this->cache[ $model::class ][ $model->id() ] = (string) $model;
			return $model;
		}
		return null;
	}
}
