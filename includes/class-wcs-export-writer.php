<?php
/**
 * Subscription Export CSV Writer Class
 *
 * @since 1.0
 */
class WCS_Export_Writer {

	private static $file = null;

	public static $headers = array();

	/**
	 * Init function for the export writer. Opens the writer and writes the given CSV headers to the output buffer.
	 *
	 * @since 1.0
	 * @param array $header
	 */
	public static function write_headers( $headers ) {
		self::$file    = fopen( 'php://output', 'w' );
		ob_start();

		self::$headers = $headers;
	}

	/**
	 * Write line to CSV
	 *
	 * @since 1.0
	 * @param array $row
	 */
	public static function write( $row ) {

		if ( empty( $row ) ) {
			return;
		}

		$data = array();

		foreach ( self::$headers as $header_key => $_ ) {

			if ( ! isset( $row[ $header_key ] ) ) {
				$row[ $header_key ] = '';
			}

			// strict string comparison, as values like '0' are valid
			$value  = ( '' !== $row[ $header_key ] ) ? $row[ $header_key ] : '';
			$data[] = $value;
		}

		fputcsv( self::$file, $data, ',', '"' );
	}

}