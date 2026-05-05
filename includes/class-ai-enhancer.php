<?php
/**
 * Optional AI enhancement layer.
 *
 * One touch-point: a list of topic-specific *starter questions* offered as
 * scaffolding inside a new draft. Questions, not prose — they prompt the
 * writer to think; they don't write for them.
 *
 * Gated by:
 *   (a) function_exists( 'wp_ai_client_prompt' )
 *   (b) at least one Connector with type === 'ai_provider'
 *   (c) the user setting habit_creator_use_ai
 *
 * Any failure or empty response falls back silently to deterministic copy.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class AI_Enhancer {

	public static function is_available(): bool {
		if ( ! Settings::ai_enabled() ) {
			return false;
		}
		if ( ! function_exists( 'wp_get_connectors' ) || ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		foreach ( (array) wp_get_connectors() as $connector ) {
			if ( ( $connector['type'] ?? '' ) === 'ai_provider' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Topic-specific starter questions that scaffold a new draft. The user
	 * answers them; they replace neither the user's voice nor the post body.
	 *
	 * Returns array of question strings (3-5 typical) or null when unavailable.
	 *
	 * @param array<string, mixed> $pattern
	 * @return array<int, string>|null
	 */
	public static function generate_writing_prompts( array $pattern ): ?array {
		if ( ! self::is_available() ) {
			return null;
		}

		$best   = $pattern['best_post'];
		$prompt = sprintf(
			'Generate 4 short, specific questions to help a blogger think through a new post that revisits a recurring topic. The topic is "%1$s". Their previous post on this thread was titled "%2$s". The questions should help them notice what has changed, what worked, and what is new — without prescribing a structure or putting words in their mouth. Return ONLY the questions, one per line, no numbering, no bullets, no preamble. Maximum 12 words per question.',
			(string) $pattern['label'],
			(string) $best['title']
		);

		$raw = self::run_prompt( $prompt );
		if ( $raw === null ) {
			return null;
		}

		$lines = array_values( array_filter(
			array_map(
				static fn( $line ) => trim( preg_replace( '/^[\d\.\-\*\)\s]+/', '', $line ) ?? '' ),
				explode( "\n", $raw )
			),
			static fn( $line ) => $line !== '' && strlen( $line ) > 5
		) );

		return $lines ? array_slice( $lines, 0, 5 ) : null;
	}

	/**
	 * Single chokepoint for AI calls. generate_text() returns either a string
	 * or a WP_Error per the wp-ai-client contract; we accept only non-empty
	 * strings and fall back silently in every other case.
	 *
	 * Temperature is intentionally not set — current Claude models (Claude 4+)
	 * reject the parameter as deprecated, and provider defaults are fine for
	 * our short-form prompts.
	 */
	private static function run_prompt( string $prompt ): ?string {
		try {
			$response = wp_ai_client_prompt( $prompt )->generate_text();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$text = is_string( $response ) ? trim( $response ) : '';
		return $text !== '' ? $text : null;
	}
}
