<?php
/**
 * Renders the Habit Creator dashboard widget.
 *
 * Each pattern is presented as a habit-in-progress: a headline framing
 * the next step ("Create a 3 year habit around X"), a body sentence
 * explaining the streak and timing, then a vertical streak — one row
 * per past period (🔥 title · ago_label) ending with the CTA row that
 * starts the next draft.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Widget {

	private const DISMISSED_USERMETA = 'habit_creator_dismissed';

	/**
	 * Capability required to flip the "Enhance with AI" toggle. Matches
	 * the capability needed to update the underlying site option.
	 */
	private const TOGGLE_CAP = 'manage_options';

	public static function register(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'habit_creator_widget',
			__( 'Habit Creator', 'habit-creator' ),
			[ self::class, 'render' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( $hook !== 'index.php' ) {
			return;
		}
		wp_enqueue_style(
			'habit-creator',
			plugins_url( 'assets/widget.css', dirname( __FILE__ ) ),
			[],
			VERSION
		);
		wp_enqueue_script(
			'habit-creator',
			plugins_url( 'assets/widget.js', dirname( __FILE__ ) ),
			[ 'wp-i18n' ],
			VERSION,
			true
		);
		wp_localize_script( 'habit-creator', 'HabitCreator', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( NONCE_ACTION ),
		] );
	}

	public static function render(): void {
		$is_mock = ! empty( $_GET['habit_creator_mock'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="habit-creator">';
		self::render_ai_toggle();
		echo '<div class="habit-creator-body-wrap">';
		echo self::render_inner( get_current_user_id(), $is_mock ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted markup assembled in render_inner
		echo '</div>';
		echo '</div>';
	}

	/**
	 * "Enhance with AI" toggle, mirroring @wordpress/components ToggleControl.
	 *
	 * Only rendered when an `ai_provider` connector is registered via the
	 * WP 7.0 Connectors API — no provider, no toggle. Keeps the widget
	 * free of dead controls on installs that haven't connected an AI yet.
	 */
	private static function render_ai_toggle(): void {
		if ( ! current_user_can( self::TOGGLE_CAP ) ) {
			return;
		}
		if ( ! self::ai_provider_registered() ) {
			return;
		}

		$on      = Settings::ai_enabled();
		$classes = [ 'components-form-toggle', 'habit-creator-ai-toggle__form-toggle' ];
		if ( $on ) {
			$classes[] = 'is-checked';
		}

		?>
		<div class="habit-creator-ai-toggle">
			<button
				type="button"
				class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
				role="switch"
				aria-checked="<?php echo $on ? 'true' : 'false'; ?>"
				aria-labelledby="habit-creator-ai-toggle-label"
			>
				<span class="components-form-toggle__track" aria-hidden="true"></span>
				<span class="components-form-toggle__thumb" aria-hidden="true"></span>
			</button>
			<label
				id="habit-creator-ai-toggle-label"
				class="habit-creator-ai-toggle__label"
			><?php esc_html_e( 'Enhance with AI', 'habit-creator' ); ?></label>
		</div>
		<?php
	}

	private static function ai_provider_registered(): bool {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return false;
		}
		foreach ( (array) wp_get_connectors() as $connector ) {
			if ( ( $connector['type'] ?? '' ) === 'ai_provider' ) {
				return true;
			}
		}
		return false;
	}

	private static function render_inner( int $user_id, bool $is_mock ): string {
		$patterns = $is_mock
			? Pattern_Detector::mock_patterns_for_user( $user_id )
			: Pattern_Detector::patterns_for_user( $user_id );
		if ( ! $is_mock ) {
			$dismissed = (array) get_user_meta( $user_id, self::DISMISSED_USERMETA, true );
			$patterns  = array_values( array_filter(
				$patterns,
				static fn( $p ) => ! in_array( $p['key'], $dismissed, true )
			) );
		}

		ob_start();
		echo '<p class="habit-creator-intro">' . esc_html__( 'Streaks from your archive — keep the habits going.', 'habit-creator' ) . '</p>';

		if ( ! $patterns ) {
			echo '<p class="habit-creator-empty">' . esc_html__( 'No live streaks right now. As you build a recurring archive, Habit Creator will surface the rhythms it spots.', 'habit-creator' ) . '</p>';
			return (string) ob_get_clean();
		}

		$hero = array_shift( $patterns );
		$rest = $patterns;

		self::render_hero( $hero );

		if ( $rest ) {
			$count = count( $rest );
			printf(
				'<button type="button" class="habit-creator-expand" aria-expanded="false">%s</button>',
				esc_html( sprintf(
					/* translators: %d: number of additional streaks */
					_n( '+%d more streak', '+%d more streaks', $count, 'habit-creator' ),
					$count
				) )
			);
			echo '<ul class="habit-creator-list" hidden>';
			foreach ( $rest as $pattern ) {
				self::render_secondary( $pattern );
			}
			echo '</ul>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function render_hero( array $pattern ): void {
		?>
		<div class="habit-creator-card is-hero" data-pattern-key="<?php echo esc_attr( (string) $pattern['key'] ); ?>">
			<button type="button" class="habit-creator-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this streak', 'habit-creator' ); ?>" title="<?php esc_attr_e( 'Not this one', 'habit-creator' ); ?>">×</button>

			<h3 class="habit-creator-headline"><?php echo esc_html( (string) $pattern['headline'] ); ?></h3>
			<p class="habit-creator-body"><?php echo wp_kses_post( self::render_with_pill( (string) $pattern['body'], $pattern ) ); ?></p>

			<?php $prior = (array) $pattern['prior_posts']; ?>
			<ol class="habit-creator-streak">
				<?php foreach ( $prior as $entry ) : ?>
					<?php
					$post      = $entry['post'];
					$ago       = (string) $entry['ago_label'];
					$permalink = (string) get_permalink( (int) $post['id'] );
					?>
					<li class="habit-creator-streak-row is-done">
						<span class="habit-creator-streak-icon"><?php echo self::check_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted markup ?></span>
						<span class="habit-creator-streak-text">
							<span class="habit-creator-streak-ago"><?php echo esc_html( ucfirst( $ago ) ); ?></span>
							<a class="habit-creator-streak-title" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( (string) $post['title'] ); ?></a>
						</span>
					</li>
				<?php endforeach; ?>
				<li class="habit-creator-streak-row is-next">
					<span class="habit-creator-streak-icon"><?php echo self::flame_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted markup ?></span>
					<span class="habit-creator-streak-text">
						<span class="habit-creator-streak-ago"><?php echo esc_html( ucfirst( (string) $pattern['timing'] ) ); ?></span>
						<span class="habit-creator-streak-cta">
							<button type="button" class="button button-primary button-small habit-creator-create"><?php
								esc_html_e( 'Create a post', 'habit-creator' );
							?></button>
						</span>
					</span>
				</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function render_secondary( array $pattern ): void {
		$prior      = (array) $pattern['prior_posts'];
		$last_entry = end( $prior );
		$last_ago   = $last_entry ? (string) $last_entry['ago_label'] : '';
		?>
		<li class="habit-creator-card is-compact" data-pattern-key="<?php echo esc_attr( (string) $pattern['key'] ); ?>">
			<button type="button" class="habit-creator-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this streak', 'habit-creator' ); ?>">×</button>
			<p class="habit-creator-compact-headline"><?php echo esc_html( (string) $pattern['headline'] ); ?></p>
			<p class="habit-creator-compact-meta">
				<?php if ( $last_ago !== '' ) : ?>
					<span class="habit-creator-compact-last"><?php
						/* translators: %s: relative time, e.g. "1 year ago" */
						printf( esc_html__( 'Last: %s', 'habit-creator' ), esc_html( $last_ago ) );
					?></span>
				<?php endif; ?>
				<button type="button" class="button-link habit-creator-create"><?php
					esc_html_e( 'Create a post', 'habit-creator' );
				?> →</button>
			</p>
		</li>
		<?php
	}

	/**
	 * The "check" icon from @wordpress/icons, inlined as SVG so we don't
	 * have to enqueue the full wp-components CSS bundle for one glyph.
	 */
	private static function check_icon_svg(): string {
		return '<svg class="habit-creator-streak-check" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"/></svg>';
	}

	/**
	 * Flame SVG. There is no flame in @wordpress/icons (streaks aren't a
	 * built-in WP concept), so we use the Heroicons solid "fire" path —
	 * MIT-licensed, 24×24 viewbox, two-region fill (outer flame + inner
	 * ember) — which reads as a flame at small sizes far better than a
	 * hand-rolled silhouette.
	 *
	 * @link https://heroicons.com (MIT)
	 */
	private static function flame_icon_svg(): string {
		// Heroicons "fire" outer outline only — we drop the inner ember
		// subpath so the flame reads as a solid filled shape rather than a
		// double-outlined glyph at small sizes.
		return '<svg class="habit-creator-streak-flame" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M12.963 2.286a.75.75 0 00-1.071-.136 9.742 9.742 0 00-3.539 6.177A7.547 7.547 0 016.648 6.61a.75.75 0 00-1.152-.082A9 9 0 1015.68 4.534a7.46 7.46 0 01-2.717-2.248z"/></svg>';
	}

	/**
	 * Wrap the first occurrence of the topic name in a tag/category pill.
	 * Falls back to plain escaped text if the topic isn't found in the
	 * string or the pattern type doesn't support a pill.
	 *
	 * @param array<string, mixed> $pattern
	 */
	private static function render_with_pill( string $text, array $pattern ): string {
		$type  = (string) $pattern['type'];
		$topic = (string) $pattern['topic'];
		if ( $topic === '' || ! in_array( $type, [ 'tag', 'category' ], true ) ) {
			return esc_html( $text );
		}
		$pos = strpos( $text, $topic );
		if ( $pos === false ) {
			return esc_html( $text );
		}
		$before = substr( $text, 0, $pos );
		$after  = substr( $text, $pos + strlen( $topic ) );
		return esc_html( $before ) . self::render_topic_pill( $type, $topic ) . esc_html( $after );
	}

	private static function render_topic_pill( string $type, string $label ): string {
		$prefix = $type === 'tag'
			? '<span class="habit-creator-pill-prefix" aria-hidden="true">#</span>'
			: '';
		return sprintf(
			'<span class="habit-creator-pill habit-creator-pill--%1$s">%2$s%3$s</span>',
			esc_attr( $type ),
			$prefix,
			esc_html( $label )
		);
	}

	public static function handle_toggle_ai(): void {
		check_ajax_referer( NONCE_ACTION );
		if ( ! current_user_can( self::TOGGLE_CAP ) ) {
			wp_send_json_error( [], 403 );
		}
		$on = isset( $_POST['enabled'] ) && (string) $_POST['enabled'] === '1';
		update_option( Settings::OPTION_USE_AI, $on ? '1' : '0' );
		wp_send_json_success( [
			'enabled' => $on,
		] );
	}

	public static function handle_dismiss(): void {
		check_ajax_referer( NONCE_ACTION );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [], 403 );
		}
		$pattern_key = isset( $_POST['pattern_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pattern_key'] ) ) : '';
		if ( $pattern_key === '' ) {
			wp_send_json_error( [], 400 );
		}

		$user_id     = get_current_user_id();
		$dismissed   = (array) get_user_meta( $user_id, self::DISMISSED_USERMETA, true );
		$dismissed[] = $pattern_key;
		update_user_meta( $user_id, self::DISMISSED_USERMETA, array_values( array_unique( $dismissed ) ) );
		wp_send_json_success( [ 'html' => self::render_inner( $user_id, false ) ] );
	}
}
