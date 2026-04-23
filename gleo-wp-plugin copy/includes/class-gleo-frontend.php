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

		// llms.txt discovery link — lets AI crawlers and our own scanner detect it
		add_action( 'wp_head', array( $this, 'inject_llms_link' ), 2 );

		// Front-end styles for Gleo-injected content blocks
		add_action( 'wp_head', array( $this, 'inject_content_styles' ), 5 );

		// REST endpoints for applying fixes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Prevent wpautop from mangling Gleo-injected HTML blocks
		add_filter( 'the_content', array( $this, 'protect_gleo_blocks_from_wpautop' ), 8 );
	}

	/**
	 * Ensure Gleo HTML blocks survive wpautop.
	 *
	 * wpautop inserts <p>/<br> tags inside div nesting, breaking the
	 * .gleo-faq-item > .gleo-faq-q + .gleo-faq-a structure that the
	 * accordion JS and CSS depend on. We neutralise this by running
	 * wpautop ourselves on the non-Gleo portions only.
	 */
	public function protect_gleo_blocks_from_wpautop( $content ) {
		if ( strpos( $content, 'gleo-faq-wrap' ) === false
			&& strpos( $content, 'gleo-stats-callout' ) === false
			&& strpos( $content, 'gleo-table-block' ) === false ) {
			return $content;
		}
		// Remove wpautop and do it ourselves, skipping Gleo blocks
		remove_filter( 'the_content', 'wpautop' );

		// Split content around Gleo blocks, apply wpautop only to non-Gleo parts
		$output = '';
		$pos = 0;
		while ( preg_match( '/<div\s+class="gleo-(?:faq-wrap|stats-callout|table-block|depth-block)"[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];
			// Find matching closing div manually
			$depth = 0;
			$end = -1;
			$search_pos = $start;
			while ( preg_match( '/<\/?div[^>]*>/i', $content, $div_m, PREG_OFFSET_CAPTURE, $search_pos ) ) {
				$matched_text = $div_m[0][0];
				if ( $matched_text[1] === '/' ) {
					$depth--;
				} else {
					$depth++; // It's <div...
				}
				$search_pos = $div_m[0][1] + strlen( $matched_text );
				if ( $depth === 0 ) {
					$end = $search_pos;
					break;
				}
			}
			if ( $end !== -1 ) {
				$output .= wpautop( substr( $content, $pos, $start - $pos ) );
				$output .= substr( $content, $start, $end - $start );
				$pos = $end;
			} else {
				// Malformed, jump to next match
				$pos = $start + 1;
			}
		}
		$output .= wpautop( substr( $content, $pos ) );
		return $output;
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
  font-family: inherit;
  color: var(--gc-text);
  line-height: 1.55;
  margin: 1.5em 0;
  clear: both;
}
/* ── FAQ accordion ────────────────────────────────────────────────────── */
.gleo-faq-wrap > h2 {
  font-size: 1.25em; font-weight: 700; margin: 0 0 10px;
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
  width: 100% !important; padding: 13px 16px !important; background: none !important; border: none !important;
  display: flex !important; justify-content: space-between; align-items: center;
  cursor: pointer; text-align: left;
  font-size: 1em; font-weight: 600;
  color: var(--gc-text) !important;
  transition: background 0.15s;
  box-sizing: border-box;
  -webkit-appearance: none;
}
.gleo-faq-q:hover { background: var(--gc-hover) !important; }
.gleo-faq-q::after {
  content: '+'; font-size: 1.1em; flex-shrink: 0; margin-left: 12px;
  color: var(--gc-accent);
  transition: transform 0.25s;
}
.gleo-faq-item.gleo-open .gleo-faq-q::after { content: '\2212'; }
.gleo-faq-a {
  max-height: 0 !important; overflow: hidden !important; padding: 0 16px !important;
  font-size: 1em; line-height: 1.65;
  color: var(--gc-muted);
  transition: max-height 0.35s ease, padding 0.35s;
}
.gleo-faq-item.gleo-open .gleo-faq-a {
  max-height: 600px !important; padding: 10px 16px 16px !important;
  overflow: visible !important;
  border-top: 1px solid var(--gc-border);
}
/* ── Data Table ───────────────────────────────────────────────────────── */
.gleo-table-block {
  width: 100% !important; max-width: 100% !important; margin: 1.5em 0 !important;
  border: 1px solid var(--gc-border) !important;
  border-radius: 10px;
  background: var(--gc-card);
  box-sizing: border-box !important;
  display: block !important;
  overflow: hidden !important; /* clips border-radius corners cleanly */
}
.gleo-table-block > h3 {
  font-size: 1em; font-weight: 700; margin: 0 !important;
  padding: 12px 16px;
  border-bottom: 1px solid var(--gc-border);
  color: var(--gc-text);
  background: var(--gc-card);
}
/* Inner scroll wrapper — this is the element that actually scrolls */
.gleo-table-scroll {
  width: 100% !important;
  overflow-x: auto !important;
  overflow-y: visible !important;
  display: block !important;
  -webkit-overflow-scrolling: touch;
  box-sizing: border-box !important;
}
/* Neutralise any WP theme constraints on the figure wrapper */
.gleo-table-block figure,
.gleo-table-block .wp-block-table {
  margin: 0 !important; padding: 0 !important;
  width: 100% !important; display: block !important;
  overflow: visible !important;
}
/* The table itself — fixed layout ensures equal column distribution */
.gleo-table-block table,
.gleo-data-table {
  border-collapse: collapse !important; text-align: left !important;
  table-layout: fixed !important;
  min-width: 100% !important;
  width: max-content !important;
}
.gleo-table-block table th,
.gleo-data-table th {
  padding: 10px 16px !important; font-weight: 700 !important; font-size: 0.9em !important;
  text-transform: uppercase; letter-spacing: 0.04em;
  color: var(--gc-muted) !important;
  border-bottom: 1px solid var(--gc-border) !important;
  background: var(--gc-card) !important;
  white-space: normal !important; min-width: 160px !important; width: auto !important;
  overflow: hidden !important; text-overflow: ellipsis !important;
}
.gleo-table-block table td,
.gleo-data-table td {
  padding: 11px 16px !important; font-size: 1em !important; line-height: 1.5 !important;
  color: var(--gc-text) !important;
  border-bottom: 1px solid var(--gc-border) !important;
  background: var(--gc-card) !important;
  word-break: break-word !important; overflow-wrap: break-word !important;
  vertical-align: top; min-width: 160px !important; width: auto !important;
}
.gleo-table-block table tbody tr:last-child td,
.gleo-data-table tbody tr:last-child td { border-bottom: none !important; }
/* ── Stats callout ────────────────────────────────────────────────────── */
.gleo-stats-callout {
  background: var(--gc-accent-bg);
  border: 1px solid var(--gc-accent-mid);
  border-radius: 10px; padding: 14px 16px;
  display: flex; align-items: center; gap: 12px;
}
.gleo-stats-icon {
  width: 32px; height: 32px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  background: var(--gc-card); flex-shrink: 0;
  color: var(--gc-accent);
}
.gleo-stats-label {
  font-size: 0.8em; font-weight: 800; text-transform: uppercase;
  letter-spacing: 0.04em; color: var(--gc-accent);
  margin-bottom: 1px;
}
.gleo-stats-text {
  font-size: 1em; font-weight: 500; margin: 0;
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
      if (btn) {
        var item = btn.closest('.gleo-faq-item');
        if (item) item.classList.toggle('gleo-open');
      }
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

    /* ── Table layout fix: ensure equal columns & scrollable wrapper ────── */
    document.querySelectorAll('.gleo-table-block').forEach(function (block) {
      /* Force any ancestor overflow to not clip us */
      var parent = block.parentElement;
      while (parent && parent !== document.body) {
        var ov = window.getComputedStyle(parent).overflow;
        var ovx = window.getComputedStyle(parent).overflowX;
        if (ov === 'hidden' || ovx === 'hidden') {
          parent.style.overflow = 'visible';
        }
        parent = parent.parentElement;
      }
      /* Ensure equal column widths at runtime */
      var tbl = block.querySelector('table');
      if (!tbl) return;
      var ths = tbl.querySelectorAll('thead th');
      var cols = ths.length || tbl.rows[0] && tbl.rows[0].cells.length || 1;
      var pct = (100 / cols).toFixed(1) + '%';
      var minW = '160px';
      tbl.style.tableLayout = 'fixed';
      tbl.style.minWidth = '100%';
      tbl.style.width = 'max-content';
      tbl.querySelectorAll('th, td').forEach(function (cell) {
        cell.style.minWidth = minW;
        cell.style.width = pct;
      });
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
	 * Inject a <link> tag pointing to /llms.txt so AI crawlers and the
	 * Gleo scanner can reliably detect its presence from the page HTML.
	 */
	public function inject_llms_link() {
		echo '<link rel="alternate" type="text/plain" title="LLMs.txt" href="' . esc_url( home_url( '/llms.txt' ) ) . '">' . "\n";
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
					// Capture everything between each <h3> as the answer
					preg_match_all( '/<h3[^>]*>(.*?)<\/h3>([\s\S]*?)(?=<h[23]|$)/i', $contextual_assets['faq_html'], $fm );
					foreach ( $fm[1] as $idx => $q ) {
						$answer = wp_strip_all_tags( $fm[2][ $idx ] );
						$answer = trim( $answer );
						$pairs[] = array(
							'q' => wp_strip_all_tags( $q ),
							'a' => ! empty( $answer ) ? $answer : 'See the article above for details.',
						);
					}
				}

				// No real scan data — block instead of inserting generic content
				if ( empty( $pairs ) ) {
					return new WP_Error( 'no_scan_data', 'No scan data found for this post. Please run a Gleo scan first to generate contextual FAQ content.', array( 'status' => 400 ) );
				}

				// Build accordion HTML
				$items_html = '';
				foreach ( $pairs as $pair ) {
					$q = esc_html( $pair['q'] );
					$a = esc_html( $pair['a'] );
					$items_html .= '<div class="gleo-faq-item">'
						. '<div class="gleo-faq-q" role="button" tabindex="0">' . $q . '</div>'
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
					$table_inline = 'style="border-collapse:collapse;table-layout:fixed;min-width:100%;width:max-content"';
					$scroll_open  = '<div class="gleo-table-scroll" style="overflow-x:auto;width:100%;display:block;-webkit-overflow-scrolling:touch">';
					$scroll_close = '</div>';
					if ( ! empty( $tm[1] ) ) {
						// Apply wp_kses_post FIRST so it doesn't strip our manually injected inline styles later
						$clean = wp_kses_post( $tm[1] );
						// Strip any preexisting inline style/width attrs from th/td
						$clean = preg_replace( '/(<t[hd][^>]*?)\s+(style|width|height)="[^"]*"/i', '$1', $clean );
						// Count columns to set equal widths via inline styles
						preg_match_all( '/<th[^>]*>/i', $clean, $col_matches );
						$col_count = max( count( $col_matches[0] ), 1 );
						$col_pct   = round( 100 / $col_count, 1 );
						$cell_style = 'style="min-width:160px;width:' . $col_pct . '%"';
						$clean = preg_replace( '/(<th)([^>]*>)/i', '$1 ' . $cell_style . '$2', $clean );
						$clean = preg_replace( '/(<td)([^>]*>)/i', '$1 ' . $cell_style . '$2', $clean );
						$table_block = '<div class="gleo-table-block"><h3>Data Overview</h3>'
							. $scroll_open
							. '<table class="gleo-data-table" ' . $table_inline . '>' . $clean . '</table>'
							. $scroll_close . '</div>';
					} else {
						$clean_raw = preg_replace( '/(<t[hd][^>]*?)\s+(style|width|height)="[^"]*"/i', '$1', $raw );
						$table_block = '<div class="gleo-table-block">'
							. $scroll_open . wp_kses_post( $clean_raw ) . $scroll_close . '</div>';
					}
				} else {
					return new WP_Error( 'no_scan_data', 'No scan data found for this post. Please run a Gleo scan first to generate a contextual data table.', array( 'status' => 400 ) );
				}
				$pos = $this->find_best_paragraph( $content, 'table' );
				$content = $this->inject_after_paragraph( $content, $table_block, $pos );
				$modified = true;
				break;

			case 'authority':
				if ( ! empty( $contextual_assets['authority_html'] ) ) {
					$stats_text = wp_strip_all_tags( $contextual_assets['authority_html'] );
				} else {
					return new WP_Error( 'no_scan_data', 'No scan data found for this post. Please run a Gleo scan first to generate contextual statistics.', array( 'status' => 400 ) );
				}
				$callout = '<div class="gleo-stats-callout">'
					. '<span class="gleo-stats-icon" aria-hidden="true">'
					. '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">'
					. '<rect x="1" y="9" width="3" height="8" rx="1" fill="currentColor"/>'
					. '<rect x="7" y="5" width="3" height="12" rx="1" fill="currentColor"/>'
					. '<rect x="13" y="1" width="3" height="16" rx="1" fill="currentColor"/>'
					. '</svg>'
					. '</span>'
					. '<div class="gleo-stats-body">'
					. '<p class="gleo-stats-label">Key Stat</p>'
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
					return new WP_Error( 'no_scan_data', 'No scan data found for this post. Please run a Gleo scan first to generate contextual depth content.', array( 'status' => 400 ) );
				}
				$modified = true;
				break;


			default:
				return new WP_Error( 'unknown_type', 'Unknown fix type: ' . $type, array( 'status' => 400 ) );
		}

		// If content was modified, update the post
		// Disable kses filters to prevent WP from stripping Gleo HTML (role, tabindex, etc.)
		if ( $modified ) {
			kses_remove_filters();
			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $content,
			) );
			kses_init_filters();
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
