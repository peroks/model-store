<?php namespace Peroks\Model\Store;

use Peroks\Model\Property;
use Peroks\Model\PropertyItem;
use Peroks\Model\PropertyType;

/**
 * Utility and helper class.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */
class Utils extends \Peroks\Model\Utils {

	/**
	 * Checks if a model property corresponds to a table column.
	 *
	 * @param Property|array $property The property.
	 *
	 * @return bool
	 */
	public static function isColumn( Property | array $property ): bool {
		$type = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;

		// Storing functions is not supported.
		if ( PropertyType::FUNCTION === $type ) {
			return false;
		}

		// The reference is stored in the foreign model.
		if ( isset( $property[ PropertyItem::MATCH ] ) ) {
			return false;
		}

		// Relations are stored in a separate relation table.
		if ( static::isRelation( $property ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks if a model property corresponds to a relation table.
	 *
	 * @param Property|array $property The property.
	 *
	 * @return bool
	 */
	public static function isRelation( Property | array $property ): bool {
		$type = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;

		if ( PropertyType::ARRAY === $type && empty( $property[ PropertyItem::MATCH ] ) ) {
			$model = $property[ PropertyItem::MODEL ] ?? null;

			if ( static::isModel( $model ) && $model::idProperty() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if a model property needs a foreign key.
	 *
	 * @param Property|array $property The property.
	 *
	 * @return bool
	 */
	public static function needsForeignKey( Property | array $property ): bool {
		$type = $property[ PropertyItem::TYPE ] ?? PropertyType::MIXED;

		if ( PropertyType::ARRAY !== $type && empty( $property[ PropertyItem::MATCH ] ) ) {
			$model   = $property[ PropertyItem::MODEL ] ?? null;
			$foreign = $property[ PropertyItem::FOREIGN ] ?? $model;

			if ( static::isModel( $foreign ) && $foreign::idProperty() ) {
				return true;
			}
		}

		return false;
	}
}
