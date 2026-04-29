<?php
/**
 * Optional AI enhancement layer.
 *
 * Two narrowly-scoped touch-points only:
 *   1. The dashboard encouragement line — one short sentence in the widget UI.
 *      Never enters a post.
 *   2. A list of topic-specific *starter questions* offered as scaffolding
 *      inside a new draft. Questions, not prose. They prompt the writer to
 *      think; they don't write for them.
 *
 * Both are gated by:
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
	 * Replace each pattern's encouragement with an AI-written one when available.
	 *
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich( array $patterns ): array {
		foreach ( $patterns as &$pattern ) {
			$nudge = self::generate_nudge( $pattern );
			if ( $nudge !== null ) {
				$pattern['encouragement'] = $nudge;
				$pattern['ai_enhanced']   = true;
			}
		}
		unset( $pattern );
		return $patterns;
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function generate_nudge( array $pattern ): ?string {
		$best       = $pattern['best_post'];
		$year_count = count( $pattern['years'] );
		$prompt     = sprintf(
			'In one warm, encouraging sentence (under 25 words), nudge a blogger to write a follow-up post. They have written %1$d posts on the topic "%2$s" across %1$d different years. Their most recent on this topic was titled "%3$s" and received %4$d comments. No exclamation points. No flattery. Reference the streak or the readers, not the topic name.',
			$year_count,
			(string) $pattern['label'],
			(string) $best['title'],
			(int) $best['comments']
		);

		return self::run_prompt( $prompt );
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
