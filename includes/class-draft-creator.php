<?php
/**
 * Creates a new draft for a recurring pattern.
 *
 * Default behaviour is a blank canvas — no title, no body, just the
 * pattern's primary tag/category preset. When the "Uses AI" toggle is
 * on and an AI provider is registered, AI_Enhancer::generate_writing_
 * prompts() drops a short list of topic-specific starter questions
 * into the body for the writer to chew on. Questions only; never prose.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Draft_Creator {

	public static function handle_ajax(): void {
		check_ajax_referer( NONCE_ACTION );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot create posts.', 'habit-creator' ) ], 403 );
		}

		$pattern_key = isset( $_POST['pattern_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pattern_key'] ) ) : '';
		if ( $pattern_key === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing pattern.', 'habit-creator' ) ], 400 );
		}

		$user_id  = get_current_user_id();
		$is_mock  = ! empty( $_POST['is_mock'] );
		$patterns = $is_mock
			? Pattern_Detector::mock_patterns_for_user( $user_id )
			: Pattern_Detector::patterns_for_user( $user_id );
		$pattern  = null;
		foreach ( $patterns as $candidate ) {
			if ( $candidate['key'] === $pattern_key ) {
				$pattern = $candidate;
				break;
			}
		}

		if ( $pattern === null ) {
			wp_send_json_error( [ 'message' => __( 'Pattern no longer available.', 'habit-creator' ) ], 404 );
		}

		$post_id = self::insert_draft( $pattern, $user_id );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ], 500 );
		}

		wp_send_json_success( [
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		] );
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return int|\WP_Error
	 */
	private static function insert_draft( array $pattern, int $user_id ) {
		$content = '';
		$used_ai = false;
		if ( class_exists( __NAMESPACE__ . '\\AI_Enhancer' ) && AI_Enhancer::is_available() ) {
			$questions = AI_Enhancer::generate_writing_prompts( $pattern );
			if ( $questions ) {
				$content = self::questions_block( $questions );
				$used_ai = true;
			}
		}

		$args = [
			// auto-draft is WP's canonical "new empty post" status — the
			// same one that /wp-admin/post-new.php creates. The block
			// editor auto-converts to 'draft' the moment the user types.
			'post_status'  => 'auto-draft',
			'post_author'  => $user_id,
			'post_title'   => (string) ( $pattern['topic'] ?? '' ),
			'post_content' => $content,
			'post_type'    => 'post',
			'meta_input'   => [
				'_habit_creator_pattern_key' => (string) $pattern['key'],
				'_habit_creator_used_ai'     => $used_ai ? '1' : '0',
			],
		];

		self::apply_primary_taxonomy( $args, $pattern );

		// wp_insert_post rejects posts with empty title/content/excerpt
		// via the `wp_insert_post_empty_content` filter regardless of
		// status. Suppress it for just this insertion — when the toggle
		// is off we genuinely want a blank canvas with only a tag preset.
		add_filter( 'wp_insert_post_empty_content', '__return_false' );
		$post_id = wp_insert_post( $args, true );
		remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		return $post_id;
	}

	/**
	 * Wrap the AI-generated questions in a block-editor list block so the
	 * user lands on a clean bulleted scaffold they can edit, expand, or
	 * delete entirely.
	 *
	 * @param array<int, string> $questions
	 */
	private static function questions_block( array $questions ): string {
		$items = '';
		foreach ( $questions as $q ) {
			$items .= '<li>' . esc_html( (string) $q ) . "</li>\n";
		}
		return "<!-- wp:list -->\n<ul>\n" . $items . "</ul>\n<!-- /wp:list -->";
	}

	/**
	 * Assigns only the pattern's *primary* taxonomy term to the new draft
	 * (the tag or category that defined the streak) — not every tag the
	 * source post happened to carry. Phrase patterns don't map to a
	 * taxonomy, so they're a no-op here.
	 *
	 * @param array<string, mixed> $args     wp_insert_post args, mutated in place.
	 * @param array<string, mixed> $pattern
	 */
	private static function apply_primary_taxonomy( array &$args, array $pattern ): void {
		$parts = explode( ':', (string) $pattern['key'], 2 );
		$type  = $parts[0] ?? '';
		$slug  = $parts[1] ?? '';
		if ( $slug === '' ) {
			return;
		}

		if ( $type === 'tag' ) {
			$term = get_term_by( 'slug', $slug, 'post_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['tags_input'] = [ $term->name ];
			}
			return;
		}

		if ( $type === 'category' ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$args['post_category'] = [ (int) $term->term_id ];
			}
		}
	}
}
