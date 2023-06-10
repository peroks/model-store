<?php namespace Peroks\Model\Store;

/**
 * The store model class.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */
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
