<?php
/**
 * Detects habit-shaped streaks in a user's posting archive.
 *
 * For each signal we extract from posts (tag, category, title bigram), we look
 * for the longest *consecutive* run of periods the signal appears in, across
 * four time units: day, week, month, and year. The strongest candidate per
 * signal is kept, then we surface only "live" streaks — ones where the next
 * post is due now or imminent so the user can plausibly extend them.
 *
 * @package HabitCreator
 */

declare( strict_types=1 );

namespace HabitCreator;

defined( 'ABSPATH' ) || exit;

final class Pattern_Detector {

	private const MAX_PATTERNS         = 5;
	private const MIN_STREAK           = 2;
	private const YEAR_LOOKAHEAD_WEEKS = 4;
	private const NGRAM_SIZE           = 2;
	private const STOPWORDS            = [
		'the','a','an','and','or','but','of','to','in','on','for','with','at','by',
		'is','are','was','were','be','been','being','it','its','as','that','this',
		'these','those','i','you','we','they','my','your','our','their','from',
		'how','why','what','when','where','about','into','some','any','new','post',
	];

	// Slugs that are too generic to count as evidence of *coherence* between
	// posts. We don't filter primary signals by this list — a "habit" can
	// genuinely live inside a big bucket — but we don't accept "you both
	// tagged this `uncategorized`" as proof that two posts are about the
	// same thing.
	private const GENERIC_TAXONOMY_SLUGS = [
		'uncategorized', 'all-posts', 'allposts', 'all', 'general',
		'misc', 'miscellaneous', 'random', 'blog', 'posts', 'news',
		'updates', 'announcements', 'other', 'stuff', 'notes',
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
		$by_sig   = self::group_by_signal( $posts );
		$patterns = self::detect_streaks( $by_sig );
		$patterns = self::filter_coherent( $patterns, count( $posts ) );
		$ranked   = self::rank_and_compose( $patterns );

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
			$ts   = (int) get_post_time( 'U', true, $post );
			$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'all' ] );
			$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );

			$out[] = [
				'id'           => (int) $post->ID,
				'title'        => (string) $post->post_title,
				'timestamp'    => $ts,
				'comments'     => (int) $post->comment_count,
				'day_index'    => self::day_index( $ts ),
				'week_index'   => self::week_index( $ts ),
				'month_index'  => self::month_index( $ts ),
				'iso_year'     => (int) gmdate( 'o', $ts ),
				'iso_week_num' => (int) gmdate( 'W', $ts ),
				'tags'         => array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $tags ),
				'cats'         => array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $cats ),
			];
		}

		return $out;
	}

	/**
	 * Bucket posts by signal: tag, category, or recurring title bigram.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 * @return array<string, array<string, mixed>>
	 */
	private static function group_by_signal( array $posts ): array {
		$out = [];
		foreach ( $posts as $p ) {
			foreach ( $p['tags'] as $t ) {
				self::push_signal( $out, 'tag:' . $t['slug'], 'tag', $t['name'], $p );
			}
			foreach ( $p['cats'] as $t ) {
				self::push_signal( $out, 'category:' . $t['slug'], 'category', $t['name'], $p );
			}
			foreach ( self::title_ngrams( $p['title'] ) as $ngram ) {
				self::push_signal( $out, 'phrase:' . $ngram, 'phrase', $ngram, $p );
			}
		}

		// One post per signal — a phrase may emit the same post twice.
		foreach ( $out as &$bucket ) {
			$seen = [];
			$kept = [];
			foreach ( $bucket['posts'] as $p ) {
				if ( isset( $seen[ $p['id'] ] ) ) {
					continue;
				}
				$seen[ $p['id'] ] = true;
				$kept[]           = $p;
			}
			$bucket['posts'] = $kept;
		}
		unset( $bucket );

		return $out;
	}

	private static function is_generic_slug( string $slug ): bool {
		return in_array( strtolower( $slug ), self::GENERIC_TAXONOMY_SLUGS, true );
	}

	/**
	 * @param array<string, array<string, mixed>> $bucket
	 * @param array<string, mixed>                $post
	 */
	private static function push_signal( array &$bucket, string $key, string $type, string $label, array $post ): void {
		$bucket[ $key ]['type']    = $type;
		$bucket[ $key ]['label']   = $label;
		$bucket[ $key ]['posts'][] = $post;
	}

	/**
	 * For each signal, find the strongest live streak across all four units.
	 *
	 * @param array<string, array<string, mixed>> $by_sig
	 * @return array<int, array<string, mixed>>
	 */
	private static function detect_streaks( array $by_sig ): array {
		$patterns = [];
		foreach ( $by_sig as $key => $sig ) {
			if ( count( $sig['posts'] ) < self::MIN_STREAK ) {
				continue;
			}
			$best = self::best_streak_for_signal( $sig['posts'] );
			if ( $best === null ) {
				continue;
			}
			$patterns[] = array_merge( $best, [
				'key'         => $key,
				'type'        => $sig['type'],
				'label'       => $sig['label'],
				'bucket_size' => count( $sig['posts'] ),
			] );
		}
		return $patterns;
	}

	/**
	 * Try detecting a streak in each unit; return the strongest candidate.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 * @return array<string, mixed>|null
	 */
	private static function best_streak_for_signal( array $posts ): ?array {
		$candidates = array_filter( [
			self::yearly_streak( $posts ),
			self::monthly_streak( $posts ),
			self::weekly_streak( $posts ),
			self::daily_streak( $posts ),
		] );
		if ( ! $candidates ) {
			return null;
		}

		usort( $candidates, static function ( $a, $b ) {
			$cmp = $b['streak'] <=> $a['streak'];
			if ( $cmp !== 0 ) {
				return $cmp;
			}
			return self::unit_rank( $b['unit'] ) <=> self::unit_rank( $a['unit'] );
		} );

		return $candidates[0];
	}

	private static function unit_rank( string $unit ): int {
		return [ 'day' => 1, 'week' => 2, 'month' => 3, 'year' => 4 ][ $unit ] ?? 0;
	}

	/**
	 * Yearly streak: same week-of-year, consecutive calendar years.
	 * Live if the next anniversary lands within YEAR_LOOKAHEAD_WEEKS weeks.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 */
	private static function yearly_streak( array $posts ): ?array {
		$by_week_year = [];
		foreach ( $posts as $p ) {
			$by_week_year[ $p['iso_week_num'] ][ $p['iso_year'] ][] = $p;
		}

		$best = null;
		foreach ( $by_week_year as $week => $by_year ) {
			$years = array_keys( $by_year );
			sort( $years );
			$run = self::longest_consecutive_run( $years );
			if ( count( $run ) < self::MIN_STREAK ) {
				continue;
			}

			$current_week = (int) gmdate( 'W' );
			$weeks_until  = self::weeks_until( $current_week, (int) $week );
			if ( $weeks_until > self::YEAR_LOOKAHEAD_WEEKS ) {
				continue;
			}

			$current_year = (int) gmdate( 'o' );
			$most_recent  = (int) end( $run );
			// Only surface yearly streaks the user could plausibly extend.
			// Any gap older than last year means the habit has lapsed.
			if ( $most_recent < $current_year - 1 ) {
				continue;
			}

			$prior = [];
			$all   = [];
			foreach ( $run as $y ) {
				$best_in_year = self::best_post_among( $by_year[ $y ] );
				$prior[]      = [
					'post'      => $best_in_year,
					'ago_label' => self::ago_label( 'year', $current_year - $y ),
				];
				$all          = array_merge( $all, $by_year[ $y ] );
			}

			$candidate = [
				'unit'           => 'year',
				'streak'         => count( $run ),
				'prior_posts'    => $prior,
				'best_post'      => self::best_post_among( $all ),
				'next_due_n'     => $weeks_until,
				'next_due_unit'  => 'week',
			];
			if ( $best === null || $candidate['streak'] > $best['streak'] ) {
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Monthly streak: signal appears in N consecutive year-months. Live if the
	 * most recent month is the current month or the previous month.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 */
	private static function monthly_streak( array $posts ): ?array {
		return self::consecutive_streak( $posts, 'month' );
	}

	/**
	 * Weekly streak: N consecutive ISO weeks.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 */
	private static function weekly_streak( array $posts ): ?array {
		return self::consecutive_streak( $posts, 'week' );
	}

	/**
	 * Daily streak: N consecutive calendar days.
	 *
	 * @param array<int, array<string, mixed>> $posts
	 */
	private static function daily_streak( array $posts ): ?array {
		return self::consecutive_streak( $posts, 'day' );
	}

	/**
	 * @param array<int, array<string, mixed>> $posts
	 * @param 'day'|'week'|'month'             $unit
	 */
	private static function consecutive_streak( array $posts, string $unit ): ?array {
		$index_field = $unit . '_index';
		$by_period   = [];
		foreach ( $posts as $p ) {
			$by_period[ $p[ $index_field ] ][] = $p;
		}

		$periods = array_keys( $by_period );
		sort( $periods );
		$run = self::longest_consecutive_run( $periods );
		if ( count( $run ) < self::MIN_STREAK ) {
			return null;
		}

		$current_period = self::current_period_index( $unit );
		$most_recent    = end( $run );
		// Live if the next post is due this period or the previous period was
		// the most recent occurrence (i.e., we're at most one period overdue).
		if ( $most_recent < $current_period - 1 ) {
			return null;
		}

		$next_due_n = max( 0, ( $most_recent + 1 ) - $current_period );
		$prior      = [];
		$all        = [];
		foreach ( $run as $period ) {
			$best_in_period = self::best_post_among( $by_period[ $period ] );
			$prior[]        = [
				'post'      => $best_in_period,
				'ago_label' => self::ago_label( $unit, $current_period - $period ),
			];
			$all            = array_merge( $all, $by_period[ $period ] );
		}

		return [
			'unit'          => $unit,
			'streak'        => count( $run ),
			'prior_posts'   => $prior,
			'best_post'     => self::best_post_among( $all ),
			'next_due_n'    => $next_due_n,
			'next_due_unit' => $unit,
		];
	}

	/**
	 * @param array<int, int> $sorted_indices
	 * @return array<int, int>
	 */
	private static function longest_consecutive_run( array $sorted_indices ): array {
		$best    = [];
		$current = [];
		$prev    = null;
		foreach ( $sorted_indices as $i ) {
			if ( $prev === null || $i === $prev + 1 ) {
				$current[] = $i;
			} else {
				if ( count( $current ) > count( $best ) ) {
					$best = $current;
				}
				$current = [ $i ];
			}
			$prev = $i;
		}
		if ( count( $current ) > count( $best ) ) {
			$best = $current;
		}
		return $best;
	}

	private static function current_period_index( string $unit ): int {
		$ts = time();
		switch ( $unit ) {
			case 'day':
				return self::day_index( $ts );
			case 'week':
				return self::week_index( $ts );
			case 'month':
				return self::month_index( $ts );
		}
		return 0;
	}

	private static function day_index( int $ts ): int {
		return (int) ( $ts / DAY_IN_SECONDS );
	}

	private static function week_index( int $ts ): int {
		// Use Monday-aligned weeks so consecutive weeks are exactly 7 days apart.
		// The +3 offset shifts the epoch (Thu Jan 1 1970) so weeks start on Monday.
		return (int) floor( ( ( $ts / DAY_IN_SECONDS ) + 3 ) / 7 );
	}

	private static function month_index( int $ts ): int {
		return ( (int) gmdate( 'Y', $ts ) ) * 12 + (int) gmdate( 'n', $ts ) - 1;
	}

	private static function weeks_until( int $current_week, int $target_week ): int {
		$diff = $target_week - $current_week;
		if ( $diff < 0 ) {
			$diff += 53;
		}
		return $diff;
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
	private static function best_post_among( array $posts ): array {
		usort( $posts, static fn( $a, $b ) => $b['comments'] <=> $a['comments'] ?: $b['timestamp'] <=> $a['timestamp'] );
		return $posts[0];
	}

	// Buckets larger than this share of the archive can't be trusted as a
	// topic on their own — the tag/category name covers too much ground to
	// imply that two random posts in it are about the same thing.
	private const TRUSTED_BUCKET_THRESHOLD = 0.2;

	/**
	 * For each candidate streak, decide whether to keep it and how to
	 * label it.
	 *
	 * Niche, specifically-named buckets (≤ TRUSTED_BUCKET_THRESHOLD of the
	 * archive, not in the generic stoplist) are trusted as topics. The tag
	 * IS the topic.
	 *
	 * Big or generic-named buckets get *refined*: we look for content the
	 * posts in the run actually share (a secondary tag/category, a title
	 * bigram, a title word) and surface the streak under that more specific
	 * label. If nothing specific is shared, the streak is genuinely incoherent
	 * and we drop it.
	 *
	 * Phrase streaks are inherently coherent — the bigram is shared by
	 * construction.
	 *
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_coherent( array $patterns, int $total_posts ): array {
		$out = [];
		foreach ( $patterns as $pattern ) {
			$kept = self::refine_or_drop( $pattern, $total_posts );
			if ( $kept !== null ) {
				$out[] = $kept;
			}
		}
		return array_values( $out );
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>|null
	 */
	private static function refine_or_drop( array $pattern, int $total_posts ): ?array {
		if ( $pattern['type'] === 'phrase' ) {
			return $pattern;
		}

		$slug         = self::slug_from_key( (string) $pattern['key'] );
		$is_generic   = self::is_generic_slug( $slug );
		$bucket_size  = (int) ( $pattern['bucket_size'] ?? 0 );
		$niche_thresh = (int) ceil( $total_posts * self::TRUSTED_BUCKET_THRESHOLD );

		if ( ! $is_generic && $bucket_size > 0 && $bucket_size <= $niche_thresh ) {
			return $pattern;
		}

		return self::refine_via_shared_content( $pattern );
	}

	/**
	 * @param array<string, mixed> $pattern
	 * @return array<string, mixed>|null
	 */
	private static function refine_via_shared_content( array $pattern ): ?array {
		$posts = array_column( (array) $pattern['prior_posts'], 'post' );
		if ( count( $posts ) < 2 ) {
			return $pattern;
		}

		$primary_key = (string) $pattern['key'];

		// 1. Shared non-generic secondary taxonomy — preserves the pill.
		$taxa_sets = [];
		$taxa_meta = [];
		foreach ( $posts as $p ) {
			$taxa = [];
			foreach ( (array) $p['tags'] as $t ) {
				$tag_slug = (string) $t['slug'];
				if ( self::is_generic_slug( $tag_slug ) ) {
					continue;
				}
				$tag_key = 'tag:' . $tag_slug;
				if ( $tag_key === $primary_key ) {
					continue;
				}
				$taxa[]              = $tag_key;
				$taxa_meta[ $tag_key ] = [ 'type' => 'tag', 'label' => (string) $t['name'] ];
			}
			foreach ( (array) $p['cats'] as $t ) {
				$cat_slug = (string) $t['slug'];
				if ( self::is_generic_slug( $cat_slug ) ) {
					continue;
				}
				$cat_key = 'category:' . $cat_slug;
				if ( $cat_key === $primary_key ) {
					continue;
				}
				$taxa[]              = $cat_key;
				$taxa_meta[ $cat_key ] = [ 'type' => 'category', 'label' => (string) $t['name'] ];
			}
			$taxa_sets[] = array_values( array_unique( $taxa ) );
		}
		$shared_taxa = count( $taxa_sets ) > 1 ? array_intersect( ...$taxa_sets ) : reset( $taxa_sets );
		if ( ! empty( $shared_taxa ) ) {
			$key   = (string) reset( $shared_taxa );
			$meta  = $taxa_meta[ $key ];
			return array_merge( $pattern, [
				'key'   => $key,
				'type'  => $meta['type'],
				'label' => $meta['label'],
			] );
		}

		// 2. Shared title bigram — more specific than a single word.
		$bigram_sets    = array_map(
			static fn( $p ) => array_values( array_unique( self::title_ngrams( (string) $p['title'] ) ) ),
			$posts
		);
		$shared_bigrams = count( $bigram_sets ) > 1 ? array_intersect( ...$bigram_sets ) : reset( $bigram_sets );
		if ( ! empty( $shared_bigrams ) ) {
			$bigram = (string) reset( $shared_bigrams );
			return array_merge( $pattern, [
				'key'   => 'phrase:' . $bigram,
				'type'  => 'phrase',
				'label' => $bigram,
			] );
		}

		// 3. Shared significant title word — least specific but still real.
		$word_sets    = array_map(
			static fn( $p ) => self::significant_words( (string) $p['title'] ),
			$posts
		);
		$shared_words = count( $word_sets ) > 1 ? array_intersect( ...$word_sets ) : reset( $word_sets );
		if ( ! empty( $shared_words ) ) {
			$words = array_values( $shared_words );
			usort( $words, static fn( $a, $b ) => strlen( $b ) - strlen( $a ) );
			$word = (string) $words[0];
			return array_merge( $pattern, [
				'key'   => 'phrase:' . $word,
				'type'  => 'phrase',
				'label' => $word,
			] );
		}

		return null;
	}

	private static function slug_from_key( string $key ): string {
		$pos = strpos( $key, ':' );
		return $pos === false ? $key : substr( $key, $pos + 1 );
	}

	/**
	 * Build a fixed set of patterns for design review — one per unit
	 * (day, week, month, year), mixing tag/category/phrase types — using
	 * real posts pulled from the user's archive so titles and permalinks
	 * still work. Triggered by ?habit_creator_mock=1; never cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mock_patterns_for_user( int $user_id ): array {
		$real = self::fetch_user_posts( $user_id );
		if ( count( $real ) < 14 ) {
			return [];
		}

		$pick = static function ( array $source, int $offset, int $count ) {
			return array_slice( $source, $offset, $count );
		};

		$mocks = [
			[
				'key'           => 'tag:reflections',
				'type'          => 'tag',
				'label'         => 'reflections',
				'unit'          => 'year',
				'streak'        => 3,
				'prior_posts'   => self::mock_prior( $pick( $real, 0, 3 ), [ '3 years ago', '2 years ago', 'last year' ] ),
				'best_post'     => $real[0],
				'next_due_n'    => 1,
				'next_due_unit' => 'week',
				'bucket_size'   => 12,
			],
			[
				'key'           => 'tag:journal',
				'type'          => 'tag',
				'label'         => 'journal',
				'unit'          => 'month',
				'streak'        => 2,
				'prior_posts'   => self::mock_prior( $pick( $real, 3, 2 ), [ 'last month', 'this month' ] ),
				'best_post'     => $real[3],
				'next_due_n'    => 1,
				'next_due_unit' => 'month',
				'bucket_size'   => 6,
			],
			[
				'key'           => 'phrase:weekly notes',
				'type'          => 'phrase',
				'label'         => 'weekly notes',
				'unit'          => 'week',
				'streak'        => 4,
				'prior_posts'   => self::mock_prior( $pick( $real, 5, 4 ), [ '4 weeks ago', '3 weeks ago', '2 weeks ago', 'last week' ] ),
				'best_post'     => $real[5],
				'next_due_n'    => 0,
				'next_due_unit' => 'week',
				'bucket_size'   => 4,
			],
			[
				'key'           => 'tag:morning-pages',
				'type'          => 'tag',
				'label'         => 'morning-pages',
				'unit'          => 'day',
				'streak'        => 5,
				'prior_posts'   => self::mock_prior( $pick( $real, 9, 5 ), [ '5 days ago', '4 days ago', '3 days ago', '2 days ago', 'yesterday' ] ),
				'best_post'     => $real[9],
				'next_due_n'    => 0,
				'next_due_unit' => 'day',
				'bucket_size'   => 5,
			],
		];

		return self::rank_and_compose( $mocks );
	}

	/**
	 * @param array<int, array<string, mixed>> $posts
	 * @param array<int, string>               $ago_labels
	 * @return array<int, array<string, mixed>>
	 */
	private static function mock_prior( array $posts, array $ago_labels ): array {
		$out = [];
		foreach ( array_values( $posts ) as $i => $post ) {
			$out[] = [
				'post'      => $post,
				'ago_label' => $ago_labels[ $i ] ?? '',
			];
		}
		return $out;
	}

	/**
	 * Significant words from a title — lowercase, alphanumeric, length>2,
	 * not in the stopword list. Used by the coherence check.
	 *
	 * @return array<int, string>
	 */
	private static function significant_words( string $title ): array {
		$normalized = strtolower( wp_strip_all_tags( $title ) );
		$normalized = preg_replace( '/[^a-z0-9\s]/', ' ', $normalized );
		$words      = array_values( array_filter(
			preg_split( '/\s+/', (string) $normalized ),
			static fn( $w ) => $w !== '' && ! in_array( $w, self::STOPWORDS, true ) && strlen( $w ) > 2
		) );
		return array_values( array_unique( $words ) );
	}

	/**
	 * Score, dedupe by best-post, slice top N, compose strings.
	 *
	 * @param array<int, array<string, mixed>> $patterns
	 * @return array<int, array<string, mixed>>
	 */
	private static function rank_and_compose( array $patterns ): array {
		foreach ( $patterns as &$p ) {
			$p['score'] = ( (int) $p['streak'] * 10 ) + (int) $p['best_post']['comments'];
		}
		unset( $p );

		usort( $patterns, static fn( $a, $b ) => $b['score'] <=> $a['score'] );

		$by_post = [];
		foreach ( $patterns as $p ) {
			$post_id = (int) $p['best_post']['id'];
			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = $p;
			}
		}

		$out = array_slice( array_values( $by_post ), 0, self::MAX_PATTERNS );

		foreach ( $out as &$pattern ) {
			$pattern['topic']    = self::compose_topic( $pattern );
			$pattern['headline'] = self::compose_headline( $pattern );
			$pattern['body']     = self::compose_body( $pattern );
			$pattern['cta']      = self::compose_cta( $pattern );
			$pattern['timing']   = self::compose_timing( (int) $pattern['next_due_n'], (string) $pattern['next_due_unit'] );
		}
		unset( $pattern );

		return $out;
	}

	/**
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
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_headline( array $pattern ): string {
		$next  = (int) $pattern['streak'] + 1;
		$unit  = (string) $pattern['unit'];
		$topic = (string) self::compose_topic( $pattern );
		return sprintf(
			/* translators: 1: target streak count, 2: unit (day/week/month/year), 3: topic */
			__( 'Create a %1$d %2$s habit of %3$s posts.', 'habit-creator' ),
			$next,
			$unit,
			$topic
		);
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_body( array $pattern ): string {
		$streak  = (int) $pattern['streak'];
		$next    = $streak + 1;
		$unit    = (string) $pattern['unit'];
		$topic   = (string) self::compose_topic( $pattern );
		$timing  = self::compose_timing( (int) $pattern['next_due_n'], (string) $pattern['next_due_unit'] );
		$article = self::indefinite_article( $topic );

		if ( $unit === 'year' ) {
			return sprintf(
				/* translators: 1: indefinite article (a/an), 2: topic, 3: current streak, 4: timing phrase, 5: target streak count */
				__( 'You\'ve written %1$s %2$s post around this time of year for %3$d years. Keep it going. Blog again %4$s to make it a %5$d year habit.', 'habit-creator' ),
				$article,
				$topic,
				$streak,
				$timing,
				$next
			);
		}
		return sprintf(
			/* translators: 1: indefinite article (a/an), 2: topic, 3: current streak, 4: unit (day/week/month) plural, 5: timing phrase, 6: target streak count, 7: unit singular */
			__( 'You\'ve written %1$s %2$s post %3$d %4$s in a row. Keep it going. Post again %5$s to make it a %6$d %7$s habit.', 'habit-creator' ),
			$article,
			$topic,
			$streak,
			$unit . 's',
			$timing,
			$next,
			$unit
		);
	}

	private static function indefinite_article( string $word ): string {
		$first = strtolower( substr( trim( $word ), 0, 1 ) );
		return in_array( $first, [ 'a', 'e', 'i', 'o', 'u' ], true )
			? __( 'an', 'habit-creator' )
			: __( 'a', 'habit-creator' );
	}

	/**
	 * @param array<string, mixed> $pattern
	 */
	private static function compose_cta( array $pattern ): string {
		$next = (int) $pattern['streak'] + 1;
		$unit = (string) $pattern['unit'];
		return sprintf(
			/* translators: 1: target streak count, 2: unit (day/week/month/year) */
			__( 'Make it %1$d %2$ss', 'habit-creator' ),
			$next,
			$unit
		);
	}

	private static function compose_timing( int $n, string $unit ): string {
		switch ( $unit ) {
			case 'day':
				if ( $n === 0 ) {
					return __( 'today', 'habit-creator' );
				}
				if ( $n === 1 ) {
					return __( 'tomorrow', 'habit-creator' );
				}
				return sprintf(
					/* translators: %d: number of days */
					__( 'in %d days', 'habit-creator' ),
					$n
				);
			case 'month':
				if ( $n === 0 ) {
					return __( 'this month', 'habit-creator' );
				}
				if ( $n === 1 ) {
					return __( 'next month', 'habit-creator' );
				}
				return __( 'in the next few months', 'habit-creator' );
			case 'week':
			default:
				if ( $n === 0 ) {
					return __( 'this week', 'habit-creator' );
				}
				if ( $n === 1 ) {
					return __( 'next week', 'habit-creator' );
				}
				return __( 'in the next few weeks', 'habit-creator' );
		}
	}

	private static function ago_label( string $unit, int $n ): string {
		if ( $n <= 0 ) {
			switch ( $unit ) {
				case 'day':
					return __( 'today', 'habit-creator' );
				case 'week':
					return __( 'this week', 'habit-creator' );
				case 'month':
					return __( 'this month', 'habit-creator' );
				case 'year':
					return __( 'this year', 'habit-creator' );
			}
		}
		switch ( $unit ) {
			case 'day':
				return $n === 1
					? __( 'yesterday', 'habit-creator' )
					: sprintf( /* translators: %d: days ago */ __( '%d days ago', 'habit-creator' ), $n );
			case 'week':
				return $n === 1
					? __( 'last week', 'habit-creator' )
					: sprintf( /* translators: %d: weeks ago */ __( '%d weeks ago', 'habit-creator' ), $n );
			case 'month':
				return $n === 1
					? __( 'last month', 'habit-creator' )
					: sprintf( /* translators: %d: months ago */ __( '%d months ago', 'habit-creator' ), $n );
			case 'year':
				return $n === 1
					? __( 'last year', 'habit-creator' )
					: sprintf( /* translators: %d: years ago */ __( '%d years ago', 'habit-creator' ), $n );
		}
		return '';
	}
}
