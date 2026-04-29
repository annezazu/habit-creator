<?php
/**
 * Renders the "Habit Creator" dashboard widget.
 *
 * Layout (Option A — stat-anchored):
 *   Intro line below the widget title explaining what this is.
 *   Hero card: big numeral + "YEARS" label on the left; kicker, topic,
 *   timing, optional encouragement, milestone CTA on the right.
 *   Up to four more patterns hidden behind a "+N more" expander, rendered
 *   as compact one-liners with their own milestone CTA links.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Widget {

	private const DISMISSED_USERMETA = 'habit_creator_dismissed';

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
		$user_id   = get_current_user_id();
		$dismissed = (array) get_user_meta( $user_id, self::DISMISSED_USERMETA, true );
		$patterns  = Pattern_Detector::patterns_for_user( $user_id );
		$patterns  = array_values( array_filter(
			$patterns,
			static fn( $p ) => ! in_array( $p['key'], $dismissed, true )
		) );

		echo '<div class="habit-creator">';
		echo '<p class="habit-creator-intro">' . esc_html__( 'Patterns from your archive — keep the streaks you didn\'t know you had.', 'habit-creator' ) . '</p>';

		if ( ! $patterns ) {
			echo '<p class="habit-creator-empty">' . esc_html__( 'No recurring patterns yet. As you build a year-over-year archive, Habit Creator will surface the rhythms it spots.', 'habit-creator' ) . '</p>';
			echo '</div>';
			return;
		}

		$hero = array_shift( $patterns );
		$rest = $patterns;

		self::render_hero( $hero );

		if ( $rest ) {
			$count = count( $rest );
			printf(
				'<button type="button" class="habit-creator-expand" aria-expanded="false">%s</button>',
				esc_html( sprintf(
					/* translators: %d: number of additional patterns */
					_n( '+%d more pattern', '+%d more patterns', $count, 'habit-creator' ),
					$count
				) )
			);
			echo '<ul class="habit-creator-list" hidden>';
			foreach ( $rest as $pattern ) {
				self::render_secondary( $pattern );
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function render_hero( array $pattern ): void {
		$best        = $pattern['best_post'];
		$ai_enhanced = ! empty( $pattern['ai_enhanced'] );
		$years       = count( $pattern['years'] );
		?>
		<div class="habit-creator-card is-hero" data-pattern-key="<?php echo esc_attr( (string) $pattern['key'] ); ?>">
			<button type="button" class="habit-creator-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this pattern', 'habit-creator' ); ?>" title="<?php esc_attr_e( 'Not this year', 'habit-creator' ); ?>">×</button>

			<div class="habit-creator-stat" aria-hidden="true">
				<span class="habit-creator-stat-number"><?php echo esc_html( (string) $years ); ?></span>
				<span class="habit-creator-stat-label"><?php
					echo esc_html( _n( 'YEAR', 'YEARS', $years, 'habit-creator' ) );
				?></span>
			</div>

			<div class="habit-creator-body">
				<p class="habit-creator-kicker">
					<?php echo esc_html( (string) $pattern['kicker'] ); ?>
					<?php if ( $ai_enhanced ) : ?>
						<span class="habit-creator-ai-badge" title="<?php esc_attr_e( 'Enhanced via the WordPress AI Client', 'habit-creator' ); ?>">AI</span>
					<?php endif; ?>
				</p>
				<h3 class="habit-creator-topic"><?php echo esc_html( (string) $pattern['topic'] ); ?></h3>
				<p class="habit-creator-timing"><?php echo esc_html( (string) $pattern['timing'] ); ?></p>

				<?php if ( ! empty( $pattern['encouragement'] ) ) : ?>
					<p class="habit-creator-encouragement"><?php echo esc_html( (string) $pattern['encouragement'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $best['title'] ) ) : ?>
					<p class="habit-creator-prior">
						<?php esc_html_e( 'Last:', 'habit-creator' ); ?>
						<a href="<?php echo esc_url( (string) get_permalink( (int) $best['id'] ) ); ?>"><?php echo esc_html( (string) $best['title'] ); ?></a>
					</p>
				<?php endif; ?>

				<p class="habit-creator-actions">
					<button type="button" class="button button-primary habit-creator-create"><?php
						echo esc_html( (string) $pattern['cta'] );
					?> →</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function render_secondary( array $pattern ): void {
		$years = count( $pattern['years'] );
		?>
		<li class="habit-creator-card is-compact" data-pattern-key="<?php echo esc_attr( (string) $pattern['key'] ); ?>">
			<button type="button" class="habit-creator-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this pattern', 'habit-creator' ); ?>">×</button>
			<div class="habit-creator-compact-row">
				<span class="habit-creator-streak-inline"><?php
					echo esc_html( sprintf(
						/* translators: %d: streak length in years */
						_n( '%d yr', '%d yrs', $years, 'habit-creator' ),
						$years
					) );
				?></span>
				<span class="habit-creator-kicker-inline"><?php echo esc_html( (string) $pattern['kicker'] ); ?></span>
				<span class="habit-creator-topic-inline"><?php echo esc_html( (string) $pattern['topic'] ); ?></span>
			</div>
			<div class="habit-creator-compact-row habit-creator-compact-meta">
				<span class="habit-creator-timing-inline"><?php echo esc_html( (string) $pattern['timing'] ); ?></span>
				<button type="button" class="button-link habit-creator-create"><?php
					echo esc_html( str_replace( ' years', ' →', (string) $pattern['cta'] ) );
				?></button>
			</div>
		</li>
		<?php
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
		wp_send_json_success();
	}
}
