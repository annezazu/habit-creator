<?php
/**
 * Creates a pre-populated draft for a recurring pattern and redirects the
 * user to the editor.
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
		$patterns = Pattern_Detector::patterns_for_user( $user_id );
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
		$best  = $pattern['best_post'];
		$year  = (int) gmdate( 'Y' );
		$title = sprintf( '%s — %d', (string) $best['title'], $year );

		$intro = null;
		if ( class_exists( __NAMESPACE__ . '\\AI_Enhancer' ) ) {
			$intro = AI_Enhancer::generate_draft_intro( $pattern );
		}
		if ( $intro === null ) {
			$intro = sprintf(
				/* translators: %s: previous post title */
				__( 'Last time around the calendar I wrote about %s. Here\'s where things stand this year.', 'habit-creator' ),
				(string) $best['title']
			);
		}

		$permalink = (string) get_permalink( (int) $best['id'] );
		$back_link = sprintf(
			/* translators: 1: previous post URL, 2: previous post title */
			__( 'Previously: <a href="%1$s">%2$s</a>', 'habit-creator' ),
			esc_url( $permalink ),
			esc_html( (string) $best['title'] )
		);

		$content_blocks  = "<!-- wp:paragraph -->\n<p>" . esc_html( $intro ) . "</p>\n<!-- /wp:paragraph -->\n\n";
		$content_blocks .= "<!-- wp:paragraph -->\n<p>" . $back_link . "</p>\n<!-- /wp:paragraph -->";

		$args = [
			'post_status'  => 'draft',
			'post_author'  => $user_id,
			'post_title'   => $title,
			'post_content' => $content_blocks,
			'post_type'    => 'post',
			'meta_input'   => [
				'_habit_creator_source_post' => (int) $best['id'],
				'_habit_creator_pattern_key' => (string) $pattern['key'],
			],
		];

		$tags = array_column( (array) $best['tags'], 'id' );
		if ( $tags ) {
			$args['tags_input'] = $tags;
		}
		$cats = array_column( (array) $best['cats'], 'id' );
		if ( $cats ) {
			$args['post_category'] = $cats;
		}

		return wp_insert_post( $args, true );
	}
}
