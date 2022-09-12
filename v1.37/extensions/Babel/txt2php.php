<?php
/**
 * txt2php: Converts the text file of ISO codes to a PHP static array definition.
 *
 * Usage: php txt2php.php
 */

 use Wikimedia\StaticArrayWriter;

$dir = __DIR__;
$IP = "$dir/../..";

require_once "$IP/maintenance/CommandLineInc.php";

$dir = __DIR__;

$names = [];
$codes = [];
$fr = fopen( "$dir/codes.txt", 'r' );

while ( true ) {
	$line = fgets( $fr );
	if ( !$line ) {
		break;
	}

	// Format is code1 code2 "language name"
	$line = explode( ' ', $line, 3 );
	$iso1 = trim( $line[0] );
	$iso3 = trim( $line[1] );
	// Strip quotes
	$name = substr( trim( $line[2] ), 1, -1 );
	if ( $iso1 !== '-' ) {
		$codes[ $iso1 ] = $iso1;
		if ( $iso3 !== '-' ) {
			$codes[ $iso3 ] = $iso1;
		}
		$names[ $iso1 ] = $name;
		$names[ $iso3 ] = $name;
	} elseif ( $iso3 !== '-' ) {
		$codes[ $iso3 ] = $iso3;
		$names[ $iso3 ] = $name;
	}
}

fclose( $fr );

$writer = new StaticArrayWriter();
$header = 'This file is generated by txt2php.php. Do not edit it directly.';
$code = $writer->create( $names, $header );
file_put_contents( "$dir/names.php", $code );

$code = $writer->create( $codes, $header );
file_put_contents( "$dir/codes.php", $code );
