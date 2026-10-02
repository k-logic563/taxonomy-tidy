<?php
/**
 * JSON persistence helper.
 *
 * @package TermSteward
 */

declare(strict_types=1);

namespace TermSteward\Infrastructure\Persistence;

/**
 * Encodes and decodes structured operation data consistently.
 */
final class Json {
	/**
	 * Encodes structured data for database storage.
	 *
	 * @param array<string, mixed> $value Structured data.
	 * @throws PersistenceException When the value cannot be encoded.
	 */
	public static function encode( array $value ): string {
		$encoded = wp_json_encode( $value );

		if ( false === $encoded ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'Operation data could not be encoded as JSON.' );
		}

		return $encoded;
	}

	/**
	 * Decodes structured data loaded from the database.
	 *
	 * @param string $value Stored JSON value.
	 * @return array<string, mixed>
	 * @throws \JsonException|PersistenceException When JSON is invalid or does not contain an array.
	 */
	public static function decode( string $value ): array {
		$decoded = json_decode( $value, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception; not HTML output.
			throw new PersistenceException( 'Stored operation data must decode to an array.' );
		}

		return $decoded;
	}
}
