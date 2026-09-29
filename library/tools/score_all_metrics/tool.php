<?php
/**
 * Tool: Score All Metrics
 *
 * Run the full 20-metric SEO & AI Visibility evaluation. Returns a 0-100
 * composite score with per-metric pass/warn/fail status, individual scores,
 * and a prioritised fix list.
 *
 * @package Agentic\Tools
 */

declare(strict_types=1);

namespace Agentic\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Score_All_Metrics extends \Agentic\Tool_Base {

	public function get_name(): string {
		return 'score_all_metrics';
	}

	public function get_description(): string {
		return 'Run the full 20-metric SEO & AI Visibility evaluation. Returns a 0-100 composite score with per-metric pass/warn/fail status, individual scores, and a prioritised fix list.';
	}

	public function get_category(): string {
		return 'site-audit';
	}

	public function get_parameters(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);
	}

	public function get_annotations(): array {
		return array(
			'read_only'   => true,
			'destructive' => false,
		);
	}

	public function execute( array $args ): array {
		$loader = \Agentic\Tool_Loader::get_instance();

		// Collect data from other tools.
		$content = $loader->execute( 'get_content_stats', array() );
		$seo     = $loader->execute( 'get_seo_stats', array() );
		$web     = $loader->execute( 'get_web_standards_status', array() );
		$ai      = $loader->execute( 'get_ai_visibility_status', array() );
		$comm    = $loader->execute( 'get_commercial_signals', array() );

		$metrics = array();

		// M1: Search Intent Alignment.
		$posts_with_intent = 0;
		$sample_posts      = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 20,
			)
		);
		$intent_patterns   = array( 'how to', 'what is', 'guide', 'buy', 'review', 'best', 'contact', 'about', 'pricing', 'learn' );
		foreach ( $sample_posts as $p ) {
			$lower = strtolower( $p->post_title );
			foreach ( $intent_patterns as $ip ) {
				if ( str_contains( $lower, $ip ) ) {
					++$posts_with_intent;
					break; }
			}
		}
		$m1_ratio  = count( $sample_posts ) > 0 ? $posts_with_intent / count( $sample_posts ) : 0;
		$metrics[] = $this->metric_result( 1, 'Search Intent Alignment', $m1_ratio >= 0.5 ? 5 : ( $m1_ratio >= 0.25 ? 3 : 1 ), $m1_ratio >= 0.5 ? 'pass' : ( $m1_ratio >= 0.25 ? 'warn' : 'fail' ), round( $m1_ratio * 100 ) . '% of pages have clear intent signals in titles.' );

		// M2: Content Quality & Originality.
		$avg_words  = $content['avg_word_count'] ?? 0;
		$thin_count = $content['thin_post_count'] ?? 0;
		$total      = max( 1, $content['total_posts'] ?? 1 );
		$thin_pct   = round( ( $thin_count / $total ) * 100 );
		$m2_score   = $avg_words >= 800 && $thin_pct < 10 ? 5 : ( $avg_words >= 400 && $thin_pct < 30 ? 3 : 1 );
		$metrics[]  = $this->metric_result( 2, 'Content Quality & Originality', $m2_score, $m2_score >= 4 ? 'pass' : ( $m2_score >= 3 ? 'warn' : 'fail' ), "Avg {$avg_words} words, {$thin_pct}% thin content." );

		// M3: E-E-A-T Signals.
		$eeat       = $loader->execute( 'check_eeat_signals', array() );
		$eeat_score = $eeat['eeat_score'] ?? 0;
		$m3_score   = $eeat_score >= 80 ? 5 : ( $eeat_score >= 50 ? 3 : 1 );
		$metrics[]  = $this->metric_result( 3, 'E-E-A-T Signals', $m3_score, $m3_score >= 4 ? 'pass' : ( $m3_score >= 3 ? 'warn' : 'fail' ), "E-E-A-T score: {$eeat_score}/100." );

		// M4: Topical Authority & Content Depth.
		$topics    = $loader->execute( 'analyze_topic_coverage', array() );
		$gap_count = count( $topics['weak_categories'] ?? array() );
		$m4_score  = 0 === $gap_count ? 5 : ( $gap_count <= 3 ? 3 : 1 );
		$metrics[] = $this->metric_result( 4, 'Topical Authority & Depth', $m4_score, $m4_score >= 4 ? 'pass' : ( $m4_score >= 3 ? 'warn' : 'fail' ), 0 === $gap_count ? 'All topic clusters have adequate coverage.' : "{$gap_count} topic areas need more content." );

		// M5: User Engagement Signals.
		$text_only_pct = $content['text_only_post_pct'] ?? 0;
		$avg_gap       = $content['avg_days_between_posts'] ?? 999;
		$m5_score      = $text_only_pct < 20 && $avg_gap <= 14 ? 5 : ( $text_only_pct < 50 && $avg_gap <= 60 ? 3 : 1 );
		$metrics[]     = $this->metric_result( 5, 'User Engagement Signals', $m5_score, $m5_score >= 4 ? 'pass' : ( $m5_score >= 3 ? 'warn' : 'fail' ), "{$text_only_pct}% text-only posts, publishing every {$avg_gap} days." );

		// M6: Internal Link Quality.
		$orphaned  = $seo['orphaned_pages'] ?? 0;
		$m6_score  = 0 === $orphaned ? 5 : ( $orphaned <= 5 ? 3 : 1 );
		$metrics[] = $this->metric_result( 6, 'Internal Link Quality', $m6_score, $m6_score >= 4 ? 'pass' : ( $m6_score >= 3 ? 'warn' : 'fail' ), 0 === $orphaned ? 'No orphan pages.' : "{$orphaned} orphan pages with no inbound links." );

		// M7: Content Freshness.
		$stale_pct = $content['pct_older_than_12_months'] ?? 0;
		$m7_score  = $stale_pct < 30 ? 5 : ( $stale_pct < 60 ? 3 : 1 );
		$metrics[] = $this->metric_result( 7, 'Content Freshness', $m7_score, $m7_score >= 4 ? 'pass' : ( $m7_score >= 3 ? 'warn' : 'fail' ), "{$stale_pct}% of content older than 12 months." );

		// M8: Structured Data / Schema Markup.
		$schema_count = count( $seo['schema_types_detected'] ?? array() );
		$m8_score     = $schema_count >= 3 ? 5 : ( $schema_count >= 1 ? 3 : 1 );
		$metrics[]    = $this->metric_result( 8, 'Structured Data / Schema', $m8_score, $m8_score >= 4 ? 'pass' : ( $m8_score >= 3 ? 'warn' : 'fail' ), $schema_count . ' schema types detected.' );

		// M9: Content Structure & Readability.
		$heading_issues = ( $seo['pages_no_h1'] ?? 0 ) + ( $seo['pages_multiple_h1'] ?? 0 );
		$m9_score       = 0 === $heading_issues && $avg_words >= 400 ? 5 : ( $heading_issues <= 5 ? 3 : 1 );
		$metrics[]      = $this->metric_result( 9, 'Content Structure & Readability', $m9_score, $m9_score >= 4 ? 'pass' : ( $m9_score >= 3 ? 'warn' : 'fail' ), "{$heading_issues} heading issues, avg {$avg_words} words." );

		// M10: Core Web Vitals — proxy from caching & asset signals.
		$has_cache = false;
		$plugins   = (array) get_option( 'active_plugins', array() );
		foreach ( $plugins as $p ) {
			if ( preg_match( '/wp-super-cache|w3-total-cache|litespeed-cache|wp-rocket|wp-fastest-cache/', $p ) ) {
				$has_cache = true;
				break; }
		}
		$m10_score = $has_cache ? 4 : 2;
		$metrics[] = $this->metric_result( 10, 'Core Web Vitals / Performance', $m10_score, $m10_score >= 4 ? 'pass' : 'warn', $has_cache ? 'Caching plugin active.' : 'No caching plugin — performance likely impacted.' );

		// M11: Direct Answer Format.
		$da_result = $loader->execute( 'check_direct_answers', array() );
		$da_pct    = $da_result['pct_with_direct_answer'] ?? 0;
		$m11_score = $da_pct >= 60 ? 5 : ( $da_pct >= 30 ? 3 : 1 );
		$metrics[] = $this->metric_result( 11, 'Direct Answer Format', $m11_score, $m11_score >= 4 ? 'pass' : ( $m11_score >= 3 ? 'warn' : 'fail' ), "{$da_pct}% of pages front-load their answer." );

		// M12: Domain Authority Signals — advisory only.
		$has_ssl   = strpos( home_url(), 'https://' ) === 0;
		$m12_score = $has_ssl && $total >= 20 ? 4 : ( $has_ssl ? 3 : 1 );
		$metrics[] = $this->metric_result( 12, 'Domain Authority Signals', $m12_score, $m12_score >= 4 ? 'pass' : ( $m12_score >= 3 ? 'warn' : 'fail' ), 'HTTPS: ' . ( $has_ssl ? 'yes' : 'no' ) . ", {$total} published pages." );

		// M13: Authorship & Credentials.
		$auth_result = $loader->execute( 'check_authorship', array() );
		$auth_issues = count( $auth_result['authors_missing_bio'] ?? array() );
		$m13_score   = 0 === $auth_issues ? 5 : ( $auth_issues <= 2 ? 3 : 1 );
		$metrics[]   = $this->metric_result( 13, 'Authorship & Credentials', $m13_score, $m13_score >= 4 ? 'pass' : ( $m13_score >= 3 ? 'warn' : 'fail' ), 0 === $auth_issues ? 'All authors have bios.' : "{$auth_issues} authors missing bios." );

		// M14: Brand Signals.
		$brand       = $loader->execute( 'check_brand_signals', array() );
		$brand_score = $brand['brand_score'] ?? 0;
		$m14_score   = $brand_score >= 80 ? 5 : ( $brand_score >= 50 ? 3 : 1 );
		$metrics[]   = $this->metric_result( 14, 'Brand Signals', $m14_score, $m14_score >= 4 ? 'pass' : ( $m14_score >= 3 ? 'warn' : 'fail' ), "Brand consistency score: {$brand_score}/100." );

		// M15: Technical SEO Foundations.
		$has_sitemap   = ! empty( $web['sitemap_exists'] );
		$has_robots    = ! empty( $web['robots_txt_exists'] );
		$has_canonical = ( $web['canonical_coverage_pct'] ?? 0 ) >= 80;
		$tech_points   = (int) $has_sitemap + (int) $has_robots + (int) $has_canonical + (int) $has_ssl;
		$m15_score     = $tech_points >= 4 ? 5 : ( $tech_points >= 2 ? 3 : 1 );
		$metrics[]     = $this->metric_result( 15, 'Technical SEO Foundations', $m15_score, $m15_score >= 4 ? 'pass' : ( $m15_score >= 3 ? 'warn' : 'fail' ), 'Sitemap: ' . ( $has_sitemap ? '✓' : '✗' ) . ', Robots: ' . ( $has_robots ? '✓' : '✗' ) . ', Canonical: ' . ( $has_canonical ? '✓' : '✗' ) . ', HTTPS: ' . ( $has_ssl ? '✓' : '✗' ) );

		// M16: Semantic Optimization — sample check.
		$with_kw = 0;
		foreach ( $sample_posts as $p ) {
			if ( \Agentic\Tool_Helpers::get_focus_keyword( $p->ID ) ) {
				++$with_kw;
			}
		}
		$kw_pct    = count( $sample_posts ) > 0 ? round( ( $with_kw / count( $sample_posts ) ) * 100 ) : 0;
		$m16_score = $kw_pct >= 70 ? 5 : ( $kw_pct >= 30 ? 3 : 1 );
		$metrics[] = $this->metric_result( 16, 'Semantic Optimization', $m16_score, $m16_score >= 4 ? 'pass' : ( $m16_score >= 3 ? 'warn' : 'fail' ), "{$kw_pct}% of pages have focus keywords set." );

		// M17: Unique Insights / Proprietary Data.
		$orig_result = $loader->execute( 'detect_original_content', array() );
		$orig_pct    = $orig_result['pct_with_original_signals'] ?? 0;
		$m17_score   = $orig_pct >= 50 ? 5 : ( $orig_pct >= 25 ? 3 : 1 );
		$metrics[]   = $this->metric_result( 17, 'Unique Insights / Proprietary Data', $m17_score, $m17_score >= 4 ? 'pass' : ( $m17_score >= 3 ? 'warn' : 'fail' ), "{$orig_pct}% of posts contain original data signals." );

		// M18: Mobile-Friendliness.
		$has_viewport = ! empty( $web['viewport_meta_present'] );
		$has_srcset   = ! empty( $web['uses_srcset'] );
		$m18_score    = $has_viewport && $has_srcset ? 5 : ( $has_viewport ? 3 : 1 );
		$metrics[]    = $this->metric_result( 18, 'Mobile-Friendliness', $m18_score, $m18_score >= 4 ? 'pass' : ( $m18_score >= 3 ? 'warn' : 'fail' ), 'Viewport: ' . ( $has_viewport ? '✓' : '✗' ) . ', Responsive images: ' . ( $has_srcset ? '✓' : '✗' ) );

		// M19: Content Consistency.
		$consistency = $loader->execute( 'check_content_consistency', array() );
		$issue_count = count( $consistency['issues'] ?? array() );
		$m19_score   = 0 === $issue_count ? 5 : ( $issue_count <= 3 ? 3 : 1 );
		$metrics[]   = $this->metric_result( 19, 'Content Consistency', $m19_score, $m19_score >= 4 ? 'pass' : ( $m19_score >= 3 ? 'warn' : 'fail' ), 0 === $issue_count ? 'No consistency issues detected.' : "{$issue_count} consistency issues found." );

		// M20: AI Crawler Accessibility.
		$blanket   = $ai['blanket_block'] ?? false;
		$blocked   = $ai['bots_blocked'] ?? array();
		$has_llms  = $ai['has_llms_txt'] ?? false;
		$m20_score = ! $blanket && empty( $blocked ) && $has_llms ? 5 : ( ! $blanket && count( $blocked ) <= 1 ? 3 : 1 );
		$metrics[] = $this->metric_result( 20, 'AI Crawler Accessibility', $m20_score, $m20_score >= 4 ? 'pass' : ( $m20_score >= 3 ? 'warn' : 'fail' ), count( $blocked ) . ' AI bots blocked, llms.txt: ' . ( $has_llms ? 'present' : 'absent' ) );

		// Compute overall.
		$total_score = array_sum( array_column( $metrics, 'score' ) );
		$max_score   = count( $metrics ) * 5;
		$overall     = $max_score > 0 ? round( ( $total_score / $max_score ) * 100 ) : 0;

		$pass_count = count( array_filter( $metrics, fn( $m ) => 'pass' === $m['status'] ) );
		$warn_count = count( array_filter( $metrics, fn( $m ) => 'warn' === $m['status'] ) );
		$fail_count = count( array_filter( $metrics, fn( $m ) => 'fail' === $m['status'] ) );

		// Priority fixes: lowest-scoring metrics.
		$sorted = $metrics;
		usort( $sorted, fn( $a, $b ) => $a['score'] <=> $b['score'] );
		$priority_fixes = array_slice( $sorted, 0, 5 );

		return array(
			'scanned_at'     => gmdate( 'Y-m-d H:i:s' ),
			'site_url'       => home_url(),
			'overall_score'  => $overall,
			'grade'          => \Agentic\Tool_Helpers::score_to_grade( $overall ),
			'total_score'    => $total_score,
			'max_score'      => $max_score,
			'pass_count'     => $pass_count,
			'warn_count'     => $warn_count,
			'fail_count'     => $fail_count,
			'metrics'        => $metrics,
			'priority_fixes' => $priority_fixes,
		);
	}

	private function metric_result( int $number, string $name, int $score, string $status, string $finding ): array {
		return array(
			'metric_number' => $number,
			'name'          => $name,
			'score'         => min( 5, max( 0, $score ) ),
			'max'           => 5,
			'status'        => $status,
			'finding'       => $finding,
		);
	}
}

return new Score_All_Metrics();
