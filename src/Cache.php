<?php declare( strict_types = 1 );

namespace Peroks\Model\Store;

/**
 * Cache for the Octopus data store.
 *
 * @author Per Egil Roksvaag
 * @copyright Silverscreen Tours GmbH
 */
class Cache implements StoreInterface {
	protected StoreInterface $store;
	protected array $cache = [];

	public function __construct( StoreInterface $store ) {
		$this->store = $store;
	}

	protected function clearCache(): void {
		$this->cache = [];
	}

	protected function setCache( ModelInterface | null $model ): ModelInterface | null {
		if ( $model ) {
			$this->cache[ $model::class ][ $model->id() ] = (string) $model;
			return $model;
		}
		return null;
	}

	protected function inCache( ModelInterface | null $model ): bool {
		if ( $model ) {
			return isset( $this->cache[ $model::class ][ $model->id() ] );
		}
		return false;
	}

	public function has( string $class, int | string $id ): bool {
		return $this->store->has( $class, $id );
	}

	public function get( string $class, int | string $id ): ModelInterface | null {
		if ( empty( $this->cache[ $class ][ $id ] ) ) {
			return $this->setCache( $this->store->get( $class, $id ) );
		}
		return new $class( $this->cache[ $class ][ $id ] );
	}

	public function list( string $class, array $ids ): array {
		$cached = array_keys( $this->cache[ $class ] );

		if ( array_intersect( $ids, $cached ) === $ids ) {
			return array_map( function( int | string $id ) use ( $class ): ModelInterface {
				return new $class( $this->cache[ $class ][ $id ] );
			}, $ids );
		}

		return array_map( function( ModelInterface $model ): ModelInterface {
			return $this->setCache( $model );
		}, $this->store->list( $class, $ids ) );
	}

	public function filter( string $class, array $filter = [] ): array {
		return array_map( function( ModelInterface $model ): ModelInterface {
			return $this->setCache( $model );
		}, $this->store->filter( $class, $filter ) );
	}

	public function all( string $class ): array {
		return array_map( function( ModelInterface $model ): ModelInterface {
			return $this->setCache( $model );
		}, $this->store->all( $class ) );
	}

	public function set( ModelInterface $model ): ModelInterface {
		if ( $this->inCache( $model ) ) {
			if ( strval( $model ) === $this->cache[ $model::class ][ $model->id() ] ) {
				return $model;
			}
		}

		$this->clearCache();
		$this->setCache( $model );
		return $this->store->set( $model );
	}

	public function delete( string $class, int | string $id ): bool {
		$this->clearCache();
		return $this->store->delete( $class, $id );
	}

	public function build( array $models, array $options = [] ): bool {
		$this->clearCache();
		return $this->store->build( $models, $options );
	}

	public function flush(): bool {
		return $this->store->flush();
	}
}
