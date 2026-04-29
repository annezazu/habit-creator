<?php
/**
 * Detects recurring patterns in a user's posting history without requiring AI.
 *
 * Strategy: bucket published posts by ISO week-of-year, then for each bucket
 * find signals (shared tags, shared categories, shared title n-grams) that
 * recur in 2+ distinct calendar years. Each pattern is scored by how many
 * years it has recurred and how engaged readers were with the strongest
 * prior post. Patterns whose anniversary window falls in the next ~3 weeks
 * are surfaced first.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Pattern_Detector {

	private const LOOKAHEAD_WEEKS      = 4;
	private const MAX_PATTERNS         = 5;
	private const MIN_YEARS            = 2;
	private const NGRAM_SIZE           = 2;
	private const STOPWORDS       = [
		'the','a','an','and','or','but','of','to','in','on','for','with','at','by',
		'is','are','was','were','be','been','being','it','its','as','that','this',
		'these','those','i','you','we','they','my','your','our','their','from',
		'how','why','what','when','where','about','into','some','any','new','post',
	];

	public static function run_for_all_authors(): void {
		$authors = get_users( [
			'who'    => 'authors',
			'fields' => [ 'ID' ],
		] );

		foreach ( $authors as $author ) {
			self::patterns_for_user( (int) $author->ID, true );
		}
	}

	/**
	 * Return cached patterns for a user, or compute and cache on miss.
	 *
	 * @param int  $user_id WP user ID.
	 * @param bool $force   Recompute even if cache is fresh.
	 * @return array<int, array<string, mixed>>
	 */
	public static function patterns_for_user( int $user_id, bool $force = false ): array {
		$key = TRANSIENT_KEY . $user_id;

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$posts    = self::fetch_user_posts( $user_id );
		$patterns = self::build_patterns( $posts );
		$ranked   = self::rank_and_filter( $patterns );

		if ( class_exists( __NAMESPACE__ . '\\AI_Enhancer' ) && AI_Enhancer::is_available() ) {
			$ranked = AI_Enhancer::enrich( $ranked );
		}

		set_transient( $key, $ranked, DAY_IN_SECONDS );

		return $ranked;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_user_posts( int $user_id ): array {
		$query = new \WP_Query( [
			'author'         => $user_id,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		$out = [];
		foreach ( $query->posts as $post ) {
			$timestamp = (int) get_post_time( 'U', true, $post );
			$tags      = wp_get_post_tags( $post->ID, [ 'fields' => 'all' ] );
			$cats      = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );

			$out[] = [
				'id'        => (int) $post->ID,
				'title'     => (string) $post->post_title,
				'year'      => (int) gmdate( 'o', $timestamp ),
				'week'      => (int) gmdate( 'W', $timestamp ),
				'timestamp' => $timestamp,
				'comments'  => (int) $post->comment_count,
				'tags'      => array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $tags ),
				'cats'      => array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $cats ),
			];
		}

		return $out;
	}

	/**
	 * Build candidate patterns keyed by (signal_type, signal_value).
	 *
	 * @param array<int, array<string, mixed>> $posts
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_patterns( array $posts ): array {
		$by_week = [];
		foreach ( $posts as $p ) {
			$by_week[ $p['week'] ][] = $p;
		}

		$patterns = [];
		foreach ( $by_week as $week => $week_posts ) {
			if ( count( $week_posts ) < self::MIN_YEARS ) {
				continue;
			}

			self::collect_taxonomy_signals( $patterns, $week_posts, 'tag', 'tags' );
			self::collect_taxonomy_signals( $patterns, $week_posts, 'category', 'cats' );
			self::collect_ngram_signals( $patterns, $week_posts );
		}

		return $patterns;
	}

	/**
	 * @param array<string, array<string, mixed>> $patterns
	 * @param array<int, array<string, mixed>>    $week_posts
	 */
	private static function collect_taxonomy_signals( array &$patterns, array $week_posts, string $type, string $field ): void {
		$by_term = [];
		foreach ( $week_posts as $p ) {
			foreach ( $p[ $field ] as $term ) {
				$by_term[ $term['slug'] ]['term']    = $term;
				$by_term[ $term['slug'] ]['posts'][] = $p;
			}
		}

		foreach ( $by_term as $slug => $data ) {
			$years = array_unique( array_column( $data['posts'], 'year' ) );
			if ( count( $years ) < self::MIN_YEARS ) {
				continue;
			}
			$key                = $type . ':' . $slug;
			$patterns[ $key ]   = [
				'key'        => $key,
				'type'       => $type,
				'label'      => $data['term']['name'],
				'years'      => array_values( $years ),
				'posts'      => $data['posts'],
				'best_post'  => self::best_post( $data['posts'] ),
				'next_week' => (int) $data['posts'][0]['week'],
			];
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $patterns
	 * @param array<int, array<string, mixed>>    $week_posts
	 */
	private static function collect_ngram_signals( array &$patterns, array $week_posts ): void {
		$by_ngram = [];
		foreach ( $week_posts as $p ) {
			foreach ( self::title_ngrams( $p['title'] ) as $ngram ) {
				$by_ngram[ $ngram ]['posts'][] = $p;
			}
		}

		foreach ( $by_ngram as $ngram => $data ) {
			$unique_posts = [];
			foreach ( $data['posts'] as $p ) {
				$unique_posts[ $p['id'] ] = $p;
			}
			$unique_posts = array_values( $unique_posts );
			$years        = array_unique( array_column( $unique_posts, 'year' ) );
			if ( count( $years ) < self::MIN_YEARS ) {
				continue;
			}
			$key              = 'phrase:' . $ngram;
			$patterns[ $key ] = [
				'key'       => $key,
				'type'      => 'phrase',
				'label'     => $ngram,
				'years'     => array_values( $years ),
				'posts'     => $unique_posts,
				'best_post' => self::best_post( $unique_posts ),
				'next_week' => (int) $unique_posts[0]['week'],
			];
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function title_ngrams( string $title ): array {
		$normalized = strtolower( wp_strip_all_tags( $title ) );
		$normalized = preg_replace( '/[^a-z0-9\s]/', ' ', $normalized );
		$words      = array_values( array_filter(
			preg_split( '/\s+/', (string) $normalized ),
			static fn( $w ) => $w !== '' && ! in_array( $w, self::STOPWORDS, true ) && strlen( $w ) > 2
		) );

		$ngrams = [];
		$count  = count( $words );
		for ( $i = 0; $i <= $count - self::NGRAM_SIZE; $i++ ) {
			$ngrams[] = implode( ' ', array_slice( $words, $i, self::NGRAM_SIZE ) );
		}
		return $ngrams;
	}

	/**
	 * @param array<int, array<string, mixed>> $posts
	 * @return array<string, mixed>
	 */
	private static function best_post( array $posts ): array {
		usort( $posts, static fn( $a, $b ) => $b['comments'] <=> $a['comments'] ?: $b['timestamp'] <=> $a['timestamp'] );
		return $posts[0];
	}

	/**
	 * Filter to upcoming-anniversary patterns and rank by recurrence + engagement.
	 *
	 * @param array<string, array<string, mixed>> $patterns
	 * @return array<int, array<string, mixed>>
	 */
	private static function rank_and_filter( array $patterns ): array {
		$current_week = (int) gmdate( 'W' );

		$scored = [];
		foreach ( $patterns as $p ) {
			$weeks_until = self::weeks_until( $current_week, (int) $p['next_week'] );
			if ( $weeks_until > self::LOOKAHEAD_WEEKS ) {
				continue;
			}
			$p['score']       = ( count( $p['years'] ) * 10 ) + (int) $p['best_post']['comments'];
			$p['weeks_until'] = $weeks_until;
			$scored[]         = $p;
		}

		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		// Dedupe: when several patterns point at the same best-post, only the
		// highest-scoring one survives. Prevents "tag:nature", "tag:oregon",
		// and "phrase:my eyes" from each surfacing as separate cards when
		// they all trace back to the same single post.
		$by_post = [];
		foreach ( $scored as $p ) {
			$post_id = (int) $p['best_post']['id'];
			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = $p;
			}
		}

		$out = array_slice( array_values( $by_post ), 0, self::MAX_PATTERNS );

		foreach ( $out as &$pattern ) {
			$years_count = count( $pattern['years'] );

			$pattern['topic']         = self::compose_topic( $pattern );
			$pattern['kicker']        = self::compose_kicker( $pattern );
			$pattern['cta']           = self::compose_cta( $years_count );
			$pattern['timing']        = self::compose_timing( (int) $pattern['weeks_until'] );
			$pattern['encouragement'] = self::compose_encouragement( $pattern );
		}
		unset( $pattern );

		return $out;
	}

	private static function weeks_until( int $current_week, int $target_week ): int {
		$diff = $target_week - $current_week;
		if ( $diff < 0 ) {
			$diff += 53;
		}
		return $diff;
	}

	/**
	 * The short, scannable topic name used as the card's main headline.
	 *
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_topic( array $pattern ): string {
		$label = (string) $pattern['label'];
		switch ( $pattern['type'] ) {
			case 'tag':
				return ucwords( str_replace( [ '-', '_' ], ' ', $label ) );
			case 'category':
				return $label;
			case 'phrase':
				return ucfirst( $label );
			default:
				return $label;
		}
	}

	/**
	 * One short, varied sentence that sits under the topic. Three templates
	 * rotate, deterministically chosen from the pattern key so the same
	 * pattern reads the same way across renders.
	 *
	 * @param array<string, mixed> $pattern
	 */
	/**
	 * Small uppercase label naming the *kind* of pattern detected.
	 *
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_kicker( array $pattern ): string {
		switch ( $pattern['type'] ) {
			case 'tag':
				return __( 'TAG', 'habit-creator' );
			case 'category':
				return __( 'CATEGORY', 'habit-creator' );
			case 'phrase':
				return __( 'RECURRING PHRASE', 'habit-creator' );
			default:
				return __( 'PATTERN', 'habit-creator' );
		}
	}

	/**
	 * Milestone-aware CTA label. Names the next number when small enough to feel reachable.
	 */
	private static function compose_cta( int $years_count ): string {
		if ( $years_count >= 5 ) {
			return __( 'Continue the streak', 'habit-creator' );
		}
		$next = $years_count + 1;
		return sprintf(
			/* translators: %d: target streak length in years */
			__( 'Make it %d years', 'habit-creator' ),
			$next
		);
	}

	private static function compose_timing( int $weeks_until ): string {
		if ( $weeks_until === 0 ) {
			return __( 'Anniversary this week', 'habit-creator' );
		}
		return sprintf(
			/* translators: %d: weeks until anniversary */
			_n( 'Due in %d week', 'Due in %d weeks', $weeks_until, 'habit-creator' ),
			$weeks_until
		);
	}

	/**
	 * One-line emotional hook, only when honestly true. Order of preference:
	 * (1) unbroken consecutive streak ending recently, (2) high engagement.
	 *
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_encouragement( array $pattern ): ?string {
		$years    = $pattern['years'];
		$start    = self::unbroken_since( $years );
		if ( $start !== null ) {
			return sprintf(
				/* translators: %d: starting year of unbroken streak */
				__( 'You haven\'t missed a year since %d.', 'habit-creator' ),
				$start
			);
		}

		$comments = (int) $pattern['best_post']['comments'];
		if ( $comments >= 10 ) {
			return sprintf(
				/* translators: %d: comment count */
				_n( 'Last time, %d reader chimed in.', 'Last time, %d readers chimed in.', $comments, 'habit-creator' ),
				$comments
			);
		}
		if ( $comments >= 3 ) {
			return sprintf(
				/* translators: %d: comment count */
				_n( '%d comment on last year\'s post.', '%d comments on last year\'s post.', $comments, 'habit-creator' ),
				$comments
			);
		}

		return null;
	}

	/**
	 * If the years form a consecutive block ending at the most recent year,
	 * return the starting year. Otherwise null.
	 *
	 * @param array<int, int> $years
	 */
	private static function unbroken_since( array $years ): ?int {
		if ( count( $years ) < 3 ) {
			return null;
		}
		$sorted = $years;
		sort( $sorted );
		$expected = $sorted[0];
		foreach ( $sorted as $y ) {
			if ( $y !== $expected ) {
				return null;
			}
			$expected++;
		}
		return $sorted[0];
	}
}
