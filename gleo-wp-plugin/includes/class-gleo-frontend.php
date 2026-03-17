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
	 * Output CSS for all Gleo-injected content blocks (FAQ, tables, stats, Q&A).
	 * Only on singular posts that have a completed scan.
	 */
	public function inject_content_styles() {
		if ( ! is_singular( 'post' ) ) return;
		$accent     = $this->get_theme_accent_color();
		$accent_bg  = $this->hex_to_rgba( $accent, '0.07' );
		$accent_mid = $this->hex_to_rgba( $accent, '0.15' );
		?>
<style id="gleo-content-styles">
:root {
	--gleo-accent: <?php echo esc_attr( $accent ); ?>;
	--gleo-accent-bg: <?php echo esc_attr( $accent_bg ); ?>;
	--gleo-accent-mid: <?php echo esc_attr( $accent_mid ); ?>;
}
/* ---- Gleo FAQ Flip Cards ---- */
.gleo-faq-wrap {
	margin: 2em 0;
}
.gleo-faq-wrap > h2 {
	font-size: 1.5em;
	font-weight: 700;
	margin-bottom: 1em;
	letter-spacing: -0.02em;
}
.gleo-faq-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}
.gleo-faq-card {
	perspective: 1000px;
	min-height: 140px;
	cursor: pointer;
}
.gleo-faq-inner {
	position: relative;
	width: 100%;
	height: 100%;
	min-height: 140px;
	transition: transform 0.55s cubic-bezier(0.4, 0, 0.2, 1);
	transform-style: preserve-3d;
}
.gleo-faq-card.gleo-flipped .gleo-faq-inner {
	transform: rotateY(180deg);
}
.gleo-faq-front,
.gleo-faq-back {
	position: absolute;
	inset: 0;
	backface-visibility: hidden;
	-webkit-backface-visibility: hidden;
	border-radius: 12px;
	padding: 20px 22px;
	display: flex;
	flex-direction: column;
	justify-content: center;
}
.gleo-faq-front {
	background: #f8fafc;
	border: 1.5px solid #e2e8f0;
	color: #0f172a;
}
.gleo-faq-front-q {
	font-size: 0.95em;
	font-weight: 600;
	line-height: 1.45;
	margin: 0 0 10px;
}
.gleo-faq-hint {
	font-size: 0.72em;
	color: #94a3b8;
	display: flex;
	align-items: center;
	gap: 4px;
	margin-top: auto;
}
.gleo-faq-back {
	background: var(--gleo-accent);
	color: #fff;
	transform: rotateY(180deg);
	border: 1.5px solid var(--gleo-accent);
}
.gleo-faq-back-a {
	font-size: 0.88em;
	line-height: 1.6;
	margin: 0;
}

/* ---- Gleo Stats Callout ---- */
.gleo-stats-callout {
	margin: 1.75em 0;
	background: var(--gleo-accent-bg);
	border-left: 4px solid var(--gleo-accent);
	border-radius: 0 10px 10px 0;
	padding: 18px 22px;
	display: flex;
	align-items: flex-start;
	gap: 14px;
}
.gleo-stats-icon {
	font-size: 1.4em;
	flex-shrink: 0;
	margin-top: 2px;
}
.gleo-stats-body {
	flex: 1;
}
.gleo-stats-label {
	font-size: 0.7em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: var(--gleo-accent);
	margin: 0 0 6px;
}
.gleo-stats-text {
	font-size: 0.92em;
	line-height: 1.6;
	color: #1e3a5f;
	margin: 0;
}

/* ---- Gleo Data Table ---- */
.gleo-table-block {
	margin: 1.75em 0;
	overflow-x: auto;
	border-radius: 10px;
	border: 1px solid #e2e8f0;
	box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.gleo-table-block > h2 {
	font-size: 1.1em;
	font-weight: 700;
	padding: 14px 20px 0;
	margin: 0 0 2px;
}
.gleo-data-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}
.gleo-data-table thead tr {
	background: #f1f5f9;
}
.gleo-data-table th {
	padding: 11px 16px;
	text-align: left;
	font-weight: 600;
	font-size: 0.85em;
	color: #475569;
	border-bottom: 1px solid #e2e8f0;
}
.gleo-data-table td {
	padding: 11px 16px;
	border-bottom: 1px solid #f1f5f9;
	color: #1e293b;
	line-height: 1.5;
}
.gleo-data-table tbody tr:last-child td {
	border-bottom: none;
}
.gleo-data-table tbody tr:hover {
	background: #f8fafc;
}

/* ---- Gleo Q&A Block ---- */
.gleo-qa-block {
	margin: 1.75em 0;
	border-radius: 10px;
	border: 1px solid #e2e8f0;
	overflow: hidden;
}
.gleo-qa-block > .gleo-qa-title {
	font-size: 1.05em;
	font-weight: 700;
	background: #f8fafc;
	padding: 14px 20px;
	margin: 0;
	border-bottom: 1px solid #e2e8f0;
}
.gleo-qa-item {
	padding: 16px 20px;
	border-bottom: 1px solid #f1f5f9;
}
.gleo-qa-item:last-child {
	border-bottom: none;
}
.gleo-qa-q {
	font-weight: 600;
	font-size: 0.93em;
	color: #0f172a;
	margin: 0 0 6px;
	display: flex;
	align-items: flex-start;
	gap: 8px;
}
.gleo-qa-q::before {
	content: "Q";
	background: var(--gleo-accent);
	color: white;
	font-size: 0.75em;
	font-weight: 700;
	width: 18px;
	height: 18px;
	border-radius: 4px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	margin-top: 1px;
}
.gleo-qa-a {
	font-size: 0.88em;
	line-height: 1.65;
	color: #475569;
	margin: 0;
	padding-left: 26px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
	document.querySelectorAll('.gleo-faq-card').forEach(function(card) {
		card.addEventListener('click', function() {
			card.classList.toggle('gleo-flipped');
		});
	});
});
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
	 * faq, data_tables, authority, credibility, content_depth.
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

		// Fallback: if there weren't enough paragraphs, append to the end
		if ( ! $injected ) {
			$new_content .= "\n" . $html_to_inject . "\n";
		}

		return $new_content;
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
				// Insert H2 headings at logical breakpoints (every ~3 paragraphs)
				$paragraphs = preg_split( '/(<\/p>\s*)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
				$new_content = '';
				$p_count = 0;
				$heading_num = 1;
				$title_words = explode( ' ', $post->post_title );
				foreach ( $paragraphs as $part ) {
					if ( preg_match( '/<\/p>/i', $part ) ) {
						$p_count++;
					}
					$new_content .= $part;
					if ( $p_count > 0 && $p_count % 3 === 0 && ! preg_match( '/<h[2-6]/i', $part ) ) {
						$section_label = $heading_num === 1 ? 'Key Details' : ( $heading_num === 2 ? 'Important Considerations' : 'Additional Insights' );
						$new_content .= "\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$section_label}</h2>\n<!-- /wp:heading -->\n";
						$heading_num++;
					}
				}
				$content = $new_content;
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
				$build_faq_cards = function( $pairs ) {
					$cards = '';
					foreach ( $pairs as $pair ) {
						$q = esc_html( $pair['q'] );
						$a = esc_html( $pair['a'] );
						$cards .= '<div class="gleo-faq-card">'
							. '<div class="gleo-faq-inner">'
							. '<div class="gleo-faq-front"><p class="gleo-faq-front-q">' . $q . '</p><span class="gleo-faq-hint">&#8635; Tap to reveal answer</span></div>'
							. '<div class="gleo-faq-back"><p class="gleo-faq-back-a">' . $a . '</p></div>'
							. '</div></div>';
					}
					return '<div class="gleo-faq-wrap"><h2>Frequently Asked Questions</h2>'
						. '<div class="gleo-faq-grid">' . $cards . '</div></div>';
				};

				if ( ! empty( $contextual_assets['faq_html'] ) ) {
					preg_match_all( '/<h3[^>]*>(.*?)<\/h3>\s*(?:<p[^>]*>(.*?)<\/p>)?/si', $contextual_assets['faq_html'], $fm );
					$pairs = array();
					foreach ( $fm[1] as $idx => $q ) {
						$pairs[] = array(
							'q' => wp_strip_all_tags( $q ),
							'a' => ! empty( $fm[2][ $idx ] ) ? wp_strip_all_tags( $fm[2][ $idx ] ) : 'See the article above for details.',
						);
					}
					$faq_block = ! empty( $pairs ) ? $build_faq_cards( $pairs ) : wp_kses_post( $contextual_assets['faq_html'] );
				} else {
					$questions = is_array( $user_input ) ? $user_input : array();
					if ( empty( $questions ) ) {
						return new WP_Error( 'missing_input', 'Please provide FAQ questions.', array( 'status' => 400 ) );
					}
					$pairs = array();
					foreach ( $questions as $q ) {
						$pairs[] = array( 'q' => sanitize_text_field( $q ), 'a' => 'Refer to the article above for a full answer.' );
					}
					$faq_block = $build_faq_cards( $pairs );
				}
				$content = $this->inject_after_paragraph( $content, $faq_block, 5 );
				$modified = true;
				break;

			case 'data_tables':
				if ( ! empty( $contextual_assets['data_table_html'] ) ) {
					$raw = $contextual_assets['data_table_html'];
					preg_match( '/<table[^>]*>(.*?)<\/table>/si', $raw, $tm );
					if ( ! empty( $tm[1] ) ) {
						$table_block = '<div class="gleo-table-block"><table class="gleo-data-table">' . wp_kses_post( $tm[1] ) . '</table></div>';
					} else {
						$table_block = '<div class="gleo-table-block">' . wp_kses_post( $raw ) . '</div>';
					}
				} else {
					$topic       = esc_html( $post->post_title );
					$table_block = '<div class="gleo-table-block">'
						. '<table class="gleo-data-table">'
						. '<thead><tr><th>Feature</th><th>Details</th><th>Impact</th></tr></thead>'
						. '<tbody>'
						. '<tr><td>Primary Benefit</td><td>Key advantage related to ' . $topic . '</td><td>High</td></tr>'
						. '<tr><td>Secondary Benefit</td><td>Additional value point</td><td>Medium</td></tr>'
						. '<tr><td>Consideration</td><td>Important factor to evaluate</td><td>Varies</td></tr>'
						. '</tbody></table></div>';
				}
				$content = $this->inject_after_paragraph( $content, $table_block, 2 );
				$modified = true;
				break;

			case 'authority':
				if ( ! empty( $contextual_assets['authority_html'] ) ) {
					$stats_text = wp_strip_all_tags( $contextual_assets['authority_html'] );
				} else {
					$stats_text = is_string( $user_input ) ? sanitize_textarea_field( $user_input ) : '';
					if ( empty( $stats_text ) ) {
						return new WP_Error( 'missing_input', 'Please provide statistics or data points.', array( 'status' => 400 ) );
					}
				}
				$callout = '<div class="gleo-stats-callout">'
					. '<span class="gleo-stats-icon">&#128202;</span>'
					. '<div class="gleo-stats-body">'
					. '<p class="gleo-stats-label">Did You Know</p>'
					. '<p class="gleo-stats-text">' . esc_html( $stats_text ) . '</p>'
					. '</div></div>';
				$content = $this->inject_after_paragraph( $content, $callout, 1 );
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

			case 'answer_readiness':
				if ( ! empty( $contextual_assets['qa_html'] ) ) {
					preg_match_all( '/<strong>(.*?)<\/strong>\s*<\/p>\s*<p>(.*?)<\/p>/si', $contextual_assets['qa_html'], $qm );
					if ( ! empty( $qm[1] ) ) {
						$items = '';
						foreach ( $qm[1] as $idx => $q ) {
							$q_safe = esc_html( wp_strip_all_tags( $q ) );
							$a_safe = esc_html( wp_strip_all_tags( $qm[2][ $idx ] ) );
							$items .= '<div class="gleo-qa-item"><p class="gleo-qa-q">' . $q_safe . '</p><p class="gleo-qa-a">' . $a_safe . '</p></div>';
						}
						$qa_block = '<div class="gleo-qa-block"><p class="gleo-qa-title">Quick Answers</p>' . $items . '</div>';
					} else {
						$qa_block = wp_kses_post( $contextual_assets['qa_html'] );
					}
				} else {
					$topic    = esc_html( $post->post_title );
					$qa_block = '<div class="gleo-qa-block"><p class="gleo-qa-title">Quick Answers</p>'
						. '<div class="gleo-qa-item"><p class="gleo-qa-q">What is ' . $topic . '?</p>'
						. '<p class="gleo-qa-a">' . $topic . ' encompasses the key principles and practices discussed throughout this article.</p></div>'
						. '<div class="gleo-qa-item"><p class="gleo-qa-q">Why does ' . $topic . ' matter?</p>'
						. '<p class="gleo-qa-a">Understanding ' . $topic . ' directly impacts outcomes in this area. Experts recommend staying informed and applying best practices.</p></div>'
						. '</div>';
				}
				$content = $this->inject_after_paragraph( $content, $qa_block, 1 );
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

		return rest_ensure_response( array(
			'success'  => true,
			'post_id'  => $post_id,
			'type'     => $type,
			'modified' => $modified,
		) );
	}
}

new Gleo_Frontend();
