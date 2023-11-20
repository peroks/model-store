<?php
/**
 * The store model class.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

class Model extends \Peroks\Model\Model implements ModelInterface {

	/**
	 * Gets the model name for reading and writing model data.
	 *
	 * @return string The model storage name.
	 */
	public static function name(): string {
		return static::class;
	}
}
