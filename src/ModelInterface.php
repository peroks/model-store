<?php
/**
 * An extended model interface for use in data stores.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

/**
 * An extended model interface for use in data stores.
 */
interface ModelInterface extends \Peroks\Model\ModelInterface {

	/**
	 * Gets the model name for reading and writing model data.
	 *
	 * @return string The model storage name.
	 */
	public static function name(): string;
}
