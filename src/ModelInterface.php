<?php namespace Peroks\Model\Store;

/**
 * The store model interface.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */
interface ModelInterface extends \Peroks\Model\ModelInterface {

	/**
	 * Gets the model name for reading and writing model data.
	 *
	 * @return string The model storage name.
	 */
	public static function name(): string;
}
