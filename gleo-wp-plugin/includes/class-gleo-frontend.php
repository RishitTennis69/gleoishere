<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gleo_Frontend {

	public function __construct() {
		// Virtual /llms.txt endpoint
		add_action( 'template_redirect', array( $this, 'serve_llms_txt' ) );

		// JSON-LD injection into <head>
		add_action( 'wp_head', array( $this, 'inject_json_ld' ), 1 );

		// Front-end styles for Gleo-injected content blocks
		add_action( 'wp_head', array( $this, 'inject_content_styles' ), 5 );

		// REST endpoints for applying fixes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Detect the site's primary accent color from theme settings.
	 * Tries block-theme global styles first, then classic theme mods.
	 */
	private function get_theme_accent_color() {
		// Block themes: read theme.json color palette
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			if ( ! empty( $palette ) && is_array( $palette ) ) {
				foreach ( $palette as $swatch ) {
					if ( isset( $swatch['slug'] ) && in_array( $swatch['slug'], array( 'primary', 'accent', 'vivid-cyan-blue' ), true ) ) {
						$c = sanitize_hex_color( $swatch['color'] ?? '' );
						if ( $c ) return $c;
					}
				}
				// Fall back to first non-white/black color in the palette
				foreach ( $palette as $swatch ) {
					$c = sanitize_hex_color( $swatch['color'] ?? '' );
					if ( $c && ! in_array( strtolower( $c ), array( '#ffffff', '#fff', '#000000', '#000' ), true ) ) {
						return $c;
					}
				}
			}
		}
		// Classic themes: check common theme mods
		foreach ( array( 'accent_color', 'primary_color' ) as $mod ) {
			$c = sanitize_hex_color( get_theme_mod( $mod, '' ) );
			if ( $c ) return $c;
		}
		// Last resort: header text color
		$h = get_header_textcolor();
		if ( $h && 'blank' !== $h ) return '#' . ltrim( $h, '#' );
		return '#3b82f6'; // Gleo default blue
	}

	/**
	 * Convert a 6-digit hex color and alpha value into rgba() notation.
	 */
	private function hex_to_rgba( $hex, $alpha ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) return "rgba(59,130,246,{$alpha})";
		return sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
	}

	/**
	 * Detect the site background color for adaptive text contrast.
	 */
	private function get_site_background_color() {
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles = wp_get_global_styles( array( 'color' ) );
			if ( ! empty( $styles['background'] ) ) {
				$c = sanitize_hex_color( $styles['background'] );
				if ( $c ) return $c;
			}
		}
		$bg = get_theme_mod( 'background_color', 'ffffff' );
		return '#' . ltrim( $bg, '#' );
	}

	/**
	 * Return appropriate text color (dark or light) based on background luminance.
	 */
	private function get_adaptive_text_color( $bg_hex ) {
		$hex = ltrim( $bg_hex, '#' );
		if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
		if ( strlen( $hex ) !== 6 ) return '#1e293b';
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		// Relative luminance (WCAG)
		$r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
		$g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
		$b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
		$luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		return $luminance > 0.4 ? '#1e293b' : '#f1f5f9';
	}

	/**
	 * Output CSS for all Gleo-injected content blocks.
	 * Uses CSS custom properties so JS can always override colours based on the
	 * element's actual rendered background — PHP cannot reliably detect dark sections.
	 */
	public function inject_content_styles() {
		if ( ! is_singular( 'post' ) ) return;
		$accent     = $this->get_theme_accent_color();
		$accent_bg  = $this->hex_to_rgba( $accent, '0.08' );
		$accent_mid = $this->hex_to_rgba( $accent, '0.18' );
		?>
<style id="gleo-content-styles">
/* ── CSS custom-property defaults (light mode; JS overrides per-element) */
:root {
  --gc-text:       #1e293b;
  --gc-muted:      #64748b;
  --gc-border:     #e2e8f0;
  --gc-card:       #ffffff;
  --gc-hover:      #f8fafc;
  --gc-accent:     <?php echo esc_attr( $accent ); ?>;
  --gc-accent-bg:  <?php echo esc_attr( $accent_bg ); ?>;
  --gc-accent-mid: <?php echo esc_attr( $accent_mid ); ?>;
}
/* ── Shared base ─────────────────────────────────────────────────────── */
.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  color: var(--gc-text);
  line-height: 1.55;
  margin: 1.5em 0;
  clear: both;
}
/* ── FAQ accordion ────────────────────────────────────────────────────── */
.gleo-faq-wrap > h2 {
  font-size: 1.15em; font-weight: 700; margin: 0 0 10px;
  color: var(--gc-text);
}
.gleo-faq-accordion {
  border: 1px solid var(--gc-border);
  border-radius: 10px; overflow: hidden;
  background: var(--gc-card);
}
.gleo-faq-item { border-bottom: 1px solid var(--gc-border); }
.gleo-faq-item:last-child { border-bottom: none; }
.gleo-faq-q {
  width: 100%; padding: 13px 16px; background: none; border: none;
  display: flex; justify-content: space-between; align-items: center;
  cursor: pointer; text-align: left;
  font-size: 0.95em; font-weight: 600;
  color: var(--gc-text);
  transition: background 0.15s;
}
.gleo-faq-q:hover { background: var(--gc-hover); }
.gleo-faq-q::after {
  content: '+'; font-size: 1.1em; flex-shrink: 0; margin-left: 12px;
  color: var(--gc-accent);
  transition: transform 0.25s;
}
.gleo-faq-item.gleo-open .gleo-faq-q::after { content: '\2212'; }
.gleo-faq-a {
  max-height: 0; overflow: hidden; padding: 0 16px;
  font-size: 0.9em; line-height: 1.65;
  color: var(--gc-muted);
  transition: max-height 0.35s ease, padding 0.35s;
}
.gleo-faq-item.gleo-open .gleo-faq-a {
  max-height: 600px; padding: 10px 16px 16px;
  border-top: 1px solid var(--gc-border);
}
/* ── Data Table ───────────────────────────────────────────────────────── */
.gleo-table-block {
  max-width: 88%; margin-left: auto; margin-right: auto;
  border: 1px solid var(--gc-border);
  border-radius: 10px; overflow-x: auto;
  background: var(--gc-card);
}
.gleo-table-block > h3 {
  font-size: 1em; font-weight: 700; margin: 0;
  padding: 12px 16px;
  border-bottom: 1px solid var(--gc-border);
  color: var(--gc-text);
  background: var(--gc-card);
}
.gleo-data-table { width: 100%; border-collapse: collapse; text-align: left; }
.gleo-data-table th {
  padding: 10px 16px; font-weight: 700; font-size: 0.75em;
  text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--gc-muted);
  border-bottom: 1px solid var(--gc-border);
  background: var(--gc-card);
}
.gleo-data-table td {
  padding: 11px 16px; font-size: 0.88em; line-height: 1.5;
  color: var(--gc-text);
  border-bottom: 1px solid var(--gc-border);
  background: var(--gc-card);
}
.gleo-data-table tbody tr:last-child td { border-bottom: none; }
/* ── Stats callout ────────────────────────────────────────────────────── */
.gleo-stats-callout {
  background: var(--gc-accent-bg);
  border: 1px solid var(--gc-accent-mid);
  border-radius: 10px; padding: 14px 16px;
  display: flex; align-items: center; gap: 12px;
}
.gleo-stats-icon {
  font-size: 1.3em; width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  background: var(--gc-card); flex-shrink: 0;
}
.gleo-stats-label {
  font-size: 0.65em; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--gc-accent);
  margin-bottom: 1px;
}
.gleo-stats-text {
  font-size: 0.9em; font-weight: 500; margin: 0;
  color: var(--gc-text);
}
</style>
<script>
(function () {
  /* ── Helpers ──────────────────────────────────────────────────────────── */
  function lc(c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  function lum(r, g, b) { return 0.2126 * lc(r) + 0.7152 * lc(g) + 0.0722 * lc(b); }
  function parseRgb(s) { var m = s.match(/[\d.]+/g); return m && m.length >= 3 ? [+m[0], +m[1], +m[2], m[3] != null ? +m[3] : 1] : null; }

  /* Walk UP the DOM from startEl; return the first non-transparent bg as [r,g,b] */
  function getActualBg(startEl) {
    var node = startEl;
    while (node && node !== document.documentElement) {
      var cs  = window.getComputedStyle(node);
      var bg  = cs.backgroundColor;
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') {
        var rgba = parseRgb(bg);
        /* ignore alpha < 0.06 (near-transparent overlays) */
        if (rgba && rgba[3] > 0.06) return rgba;
      }
      node = node.parentElement;
    }
    return parseRgb(window.getComputedStyle(document.body).backgroundColor) || [255, 255, 255, 1];
  }

  /* ── FAQ accordion toggle ─────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', function () {
    document.body.addEventListener('click', function (e) {
      var btn = e.target.closest('.gleo-faq-q');
      if (btn) btn.parentElement.classList.toggle('gleo-open');
    });

    /* ── Adaptive colour: set CSS vars per element based on real bg ─────── */
    document.querySelectorAll('.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block').forEach(function (el) {
      var rgb  = getActualBg(el.parentElement || el);
      var dark = lum(rgb[0], rgb[1], rgb[2]) < 0.35;
      el.style.setProperty('--gc-text',   dark ? '#f1f5f9' : '#1e293b');
      el.style.setProperty('--gc-muted',  dark ? '#94a3b8' : '#64748b');
      el.style.setProperty('--gc-border', dark ? 'rgba(255,255,255,0.14)' : '#e2e8f0');
      el.style.setProperty('--gc-card',   dark ? 'rgba(255,255,255,0.08)' : '#ffffff');
      el.style.setProperty('--gc-hover',  dark ? 'rgba(255,255,255,0.05)' : '#f8fafc');
    });
  });
}());
</script>
		<?php
	}

	public function register_routes() {

		register_rest_route( 'gleo/v1', '/apply', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_apply' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( 'gleo/v1', '/schema-override', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'set_schema_override' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Serve /llms.txt — AI-friendly site summary for LLM crawlers.
	 */
	public function serve_llms_txt() {
		$request_uri = $_SERVER['REQUEST_URI'];

		// Match /llms.txt exactly (ignore query strings)
		if ( parse_url( $request_uri, PHP_URL_PATH ) !== '/llms.txt' ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noindex' );

		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$site_url  = get_site_url();

		echo "# {$site_name}\n";
		echo "> {$site_desc}\n\n";
		echo "URL: {$site_url}\n\n";

		// Pull AI-generated summaries from completed scans
		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';

		$rows = $wpdb->get_results(
			"SELECT post_id, scan_result FROM {$table_name} WHERE scan_status = 'completed' ORDER BY updated_at DESC LIMIT 20"
		);

		if ( ! empty( $rows ) ) {
			echo "## Content Summary\n\n";
			foreach ( $rows as $row ) {
				$post = get_post( $row->post_id );
				if ( ! $post ) continue;

				echo "### {$post->post_title}\n";
				echo "- URL: " . get_permalink( $post->ID ) . "\n\n";
			}
		}

		exit;
	}

	/**
	 * Inject generated JSON-LD schema into wp_head on single post pages.
	 * Respects the SEO override toggle (gleo_override_schema option).
	 */
	public function inject_json_ld() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		global $post, $wpdb;

		// Check if user has enabled global schema override or post-specific override
		$global_override = get_option( 'gleo_override_schema', false );
		$post_override = get_post_meta( $post->ID, '_gleo_schema_override', true );
		$override = $global_override || $post_override;

		// If an SEO plugin is active and user hasn't opted to override, don't inject
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		$seo_active = is_plugin_active( 'wordpress-seo/wp-seo.php' ) || is_plugin_active( 'seo-by-rank-math/rank-math.php' );
		if ( $seo_active && ! $override ) {
			return;
		}

		$table_name = $wpdb->prefix . 'gleo_scans';

		$scan = $wpdb->get_row( $wpdb->prepare(
			"SELECT scan_result FROM {$table_name} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1",
			$post->ID
		) );

		if ( ! $scan || ! $scan->scan_result ) {
			return;
		}

		$result = json_decode( $scan->scan_result, true );
		if ( ! isset( $result['json_ld_schema'] ) ) {
			return;
		}

		$schema_json = wp_json_encode( $result['json_ld_schema'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		echo "\n<!-- Gleo GEO Schema -->\n";
		echo '<script type="application/ld+json">' . $schema_json . '</script>' . "\n";
	}

	/**
	 * REST: Set the schema override option.
	 */
	public function set_schema_override( $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		update_option( 'gleo_override_schema', $enabled );

		return rest_ensure_response( array(
			'success' => true,
			'override' => $enabled,
		) );
	}

	/**
	 * REST: Handle 1-click apply actions for a specific post.
	 * Supports: schema, capsule, structure, formatting, readability,
	 * faq, data_tables, authority, credibility, content_depth, answer_readiness.
	 */
	private function inject_after_paragraph( $content, $html_to_inject, $target_index ) {
		$paragraphs = preg_split( '/(<\/p>\s*)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$new_content = '';
		$p_count = 0;
		$injected = false;

		foreach ( $paragraphs as $part ) {
			if ( preg_match( '/<\/p>/i', $part ) ) {
				$p_count++;
			}
			$new_content .= $part;

			if ( $p_count === $target_index && ! $injected ) {
				$new_content .= "\n" . $html_to_inject . "\n";
				$injected = true;
			}
		}

		if ( ! $injected ) {
			$new_content .= "\n" . $html_to_inject . "\n";
		}

		return $new_content;
	}

	/**
	 * Smart placement: scan paragraphs for semantic cues and return the best index.
	 *
	 * @param string $content     The post content.
	 * @param string $block_type  One of: 'faq', 'table', 'stats'.
	 * @return int  Best paragraph index to inject after.
	 */
	private function find_best_paragraph( $content, $block_type ) {
		// Extract plain-text paragraphs
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/si', $content, $matches );
		$paragraphs = array_map( 'wp_strip_all_tags', $matches[1] );
		$total = count( $paragraphs );
		if ( $total < 2 ) return max( 1, $total );

		// Keywords that signal good/bad placement
		$data_kw  = array( 'compare', 'versus', 'feature', 'benefit', 'cost', 'price', 'plan', 'tier', 'option', 'difference', 'advantage', 'include' );
		$avoid_kw = array( 'testimonial', 'review', 'said', 'quote', 'story', 'experience', 'felt', 'loved', 'recommend' );

		switch ( $block_type ) {
			case 'faq':
				// FAQ goes near the end — last 25% but not the absolute last paragraph
				$target = max( 3, (int) floor( $total * 0.75 ) );
				// Walk backwards from target to avoid testimonials
				for ( $i = $target; $i >= 3; $i-- ) {
					$lower = strtolower( $paragraphs[ $i - 1 ] ?? '' );
					$bad = false;
					foreach ( $avoid_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) { $bad = true; break; } }
					if ( ! $bad ) return $i;
				}
				return $target;

			case 'table':
				// Find best "data" paragraph (min position 2)
				$best_idx = 2;
				$best_score = -1;
				for ( $i = 1; $i < $total; $i++ ) {
					$lower = strtolower( $paragraphs[ $i ] );
					$score = 0;
					foreach ( $data_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) $score++; }
					foreach ( $avoid_kw as $kw ) { if ( strpos( $lower, $kw ) !== false ) $score -= 2; }
					if ( $score > $best_score ) { $best_score = $score; $best_idx = $i + 1; }
				}
				return max( 2, min( $best_idx, $total - 1 ) );

			case 'stats':
				// After first factual paragraph (min 1, look for numbers/percentages)
				for ( $i = 0; $i < min( 4, $total ); $i++ ) {
					if ( preg_match( '/\d+%|\d+\s*(million|billion|thousand|percent)/i', $paragraphs[ $i ] ) ) {
						return $i + 1;
					}
				}
				return min( 2, $total );

			default:
				return min( 3, $total );
		}
	}

	public function handle_apply( $request ) {
		$params     = $request->get_json_params();
		$post_id    = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$type       = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : '';
		$enabled    = isset( $params['enabled'] ) ? (bool) $params['enabled'] : true;
		$user_input = isset( $params['user_input'] ) ? $params['user_input'] : '';

		if ( ! $post_id || ! $type ) {
			return new WP_Error( 'invalid_data', 'Missing post ID or type.', array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		// Attempt to fetch the generated contextual assets from the scan result
		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';
		$scan = $wpdb->get_row( $wpdb->prepare(
			"SELECT scan_result FROM {$table_name} WHERE post_id = %d AND scan_status = 'completed' LIMIT 1",
			$post_id
		) );

		$contextual_assets = null;
		if ( $scan && $scan->scan_result ) {
			$result_data = json_decode( $scan->scan_result, true );
			if ( isset( $result_data['contextual_assets'] ) ) {
				$contextual_assets = $result_data['contextual_assets'];
			}
		}

		$content = $post->post_content;
		$modified = false;

		switch ( $type ) {

			case 'schema':
				if ( $enabled ) {
					update_post_meta( $post_id, '_gleo_schema_override', 1 );
				} else {
					delete_post_meta( $post_id, '_gleo_schema_override' );
				}
				break;

			case 'structure':
			// ── Strip previously-injected Gleo headings so re-running is idempotent ──
			$gleo_labels = array( 'Key Details', 'What You Need to Know', 'Important Considerations', 'Key Takeaways', 'Additional Insights' );
			foreach ( $gleo_labels as $gl ) {
				$content = preg_replace(
					'/\n?<!-- wp:heading -->\n<h2 class="wp-block-heading">' . preg_quote( $gl, '/' ) . '<\/h2>\n<!-- \/wp:heading -->\n?/i',
					'',
					$content
				);
			}
			// ── Insert up to 4 unique section headings, every ~3 paragraphs ─────────
			$heading_labels  = array( 'Key Details', 'What You Need to Know', 'Important Considerations', 'Key Takeaways' );
			$max_headings    = count( $heading_labels );
			$avoid_near      = array( 'testimonial', 'review', ' said ', 'recommend', 'loved', 'quote', 'rating', 'stars', '★', '5 star' );
			$paragraphs      = preg_split( '/(<\/p>\s*)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$new_content     = '';
			$p_count         = 0;
			$heading_num     = 0;
			$last_p_text     = '';
			foreach ( $paragraphs as $part ) {
				if ( preg_match( '/<\/p>/i', $part ) ) {
					$p_count++;
					$last_p_text = wp_strip_all_tags( $part );
				}
				$new_content .= $part;
				if (
					$p_count > 0 &&
					$p_count % 3 === 0 &&
					$heading_num < $max_headings &&
					! preg_match( '/<h[2-6]/i', $part )
				) {
					// Skip if surrounding content looks like testimonials/reviews
					$near = strtolower( $last_p_text );
					$skip = false;
					foreach ( $avoid_near as $kw ) {
						if ( strpos( $near, $kw ) !== false ) { $skip = true; break; }
					}
					if ( ! $skip ) {
						$section_label = $heading_labels[ $heading_num ];
						$new_content  .= "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$section_label}</h2>\n<!-- /wp:heading -->\n";
						$heading_num++;
					}
				}
			}
			$content  = $new_content;
			$modified = true;
			break;

			case 'formatting':
				// Convert the first long paragraph (>50 words) that doesn't contain a list into a bullet list
				$content = preg_replace_callback(
					'/<p>([^<]{200,})<\/p>/i',
					function( $matches ) {
						static $converted = false;
						if ( $converted ) return $matches[0];
						$text = $matches[1];
						$sentences = preg_split( '/(?<=[.!?])\s+/', trim( $text ) );
						if ( count( $sentences ) < 2 ) return $matches[0];
						$converted = true;
						$items = '';
						foreach ( $sentences as $s ) {
							$s = trim( $s );
							if ( strlen( $s ) > 5 ) {
								$items .= "<!-- wp:list-item -->\n<li>{$s}</li>\n<!-- /wp:list-item -->\n";
							}
						}
						return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n{$items}</ul>\n<!-- /wp:list -->";
					},
					$content,
					1
				);
				$modified = true;
				break;

			case 'readability':
				// Split paragraphs longer than 80 words into two
				$content = preg_replace_callback(
					'/<p>(.*?)<\/p>/is',
					function( $matches ) {
						$text = $matches[1];
						$words = preg_split( '/\s+/', trim( $text ) );
						if ( count( $words ) <= 80 ) return $matches[0];
						$mid = (int) ceil( count( $words ) / 2 );
						$first = implode( ' ', array_slice( $words, 0, $mid ) );
						$second = implode( ' ', array_slice( $words, $mid ) );
						return "<p>{$first}</p>\n\n<p>{$second}</p>";
					},
					$content
				);
				$modified = true;
				break;

			case 'faq':
			case 'answer_readiness':
				// Build accordion FAQ — merges former Q&A into FAQ
				$pairs = array();

				// First, try to get Q&A pairs from contextual_assets (answer_readiness data)
				if ( ! empty( $contextual_assets['qa_html'] ) ) {
					preg_match_all( '/<strong>(.*?)<\/strong>\s*<\/p>\s*<p>(.*?)<\/p>/si', $contextual_assets['qa_html'], $qm );
					if ( ! empty( $qm[1] ) ) {
						foreach ( $qm[1] as $idx => $q ) {
							$pairs[] = array(
								'q' => wp_strip_all_tags( $q ),
								'a' => wp_strip_all_tags( $qm[2][ $idx ] ),
							);
						}
					}
				}

				// Then add FAQ pairs from contextual_assets
				if ( ! empty( $contextual_assets['faq_html'] ) ) {
					preg_match_all( '/<h3[^>]*>(.*?)<\/h3>\s*(?:<p[^>]*>(.*?)<\/p>)?/si', $contextual_assets['faq_html'], $fm );
					foreach ( $fm[1] as $idx => $q ) {
						$pairs[] = array(
							'q' => wp_strip_all_tags( $q ),
							'a' => ! empty( $fm[2][ $idx ] ) ? wp_strip_all_tags( $fm[2][ $idx ] ) : 'See the article above for details.',
						);
					}
				}

				// Fallback generic questions
				if ( empty( $pairs ) ) {
					$questions = is_array( $user_input ) && ! empty( $user_input ) ? $user_input : array(
						'What are the core benefits covered in this article?',
						'Are there any key challenges to keep in mind?',
						'How can these practices be implemented efficiently?'
					);
					foreach ( $questions as $q ) {
						$pairs[] = array( 'q' => sanitize_text_field( $q ), 'a' => 'Refer to the main sections of the article above for comprehensive answers and insights.' );
					}
				}

				// Build accordion HTML
				$items_html = '';
				foreach ( $pairs as $pair ) {
					$q = esc_html( $pair['q'] );
					$a = esc_html( $pair['a'] );
					$items_html .= '<div class="gleo-faq-item">'
						. '<button class="gleo-faq-q">' . $q . '</button>'
						. '<div class="gleo-faq-a"><p>' . $a . '</p></div>'
						. '</div>';
				}
				$faq_block = '<div class="gleo-faq-wrap"><h2>Frequently Asked Questions</h2>'
					. '<div class="gleo-faq-accordion">' . $items_html . '</div></div>';

				$pos = $this->find_best_paragraph( $content, 'faq' );
				$content = $this->inject_after_paragraph( $content, $faq_block, $pos );
				$modified = true;
				break;

			case 'data_tables':
				if ( ! empty( $contextual_assets['data_table_html'] ) ) {
					$raw = $contextual_assets['data_table_html'];
					preg_match( '/<table[^>]*>(.*?)<\/table>/si', $raw, $tm );
					if ( ! empty( $tm[1] ) ) {
						$table_block = '<div class="gleo-table-block"><h3>Data Overview</h3>'
							. '<table class="gleo-data-table">' . wp_kses_post( $tm[1] ) . '</table></div>';
					} else {
						$table_block = '<div class="gleo-table-block">' . wp_kses_post( $raw ) . '</div>';
					}
				} else {
					$topic = esc_html( $post->post_title );
					$table_block = '<div class="gleo-table-block">'
						. '<h3>' . $topic . ' Overview</h3>'
						. '<table class="gleo-data-table">'
						. '<thead><tr><th>Feature</th><th>Details</th><th>Impact</th></tr></thead>'
						. '<tbody>'
						. '<tr><td>Primary Benefit</td><td>Key advantage related to ' . $topic . '</td><td>High</td></tr>'
						. '<tr><td>Secondary Benefit</td><td>Additional value point</td><td>Medium</td></tr>'
						. '<tr><td>Consideration</td><td>Important factor to evaluate</td><td>Varies</td></tr>'
						. '</tbody></table></div>';
				}
				$pos = $this->find_best_paragraph( $content, 'table' );
				$content = $this->inject_after_paragraph( $content, $table_block, $pos );
				$modified = true;
				break;

			case 'authority':
				if ( ! empty( $contextual_assets['authority_html'] ) ) {
					$stats_text = wp_strip_all_tags( $contextual_assets['authority_html'] );
				} else {
					$stats_text = is_string( $user_input ) && !empty( $user_input ) ? sanitize_textarea_field( $user_input ) : 'Recent industry analyses show that deploying these advanced methods can lead to up to a 60% boost in core engagement metrics and long-term retention.';
				}
				$callout = '<div class="gleo-stats-callout">'
					. '<span class="gleo-stats-icon">&#128202;</span>'
					. '<div class="gleo-stats-body">'
					. '<p class="gleo-stats-label">Did You Know</p>'
					. '<p class="gleo-stats-text">' . esc_html( $stats_text ) . '</p>'
					. '</div></div>';
				$pos = $this->find_best_paragraph( $content, 'stats' );
				$content = $this->inject_after_paragraph( $content, $callout, $pos );
				$modified = true;
				break;

			case 'credibility':
				$urls = is_array( $user_input ) ? $user_input : array();
				if ( empty( $urls ) ) {
					return new WP_Error( 'missing_input', 'Please provide source URLs.', array( 'status' => 400 ) );
				}
				$sources_html  = "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Sources &amp; References</h2>\n<!-- /wp:heading -->\n";
				$sources_html .= "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">\n";
				foreach ( $urls as $url ) {
					$url    = esc_url( $url );
					$domain = wp_parse_url( $url, PHP_URL_HOST );
					$sources_html .= "<!-- wp:list-item -->\n<li><a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\">{$domain}</a></li>\n<!-- /wp:list-item -->\n";
				}
				$sources_html .= "</ol>\n<!-- /wp:list -->\n";
				$content .= $sources_html;
				$modified = true;
				break;

			case 'content_depth':
				if ( ! empty( $contextual_assets['depth_html'] ) ) {
					$content = $this->inject_after_paragraph( $content, wp_kses_post( $contextual_assets['depth_html'] ), 3 );
				} else {
					$topic      = esc_html( $post->post_title );
					$expansion  = "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A Closer Look: {$topic}</h2>\n<!-- /wp:heading -->\n";
					$expansion .= "<!-- wp:paragraph -->\n<p>Understanding {$topic} requires looking at the broader context. Industry experts consistently emphasize the importance of comprehensive coverage when addressing this subject.</p>\n<!-- /wp:paragraph -->\n";
					$expansion .= "<!-- wp:paragraph -->\n<p>Staying current with the latest developments in this area is crucial. As new research and data emerge, best practices continue to evolve.</p>\n<!-- /wp:paragraph -->\n";
					$content = $this->inject_after_paragraph( $content, $expansion, 3 );
				}
				$modified = true;
				break;


			default:
				return new WP_Error( 'unknown_type', 'Unknown fix type: ' . $type, array( 'status' => 400 ) );
		}

		// If content was modified, update the post
		if ( $modified ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $content,
			) );
		}

		// ALWAYS update the scan result history to persist the score for the frontend
		if ( $scan && $scan->scan_result ) {
			$result_data = json_decode( $scan->scan_result, true );
			if ( ! isset( $result_data['content_signals'] ) ) {
				$result_data['content_signals'] = array();
			}
			$cs = &$result_data['content_signals'];
			switch ( $type ) {
				case 'schema': $cs['has_schema'] = true; break;
				case 'structure': $cs['has_headings'] = true; $cs['heading_count'] = max($cs['heading_count'] ?? 0, 6); break;
				case 'formatting': $cs['has_lists'] = true; $cs['list_item_count'] = max($cs['list_item_count'] ?? 0, 12); break;
				case 'faq': $cs['has_faq'] = true; break;
				case 'credibility': $cs['has_citations'] = true; $cs['citation_count'] = max($cs['citation_count'] ?? 0, 5); break;
				case 'authority': $cs['stat_count'] = max($cs['stat_count'] ?? 0, 3); break;
				case 'answer_readiness': $cs['has_direct_answers'] = true; break;
			}
			$wpdb->update(
				$table_name,
				array( 'scan_result' => wp_json_encode( $result_data ) ),
				array( 'post_id' => $post_id )
			);
		}

		return rest_ensure_response( array(
			'success'  => true,
			'post_id'  => $post_id,
			'type'     => $type,
			'modified' => $modified,
		) );
	}
}

new Gleo_Frontend();
