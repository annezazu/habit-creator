<?php
/**
 * Creates a pre-populated draft for a recurring pattern. The draft is a
 * *scaffold*: a few starter questions and a backlink to the previous post.
 * Habit Creator never writes the post body for the user.
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

		$ai_prompts = null;
		if ( class_exists( __NAMESPACE__ . '\\AI_Enhancer' ) ) {
			$ai_prompts = AI_Enhancer::generate_writing_prompts( $pattern );
		}
		$used_ai   = $ai_prompts !== null;
		$questions = $used_ai ? $ai_prompts : self::default_questions( $pattern );

		$content = self::build_content( $pattern, $questions, $used_ai );

		$args = [
			'post_status'  => 'draft',
			'post_author'  => $user_id,
			'post_title'   => $title,
			'post_content' => $content,
			'post_type'    => 'post',
			'meta_input'   => [
				'_habit_creator_source_post' => (int) $best['id'],
				'_habit_creator_pattern_key' => (string) $pattern['key'],
				'_habit_creator_used_ai'     => $used_ai ? '1' : '0',
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

	/**
	 * Generic, deterministic starter questions used when AI is unavailable
	 * or disabled. Topic-agnostic on purpose.
	 *
	 * @param array<string, mixed> $pattern
	 * @return array<int, string>
	 */
	private static function default_questions( array $pattern ): array {
		return [
			__( 'What\'s changed since the last time you wrote about this?', 'habit-creator' ),
			__( 'Did anything you tried last year not work the way you expected?', 'habit-creator' ),
			__( 'What\'s new this year that wasn\'t on your radar before?', 'habit-creator' ),
			__( 'Who would benefit most from reading the updated version?', 'habit-creator' ),
		];
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @param array<int, string>   $questions
	 */
	private static function build_content( array $pattern, array $questions, bool $used_ai ): string {
		$best       = $pattern['best_post'];
		$prior_url  = (string) get_permalink( (int) $best['id'] );
		$prior_html = sprintf(
			/* translators: 1: previous post URL, 2: previous post title */
			__( 'Continuing from last year\'s post: <a href="%1$s">%2$s</a>.', 'habit-creator' ),
			esc_url( $prior_url ),
			esc_html( (string) $best['title'] )
		);

		$intro = $used_ai
			? __( 'Starter questions, suggested by your AI provider. Edit or delete any that don\'t fit — then write in your own voice below.', 'habit-creator' )
			: __( 'A few starter questions to help you get going. Edit or delete any that don\'t fit — then write in your own voice below.', 'habit-creator' );

		$out  = "<!-- wp:paragraph -->\n<p>" . $prior_html . "</p>\n<!-- /wp:paragraph -->\n\n";
		$out .= "<!-- wp:heading {\"level\":2} -->\n<h2>" . esc_html__( 'A few things to think about', 'habit-creator' ) . "</h2>\n<!-- /wp:heading -->\n\n";
		$out .= "<!-- wp:paragraph -->\n<p><em>" . esc_html( $intro ) . "</em></p>\n<!-- /wp:paragraph -->\n\n";

		$list_items = '';
		foreach ( $questions as $q ) {
			$list_items .= '<li>' . esc_html( (string) $q ) . "</li>\n";
		}
		$out .= "<!-- wp:list -->\n<ul>\n" . $list_items . "</ul>\n<!-- /wp:list -->\n\n";

		$out .= "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n\n";
		$out .= "<!-- wp:paragraph -->\n<p>" . esc_html__( '[ Start writing here. ]', 'habit-creator' ) . "</p>\n<!-- /wp:paragraph -->";

		return $out;
	}
}
