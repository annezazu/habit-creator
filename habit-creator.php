<?php
/**
 * Plugin Name: Habit Creator
 * Description: Spots recurring patterns in your blogging history (topics, tags, seasonal activities) and nudges you to write the next installment. Works without AI; uses the Connectors API when an AI provider is registered.
 * Version:     0.4.42
 * Author:      Anne McCarthy
 * License:     GPL-2.0-or-later
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

const VERSION       = '0.4.42';
const CRON_HOOK     = 'habit_creator_detect_patterns';
const TRANSIENT_KEY = 'habit_creator_patterns_v2_';
const NONCE_ACTION  = 'habit_creator_action';

require_once __DIR__ . '/includes/class-pattern-detector.php';
require_once __DIR__ . '/includes/class-ai-enhancer.php';
require_once __DIR__ . '/includes/class-draft-creator.php';
require_once __DIR__ . '/includes/class-dashboard-widget.php';
require_once __DIR__ . '/includes/class-settings.php';

Settings::init();
add_action( 'wp_dashboard_setup', [ Dashboard_Widget::class, 'register' ] );
add_action( 'admin_enqueue_scripts', [ Dashboard_Widget::class, 'enqueue_assets' ] );
add_action( 'wp_ajax_habit_creator_create_draft', [ Draft_Creator::class, 'handle_ajax' ] );
add_action( 'wp_ajax_habit_creator_toggle_ai', [ Dashboard_Widget::class, 'handle_toggle_ai' ] );
add_action( CRON_HOOK, [ Pattern_Detector::class, 'run_for_all_authors' ] );

register_activation_hook( __FILE__, static function (): void {
	if ( ! wp_next_scheduled( CRON_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CRON_HOOK );
	}
} );

register_deactivation_hook( __FILE__, static function (): void {
	$timestamp = wp_next_scheduled( CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, CRON_HOOK );
	}
} );
