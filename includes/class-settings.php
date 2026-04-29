<?php
/**
 * Single user-facing setting: whether Habit Creator may call the configured
 * AI provider for writing-prompt suggestions and dashboard encouragements.
 * Default on; one checkbox under Settings → Writing.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_USE_AI = 'habit_creator_use_ai';

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'register' ] );
	}

	public static function ai_enabled(): bool {
		return (string) get_option( self::OPTION_USE_AI, '1' ) === '1';
	}

	public static function register(): void {
		register_setting(
			'writing',
			self::OPTION_USE_AI,
			[
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static fn( $v ) => (string) $v === '1' ? '1' : '0',
			]
		);

		add_settings_section(
			'habit_creator_section',
			__( 'Habit Creator', 'habit-creator' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Habit Creator detects recurring patterns in your archive locally — no AI needed for that. When an AI provider is connected, it can also suggest starter questions for new drafts. It never writes posts for you.', 'habit-creator' ) . '</p>';
			},
			'writing'
		);

		add_settings_field(
			self::OPTION_USE_AI,
			__( 'Use AI for prompt suggestions', 'habit-creator' ),
			[ self::class, 'render_field' ],
			'writing',
			'habit_creator_section'
		);
	}

	public static function render_field(): void {
		$on = self::ai_enabled();
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::OPTION_USE_AI ),
			checked( $on, true, false ),
			esc_html__( 'Suggest starter questions and warmer dashboard nudges using your connected AI provider.', 'habit-creator' ),
			esc_html__( 'Habit Creator only uses AI for short prompts and one-line nudges — never to write post content for you.', 'habit-creator' )
		);
	}
}
