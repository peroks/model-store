<?php namespace Peroks\Model\Store;

use Peroks\Model\Property;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;

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

	/**
	 * Prepare a property value before inserting it into the model.
	 *
	 * @param mixed $value The property value.
	 * @param Property|array $property The property definition.
	 *
	 * @return mixed The prepared property value.
	 */
	protected static function prepareProperty( mixed $value, Property | array $property ): mixed {
		$value = parent::prepareProperty( $value, $property );
		$type  = $property[ PropertyItem::TYPE ] ?? null;

		if ( PropertyType::INTEGER === $type ) {
			if ( is_string( $value ) && is_numeric( $value ) ) {
				return (int) $value;
			}
		} elseif ( PropertyType::FLOAT === $type ) {
			if ( is_string( $value ) && is_numeric( $value ) ) {
				return (float) $value;
			}
		} elseif ( PropertyType::BOOL === $type ) {
			if ( is_string( $value ) && is_numeric( $value ) ) {
				$value = (int) $value;
			}
			if ( is_int( $value ) ) {
				return (bool) $value;
			}
		}

		return $value;
	}
}
