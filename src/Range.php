<?php
/**
 * Range helper class.
 *
 * @author Per Egil Roksvaag
 * @copyright Per Egil Roksvaag
 * @license MIT
 */

declare( strict_types = 1 );
namespace Peroks\Model\Store;

/**
 * Range helper class.
 */
class Range {

	/**
	 * @var string|int The start of the range.
	 */
	public readonly string|int $from;

	/**
	 * @var string|int The end of the range.
	 */
	public readonly string|int $to;

	/**
	 * Constructor.
	 *
	 * @param string|int $from The start of the range.
	 * @param string|int $to The end of the range.
	 */
	public function __construct( string|int $from, string|int $to ) {
		$this->from = $from;
		$this->to   = $to;
	}
}
