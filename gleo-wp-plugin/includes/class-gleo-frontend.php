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

		// /llms.txt is served dynamically (see serve_llms_txt); this <head> link lets crawlers and the Gleo scanner detect it.
		add_action( 'wp_head', array( $this, 'inject_llms_link' ), 2 );

		// Front-end styles for Gleo-injected content blocks
		add_action( 'wp_head', array( $this, 'inject_content_styles' ), 5 );

		// When loading a post inside the Gleo admin preview iframe, force full theme + block CSS
		// so the page does not render as unstyled plain HTML (common with block themes / split bundles).
		add_action( 'wp_enqueue_scripts', array( $this, 'force_full_frontend_assets' ), 100 );

		// Preview iframe: body class + readable fallback layout; optional style-queue logging for admins.
		add_filter( 'body_class', array( $this, 'preview_body_class' ) );
		add_action( 'wp_head', array( $this, 'inject_preview_frame_fallback' ), 2 );
		add_action( 'wp_print_styles', array( $this, 'maybe_log_preview_styles' ), 99999 );

		// REST endpoints for applying fixes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * For ?gleo_iframe=1 (Gleo live preview), ensure global styles and block library load.
	 */
	public function force_full_frontend_assets() {
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flags for public post view
		$gleo_preview = ! empty( $_GET['gleo_iframe'] ) || ! empty( $_GET['gleo_cb'] );
		if ( ! $gleo_preview ) {
			return;
		}
		if ( function_exists( 'wp_enqueue_global_styles' ) ) {
			wp_enqueue_global_styles();
		}
		wp_enqueue_style( 'wp-block-library' );
		// Classic themes: main stylesheet is sometimes skipped in edge iframe loads.
		if ( is_child_theme() && ! wp_style_is( 'gleo-theme-parent', 'enqueued' ) ) {
			wp_enqueue_style(
				'gleo-theme-parent',
				get_template_directory_uri() . '/style.css',
				array(),
				wp_get_theme( get_template() )->get( 'Version' )
			);
		}
		if ( ! wp_style_is( 'gleo-theme-root', 'enqueued' ) ) {
			$deps = is_child_theme() ? array( 'gleo-theme-parent' ) : array();
			wp_enqueue_style( 'gleo-theme-root', get_stylesheet_uri(), $deps, wp_get_theme()->get( 'Version' ) );
		}
		// Load combined block CSS instead of tiny split chunks that optimizers sometimes drop for iframe navigations.
		add_filter( 'should_load_separate_core_block_assets', '__return_false', 99 );

		if ( function_exists( 'wp_enqueue_classic_theme_styles' ) ) {
			wp_enqueue_classic_theme_styles();
		}
		foreach ( array( 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $style_handle ) {
			if ( wp_style_is( $style_handle, 'registered' ) && ! wp_style_is( $style_handle, 'enqueued' ) ) {
				wp_enqueue_style( $style_handle );
			}
		}
	}

	/**
	 * Mark front-end requests loaded in the Gleo preview iframe (for fallback CSS).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function preview_body_class( $classes ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview flags
		if ( ! empty( $_GET['gleo_iframe'] ) || ! empty( $_GET['gleo_cb'] ) ) {
			$classes[] = 'gleo-preview-context';
		}
		return $classes;
	}

	/**
	 * Minimal typography/layout when theme CSS is deferred or missing in iframe loads.
	 */
	public function inject_preview_frame_fallback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['gleo_iframe'] ) && empty( $_GET['gleo_cb'] ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS only
		echo <<<'GLEO_CSS'
<style id="gleo-preview-fallback">
/* Readable fallback background only — typography comes from the theme. */
body.gleo-preview-context { background-color: #f8fafc; color: #0f172a; }
body.gleo-preview-context a { color: #0369a1; }
/* Block / classic themes often cap “content width”; in the Gleo iframe we want full layout width. */
body.gleo-preview-context {
  --wp--style--global--content-size: 100% !important;
  --wp--style--global--wide-size: 100% !important;
}
body.gleo-preview-context .wp-site-blocks,
body.gleo-preview-context main,
body.gleo-preview-context .wp-block-post-content,
body.gleo-preview-context .entry-content {
  max-width: none !important;
  width: 100% !important;
  margin-left: 0 !important;
  margin-right: 0 !important;
  padding-left: 0 !important;
  padding-right: 0 !important;
}
body.gleo-preview-context .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
  max-width: none !important;
}
body.gleo-preview-context .alignwide,
body.gleo-preview-context .alignfull {
  max-width: none !important;
  width: 100% !important;
}
</style>

GLEO_CSS;
	}

	/**
	 * Log enqueued style handles when gleo_preview_debug=1 and user is an admin (check debug.log).
	 */
	public function maybe_log_preview_styles() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['gleo_iframe'] ) && empty( $_GET['gleo_cb'] ) ) {
			return;
		}
		if ( empty( $_GET['gleo_preview_debug'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wp_styles;
		if ( ! ( $wp_styles instanceof WP_Styles ) ) {
			return;
		}
		$queued = array();
		foreach ( (array) $wp_styles->queue as $handle ) {
			$queued[] = $handle;
		}
		error_log( '[GLEO preview] Style queue (' . count( $queued ) . '): ' . implode( ', ', $queued ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
		$accent      = $this->get_theme_accent_color();
		$accent_bg   = $this->hex_to_rgba( $accent, '0.06' );
		$accent_mid  = $this->hex_to_rgba( $accent, '0.16' );
		$accent_soft = $this->hex_to_rgba( $accent, '0.12' );
		?>
<style id="gleo-content-styles">
/* Headings injected by Gleo “structure” fix — inherit theme typography */
.entry-content h2.wp-block-heading.gleo-section-heading,
main h2.wp-block-heading.gleo-section-heading,
.wp-site-blocks h2.wp-block-heading.gleo-section-heading {
	font-family: var(--wp--preset--font-family--heading, var(--wp--preset--font-family--body, inherit));
	font-size: var(--wp--preset--font-size--large, var(--wp--preset--font-size--medium, inherit));
	font-weight: var(--wp--custom--heading--font-weight, 700);
	letter-spacing: var(--wp--custom--heading--letter-spacing, -0.02em);
	line-height: var(--wp--custom--heading--line-height, 1.25);
	color: var(--wp--preset--color--contrast, var(--wp--preset--color--foreground, inherit));
	margin-top: var(--wp--preset--spacing--60, 1.5em);
	margin-bottom: var(--wp--preset--spacing--40, 0.65em);
}
/* ───────────────────────────────────────────────────────────────────────
   Gleo content blocks — clean, modern, theme-aware, fully responsive.
   Uses CSS custom properties so JS can adapt colors per-element based on
   the actual rendered background (light/dark sections).
   ─────────────────────────────────────────────────────────────────────── */
:where(.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block) {
  --gc-text:        #0f172a;
  --gc-muted:       #64748b;
  --gc-subtle:      #94a3b8;
  --gc-border:      #e5e7eb;
  --gc-border-soft: #eef0f3;
  --gc-card:        #ffffff;
  --gc-surface:     #f8fafc;
  --gc-hover:       #f1f5f9;
  --gc-accent:      <?php echo esc_attr( $accent ); ?>;
  --gc-accent-bg:   <?php echo esc_attr( $accent_bg ); ?>;
  --gc-accent-mid:  <?php echo esc_attr( $accent_mid ); ?>;
  --gc-accent-soft: <?php echo esc_attr( $accent_soft ); ?>;
  --gc-shadow:      0 1px 2px rgba(15, 23, 42, 0.04), 0 1px 3px rgba(15, 23, 42, 0.04);
  --gc-radius:      14px;
}

/* ── Shared base ───────────────────────────────────────────────────────── */
.gleo-faq-wrap,
.gleo-stats-callout,
.gleo-table-block {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  color: var(--gc-text);
  line-height: 1.6;
  margin: 1.75em 0;
  clear: both;
  box-sizing: border-box;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
.gleo-faq-wrap *,
.gleo-stats-callout *,
.gleo-table-block * { box-sizing: border-box; }

/* ── FAQ accordion ─────────────────────────────────────────────────────── */
.gleo-faq-wrap > h2 {
  font-size: clamp(1.05rem, 0.9rem + 0.6vw, 1.35rem);
  font-weight: 700;
  letter-spacing: -0.01em;
  margin: 0 0 0.75em;
  color: var(--gc-text);
  line-height: 1.3;
}
.gleo-faq-accordion {
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  overflow: hidden;
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
}
.gleo-faq-item { border-bottom: 1px solid var(--gc-border-soft); }
.gleo-faq-item:last-child { border-bottom: none; }
.gleo-faq-q {
  width: 100%;
  margin: 0;
  padding: 16px 18px;
  background: transparent;
  border: 0;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 14px;
  cursor: pointer;
  text-align: left;
  font: inherit;
  font-size: clamp(0.92rem, 0.85rem + 0.2vw, 1rem);
  font-weight: 600;
  line-height: 1.45;
  color: var(--gc-text);
  transition: background 0.15s ease, color 0.15s ease;
}
.gleo-faq-q:hover { background: var(--gc-hover); }
.gleo-faq-q:focus-visible {
  outline: 2px solid var(--gc-accent);
  outline-offset: -2px;
}
.gleo-faq-q::after {
  content: '';
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  margin-top: 1px;
  border-radius: 999px;
  background:
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/10px 2px no-repeat,
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/2px 10px no-repeat,
    var(--gc-accent-soft);
  transition: transform 0.25s ease, background-size 0.25s ease;
}
.gleo-faq-item.gleo-open .gleo-faq-q {
  color: var(--gc-text);
  background: var(--gc-accent-soft);
}
.gleo-faq-item.gleo-open .gleo-faq-q::after {
  background:
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/10px 2px no-repeat,
    linear-gradient(var(--gc-accent), var(--gc-accent)) center/0 10px no-repeat,
    var(--gc-accent-soft);
  transform: rotate(180deg);
}
.gleo-faq-a {
  max-height: 0;
  overflow: hidden;
  padding: 0 18px;
  font-size: 0.93rem;
  line-height: 1.65;
  color: var(--gc-muted);
  transition: max-height 0.3s ease, padding 0.3s ease;
}
.gleo-faq-a > p { margin: 0; }
.gleo-faq-item.gleo-open .gleo-faq-a {
  max-height: 800px;
  padding: 0 18px 18px;
  color: var(--gc-text);
  background: var(--gc-card);
}

/* ── Data Table ────────────────────────────────────────────────────────── */
.gleo-table-block {
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  background: var(--gc-card);
  box-shadow: var(--gc-shadow);
  overflow: hidden;
  width: 100%;
  max-width: 100%;
  margin-left: 0;
  margin-right: 0;
}
.gleo-table-block > h3 {
  font-size: clamp(0.95rem, 0.88rem + 0.25vw, 1.05rem);
  font-weight: 700;
  letter-spacing: -0.005em;
  margin: 0;
  padding: 14px 18px;
  border-bottom: 1px solid var(--gc-border-soft);
  color: var(--gc-text);
  background: var(--gc-surface);
}
.gleo-table-scroll {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.gleo-data-table {
  width: 100%;
  min-width: 100%;
  border-collapse: collapse;
  text-align: left;
  font-size: 0.92rem;
  background: var(--gc-card);
}
.gleo-data-table thead th {
  padding: 11px 16px;
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--gc-muted);
  background: var(--gc-surface);
  border-bottom: 1px solid var(--gc-border);
  white-space: nowrap;
}
.gleo-data-table tbody td {
  padding: 13px 16px;
  font-size: 0.92rem;
  line-height: 1.55;
  color: var(--gc-text);
  border-bottom: 1px solid var(--gc-border-soft);
  vertical-align: top;
}
.gleo-data-table tbody tr:last-child td { border-bottom: none; }
.gleo-data-table tbody tr:hover td { background: var(--gc-hover); }

/* Mobile / narrow-container fallback: flatten the table into label/value cards */
@media (max-width: 480px) {
  .gleo-data-table thead { display: none; }
  .gleo-data-table tbody, .gleo-data-table tr, .gleo-data-table td { display: block; width: 100%; }
  .gleo-data-table tr {
    padding: 10px 14px;
    border-bottom: 1px solid var(--gc-border-soft);
  }
  .gleo-data-table tr:last-child { border-bottom: none; }
  .gleo-data-table td {
    padding: 4px 0;
    border: 0;
    font-size: 0.92rem;
  }
  .gleo-data-table td[data-label]::before {
    content: attr(data-label);
    display: block;
    font-size: 0.68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gc-muted);
    margin-bottom: 2px;
  }
}

/* ── Stats / figures callout (card aligned with FAQ & tables) ──────────── */
.gleo-stats-callout {
  position: relative;
  background: var(--gc-card);
  border: 1px solid var(--gc-border);
  border-radius: var(--gc-radius);
  padding: 18px 20px;
  box-shadow: var(--gc-shadow);
  display: block;
}
.gleo-stats-inner { margin: 0; }
.gleo-stats-text {
  font-size: clamp(0.94rem, 0.9rem + 0.2vw, 1.02rem);
  font-weight: 400;
  line-height: 1.65;
  margin: 0;
  color: var(--gc-text);
  word-break: break-word;
  overflow-wrap: anywhere;
}

@media (max-width: 360px) {
  .gleo-stats-callout { padding: 16px 16px; }
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

  /* ── FAQ accordion toggle (delegated; supports keyboard activation) ───── */
  function initGleoBlocks() {
    document.body.addEventListener('click', function (e) {
      var btn = e.target.closest('.gleo-faq-q');
      if (!btn) return;
      var item = btn.parentElement;
      var open = item.classList.toggle('gleo-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* ── Adaptive colour: set CSS vars per element based on the element's
       actual rendered background. PHP can't reliably know whether the theme
       drops the post content into a light or dark section. ─────────────── */
    document.querySelectorAll('.gleo-faq-wrap, .gleo-stats-callout, .gleo-table-block').forEach(function (el) {
      var rgb  = getActualBg(el.parentElement || el);
      var dark = lum(rgb[0], rgb[1], rgb[2]) < 0.35;
      if (!dark) return; /* light defaults already match */
      el.style.setProperty('--gc-text',        '#f1f5f9');
      el.style.setProperty('--gc-muted',       '#cbd5e1');
      el.style.setProperty('--gc-subtle',      '#94a3b8');
      el.style.setProperty('--gc-border',      'rgba(255,255,255,0.14)');
      el.style.setProperty('--gc-border-soft', 'rgba(255,255,255,0.08)');
      el.style.setProperty('--gc-card',        'rgba(255,255,255,0.04)');
      el.style.setProperty('--gc-surface',     'rgba(255,255,255,0.06)');
      el.style.setProperty('--gc-hover',       'rgba(255,255,255,0.06)');
      el.style.setProperty('--gc-shadow',      '0 1px 2px rgba(0,0,0,0.2), 0 1px 3px rgba(0,0,0,0.15)');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGleoBlocks);
  } else {
    initGleoBlocks();
  }
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Match /llms.txt exactly (ignore query strings)
		if ( wp_parse_url( $request_uri, PHP_URL_PATH ) !== '/llms.txt' ) {
			return;
		}

		// Helper: keep newlines safe for a plain-text response.
		$plain = function ( $value ) {
			return str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) );
		};

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Robots-Tag: noindex' );

		$site_name = $plain( get_bloginfo( 'name' ) );
		$site_desc = $plain( get_bloginfo( 'description' ) );
		$site_url  = esc_url_raw( get_site_url() );

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
				$post = get_post( (int) $row->post_id );
				if ( ! $post ) continue;

				echo '### ' . $plain( $post->post_title ) . "\n";
				echo '- URL: ' . esc_url_raw( get_permalink( $post->ID ) ) . "\n\n";
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
	 * Output a discovery link to /llms.txt (served by Gleo on template_redirect).
	 */
	public function inject_llms_link() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
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
	/**
	 * Add `data-label="<th>"` attributes to every <td> in a table fragment so the
	 * mobile/narrow CSS fallback can render headerless rows as clean label/value pairs.
	 *
	 * Accepts the inner-HTML of a <table> (i.e. the thead+tbody fragment) and returns
	 * the same fragment with annotated <td> tags. Safe to call on already-annotated
	 * markup — it will not double-add the attribute.
	 */
	private function annotate_table_with_data_labels( $table_inner_html ) {
		// Extract header labels from the first row of <thead>.
		$labels = array();
		if ( preg_match( '/<thead[^>]*>(.*?)<\/thead>/si', $table_inner_html, $thead_m ) ) {
			if ( preg_match_all( '/<th[^>]*>(.*?)<\/th>/si', $thead_m[1], $th_m ) ) {
				foreach ( $th_m[1] as $h ) {
					$labels[] = trim( wp_strip_all_tags( $h ) );
				}
			}
		}

		if ( empty( $labels ) ) {
			return $table_inner_html;
		}

		// Walk every <tr> inside the <tbody> (or all <tr> outside <thead>) and
		// annotate <td> tags positionally.
		return preg_replace_callback(
			'/<tbody[^>]*>(.*?)<\/tbody>/si',
			function ( $tbody_match ) use ( $labels ) {
				$body = preg_replace_callback(
					'/<tr[^>]*>(.*?)<\/tr>/si',
					function ( $tr_match ) use ( $labels ) {
						$idx = 0;
						$row = preg_replace_callback(
							'/<td(\s[^>]*)?>/i',
							function ( $td_match ) use ( $labels, &$idx ) {
								$existing_attrs = isset( $td_match[1] ) ? $td_match[1] : '';
								// Skip if the tag already has data-label.
								if ( stripos( $existing_attrs, 'data-label' ) !== false ) {
									$idx++;
									return $td_match[0];
								}
								$label = isset( $labels[ $idx ] ) ? $labels[ $idx ] : '';
								$idx++;
								if ( $label === '' ) {
									return $td_match[0];
								}
								return '<td' . $existing_attrs . ' data-label="' . esc_attr( $label ) . '">';
							},
							$tr_match[1]
						);
						return '<tr>' . $row . '</tr>';
					},
					$tbody_match[1]
				);
				return '<tbody>' . $body . '</tbody>';
			},
			$table_inner_html
		);
	}

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

	/**
	 * Short label from post title for contextual section headings (no generic marketing phrases).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function gleo_short_topic_label( $post ) {
		$title = trim( wp_strip_all_tags( $post->post_title ) );
		if ( '' === $title ) {
			$words = preg_split( '/\s+/', wp_strip_all_tags( $post->post_content ), 8, PREG_SPLIT_NO_EMPTY );
			if ( ! empty( $words ) ) {
				return implode( ' ', array_slice( $words, 0, 5 ) );
			}
			return 'this topic';
		}
		// Drop subtitle after colon/em dash so headings stay readable.
		$title = preg_replace( '/\s*[:|–—-]\s*.+$/u', '', $title );
		$title = trim( $title );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 52 ) {
			return trim( mb_substr( $title, 0, 50 ) ) . '…';
		}
		if ( strlen( $title ) > 52 ) {
			return trim( substr( $title, 0, 50 ) ) . '…';
		}
		return $title;
	}

	/**
	 * Curated H2 templates for reader-facing articles (one %s = short topic). No corporate jargon.
	 *
	 * @return string[]
	 */
	private function gleo_section_heading_template_pool() {
		return array(
			'Background on %s',
			'A closer look at %s',
			'Why %s matters',
			'What to know about %s',
			'Key facts about %s',
			'The basics of %s',
			'Getting started with %s',
			'Common questions about %s',
			'How %s works in practice',
			'Real-world examples of %s',
			'Benefits of %s',
			'Drawbacks of %s',
			'When to choose %s',
			'When to avoid %s',
			'Who %s is for',
			'How much %s costs',
			'How long %s takes',
			'What you need before %s',
			'What happens after %s',
			'Tips for %s',
			'Mistakes to avoid with %s',
			'Best practices for %s',
			'Step-by-step: %s',
			'Checklist for %s',
			'Tools that help with %s',
			'Resources for learning %s',
			'Further reading on %s',
			'Related topics to %s',
			'How %s compares to alternatives',
			'History of %s',
			'The future of %s',
			'Industry context for %s',
			'Expert perspective on %s',
			'Customer stories about %s',
			'Case study: %s',
			'Data behind %s',
			'Research on %s',
			'Safety notes on %s',
			'Legal notes on %s',
			'Privacy and %s',
			'Security and %s',
			'Accessibility and %s',
			'Performance and %s',
			'Maintenance for %s',
			'Troubleshooting %s',
			'FAQ follow-ups on %s',
			'Glossary: %s',
			'Summary of %s',
			'Recap: %s',
			'Next steps with %s',
			'Wrapping up %s',
			'Editor’s notes on %s',
			'Behind the scenes of %s',
			'How we tested %s',
			'How we chose %s',
			'Updates on %s',
			'Changelog for %s',
			'Version notes on %s',
			'Regional differences in %s',
			'Seasonal notes on %s',
			'For beginners: %s',
			'For advanced readers: %s',
			'Shortcuts for %s',
			'Workarounds for %s',
			'Alternatives to %s',
			'Complements to %s',
			'Pairing %s with other ideas',
			'Expanding on %s',
			'Narrowing down %s',
			'Putting %s in context',
			'Breaking down %s',
			'Building up %s',
			'Connecting %s to your goals',
			'From the archives: %s',
			'Reader mail about %s',
		);
	}

	/**
	 * Up to four section titles derived from the post (replaces hard-coded generic labels).
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function gleo_section_heading_labels_for_post( $post ) {
		$t    = $this->gleo_short_topic_label( $post );
		$pool = $this->gleo_section_heading_template_pool();
		$n    = count( $pool );
		if ( $n < 4 ) {
			return array_fill( 0, 4, $t );
		}
		$seed = (int) crc32( (string) $post->ID . "\x1f" . (string) ( $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ) );
		$idxs = array();
		$step = max( 1, ( $seed % 11 ) + 3 );
		$c    = $seed % $n;
		for ( $i = 0; $i < 4; $i++ ) {
			$guard = 0;
			while ( in_array( $c, $idxs, true ) && $guard < $n ) {
				$c = ( $c + 1 ) % $n;
				$guard++;
			}
			$idxs[] = $c;
			$c      = ( $c + $step ) % $n;
		}
		$out = array();
		foreach ( $idxs as $ix ) {
			$out[] = sprintf( $pool[ $ix ], $t );
		}
		return $out;
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
			$content = preg_replace(
				'/\n?<!-- wp:heading -->\s*<h2[^>]*gleo-section-heading[^>]*>.*?<\/h2>\s*<!-- \/wp:heading -->\s*/is',
				'',
				$content
			);
			$gleo_legacy = array( 'Key Details', 'What You Need to Know', 'Important Considerations', 'Key Takeaways', 'Additional Insights' );
			foreach ( $gleo_legacy as $gl ) {
				$content = preg_replace(
					'/\n?<!-- wp:heading -->\s*<h2[^>]*>' . preg_quote( $gl, '/' ) . '<\/h2>\s*<!-- \/wp:heading -->\s*/i',
					'',
					$content
				);
			}
			// ── Insert up to 4 contextual section headings, every ~3 paragraphs ───────
			$p_total = (int) preg_match_all( '/<\/p>/i', $content );
			$heading_labels  = $this->gleo_section_heading_labels_for_post( $post );
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
					$p_count < $p_total &&
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
						$section_label = esc_html( $heading_labels[ $heading_num ] );
						$new_content  .= "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading gleo-section-heading\">{$section_label}</h2>\n<!-- /wp:heading -->\n";
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
						. '<button type="button" class="gleo-faq-q" aria-expanded="false">' . $q . '</button>'
						. '<div class="gleo-faq-a"><p>' . $a . '</p></div>'
						. '</div>';
				}
				$faq_inner = '<div class="gleo-faq-wrap"><h2>Frequently Asked Questions</h2>'
					. '<div class="gleo-faq-accordion">' . $items_html . '</div></div>';
				// Wrap as a proper Gutenberg HTML block so the block editor preserves it intact.
				$faq_block = "<!-- wp:html -->\n" . $faq_inner . "\n<!-- /wp:html -->";

				$pos = $this->find_best_paragraph( $content, 'faq' );
				$content = $this->inject_after_paragraph( $content, $faq_block, $pos );
				$modified = true;
				break;

			case 'data_tables':
				if ( ! empty( $contextual_assets['data_table_html'] ) ) {
					$raw = $contextual_assets['data_table_html'];
					preg_match( '/<table[^>]*>(.*?)<\/table>/si', $raw, $tm );
					if ( ! empty( $tm[1] ) ) {
						$inner_table = $this->annotate_table_with_data_labels( $tm[1] );
						$table_inner = '<div class="gleo-table-block"><h3>Data Overview</h3>'
							. '<div class="gleo-table-scroll"><table class="gleo-data-table">' . $inner_table . '</table></div></div>';
					} else {
						$table_inner = '<div class="gleo-table-block"><div class="gleo-table-scroll">' . wp_kses_post( $raw ) . '</div></div>';
					}
				} else {
					$topic = esc_html( $post->post_title );
					$rows  = '<thead><tr><th>Feature</th><th>Details</th><th>Impact</th></tr></thead>'
						. '<tbody>'
						. '<tr><td>Primary Benefit</td><td>Key advantage related to ' . $topic . '</td><td>High</td></tr>'
						. '<tr><td>Secondary Benefit</td><td>Additional value point</td><td>Medium</td></tr>'
						. '<tr><td>Consideration</td><td>Important factor to evaluate</td><td>Varies</td></tr>'
						. '</tbody>';
					$rows = $this->annotate_table_with_data_labels( $rows );
					$table_inner = '<div class="gleo-table-block">'
						. '<h3>' . $topic . ' Overview</h3>'
						. '<div class="gleo-table-scroll"><table class="gleo-data-table">'
						. $rows
						. '</table></div></div>';
				}
				$table_block = "<!-- wp:html -->\n" . $table_inner . "\n<!-- /wp:html -->";
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
				$callout_inner = '<aside class="gleo-stats-callout" role="note">'
					. '<div class="gleo-stats-inner">'
					. '<p class="gleo-stats-text">' . esc_html( $stats_text ) . '</p>'
					. '</div></aside>';
				$callout = "<!-- wp:html -->\n" . $callout_inner . "\n<!-- /wp:html -->";
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
