<?php
/**
 * Optional AI enhancement layer.
 *
 * Detects whether a registered Connector advertises `type === 'ai_provider'`
 * (added in WordPress 7.0) and, if so, uses the AI Client to generate a
 * warmer, more personal nudge headline and a draft opening paragraph.
 *
 * The plugin must remain fully functional when none of these APIs exist.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class AI_Enhancer {

	public static function is_available(): bool {
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
	 * Replace each pattern's plain headline with an AI-written nudge.
	 * Falls back silently to the deterministic headline on any error.
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
			'In one warm, encouraging sentence (under 30 words), nudge a blogger to write a follow-up post. They have written %1$d posts on the topic "%2$s" across %1$d different years. Their most recent on this topic was titled "%3$s" and received %4$d comments. Do not use exclamation points. Do not be sycophantic.',
			$year_count,
			(string) $pattern['label'],
			(string) $best['title'],
			(int) $best['comments']
		);

		try {
			$response = wp_ai_client_prompt( $prompt )
				->using_temperature( 0.4 )
				->generate_text();
		} catch ( \Throwable $e ) {
			return null;
		}

		$text = is_string( $response ) ? trim( $response ) : '';
		return $text !== '' ? $text : null;
	}

	/**
	 * Generate an opening paragraph for the new draft. Returns null if AI is unavailable.
	 *
	 * @param array<string, mixed> $pattern
	 */
	public static function generate_draft_intro( array $pattern ): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		$best   = $pattern['best_post'];
		$prompt = sprintf(
			'Write a short opening paragraph (3-4 sentences) for a blog post that revisits a recurring topic. The author previously wrote "%1$s" on the same theme. Frame this new post as a yearly update or continuation, but do not assume what has changed. Leave a placeholder like [add this year\'s update here] where specifics should go. Avoid clichés like "time flies" or "another year".',
			(string) $best['title']
		);

		try {
			$response = wp_ai_client_prompt( $prompt )
				->using_temperature( 0.6 )
				->generate_text();
		} catch ( \Throwable $e ) {
			return null;
		}

		$text = is_string( $response ) ? trim( $response ) : '';
		return $text !== '' ? $text : null;
	}
}
