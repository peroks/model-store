<?php
/**
 * Class for storing and retrieving models from a PDO database.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 *
 * @noinspection PhpComposerExtensionStubsInspection, SqlDialectInspection
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

/**
 * Class for storing and retrieving models from a PDO database.
 */
class PdoJsonStore extends SqlJsonStore implements StoreInterface {
	use PdoTrait;
}
