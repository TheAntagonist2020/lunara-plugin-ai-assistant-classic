<?php
/**
 * Plugin Name:       LUNARA AI Assistant Classic
 * Description:       Private LUNARA editorial assistant for the Classic Editor, including Journal and Review post types.
 * Version:           0.6.0
 * Author:            LUNARA FILM
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Text Domain:       lunara-ai-assistant-classic
 *
 * @package LunaraAIAssistantClassic
 */

defined( 'ABSPATH' ) || exit;

define( 'LUNARA_AI_ASSISTANT_CLASSIC_VERSION', '0.6.0' );
define( 'LUNARA_AI_ASSISTANT_CLASSIC_FILE', __FILE__ );
define( 'LUNARA_AI_ASSISTANT_CLASSIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'LUNARA_AI_ASSISTANT_CLASSIC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Classic Editor editorial assistant.
 */
final class Lunara_AI_Assistant_Classic {
	const OPTION_KEY       = 'lunara_ai_assistant_classic_settings';
	const REST_NAMESPACE   = 'lunara-ai-classic/v1';
	const SUGGESTION_META  = '_lunara_ai_suggestion_snapshots';
	const SUGGESTION_LIMIT = 5;

	/**
	 * Boot plugin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'provider'          => 'auto',
			'api_key'           => '',
			'model'             => 'gpt-4o',
			'anthropic_api_key' => '',
			'anthropic_model'   => 'claude-sonnet-5',
			'gemini_api_key'    => '',
			'gemini_model'      => 'gemini-2.0-flash',
			'post_types'        => 'post,journal,review',
			'system_prompt'     => self::default_system_prompt(),
		);
	}

	/**
	 * Get settings with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Get configured OpenAI API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return self::get_provider_api_key( 'openai' );
	}

	/**
	 * Get configured provider API key.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	private static function get_provider_api_key( $provider ) {
		$settings = self::get_settings();

		if ( 'openai' === $provider ) {
			if ( defined( 'LUNARA_OPENAI_API_KEY' ) && LUNARA_OPENAI_API_KEY ) {
				return trim( (string) LUNARA_OPENAI_API_KEY );
			}

			$env_key = getenv( 'OPENAI_API_KEY' );
			if ( $env_key ) {
				return trim( (string) $env_key );
			}

			return isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
		}

		if ( 'anthropic' === $provider ) {
			if ( defined( 'LUNARA_ANTHROPIC_API_KEY' ) && LUNARA_ANTHROPIC_API_KEY ) {
				return trim( (string) LUNARA_ANTHROPIC_API_KEY );
			}

			$env_key = getenv( 'ANTHROPIC_API_KEY' );
			if ( $env_key ) {
				return trim( (string) $env_key );
			}

			return isset( $settings['anthropic_api_key'] ) ? trim( (string) $settings['anthropic_api_key'] ) : '';
		}

		if ( defined( 'LUNARA_GEMINI_API_KEY' ) && LUNARA_GEMINI_API_KEY ) {
			return trim( (string) LUNARA_GEMINI_API_KEY );
		}

		$env_key = getenv( 'GEMINI_API_KEY' );
		if ( $env_key ) {
			return trim( (string) $env_key );
		}

		$google_key = getenv( 'GOOGLE_API_KEY' );
		if ( $google_key ) {
			return trim( (string) $google_key );
		}

		return isset( $settings['gemini_api_key'] ) ? trim( (string) $settings['gemini_api_key'] ) : '';
	}

	/**
	 * Get provider model.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	private static function get_provider_model( $provider ) {
		$settings = self::get_settings();

		if ( 'anthropic' === $provider ) {
			return trim( (string) $settings['anthropic_model'] );
		}

		if ( 'gemini' === $provider ) {
			return trim( (string) $settings['gemini_model'] );
		}

		return trim( (string) $settings['model'] );
	}

	/**
	 * Supported providers.
	 *
	 * @return array
	 */
	private static function providers() {
		return array( 'openai', 'anthropic', 'gemini' );
	}

	/**
	 * Whether the provider is explicitly pinned (not "Automatic").
	 *
	 * @return bool
	 */
	private static function provider_is_pinned() {
		$settings = self::get_settings();
		$choice   = isset( $settings['provider'] ) ? sanitize_key( (string) $settings['provider'] ) : 'auto';
		return in_array( $choice, self::providers(), true );
	}

	/**
	 * Resolve the provider that should handle a request.
	 *
	 * An explicit choice in Settings drives every request, so a single key is
	 * enough to run the assistant. "Automatic" picks the first provider that has
	 * a key configured, preferring Claude.
	 *
	 * @return string openai|anthropic|gemini
	 */
	private static function get_active_provider() {
		if ( self::provider_is_pinned() ) {
			$settings = self::get_settings();
			return sanitize_key( (string) $settings['provider'] );
		}

		foreach ( array( 'anthropic', 'openai', 'gemini' ) as $candidate ) {
			if ( '' !== self::get_provider_api_key( $candidate ) ) {
				return $candidate;
			}
		}

		return 'anthropic';
	}

	/**
	 * Human-readable provider label for UI and error messages.
	 *
	 * @param string $provider Provider key.
	 * @return string
	 */
	private static function provider_label( $provider ) {
		$labels = array(
			'openai'    => __( 'OpenAI', 'lunara-ai-assistant-classic' ),
			'anthropic' => __( 'Anthropic (Claude)', 'lunara-ai-assistant-classic' ),
			'gemini'    => __( 'Google Gemini', 'lunara-ai-assistant-classic' ),
		);
		return isset( $labels[ $provider ] ) ? $labels[ $provider ] : ucfirst( (string) $provider );
	}

	/**
	 * Default LUNARA editorial prompt.
	 *
	 * @return string
	 */
	public static function default_system_prompt() {
		return "You are the LUNARA editorial assistant inside Dalton Johnson's WordPress Classic Editor. Your primary job is to help Dalton revise, package, and sharpen his own writing. You may generate editorial furniture: titles, deks, subheaders, H2 options, pull quotes, capsule lines, social copy, and packaging audits from Dalton's draft or notes. When Dalton explicitly asks for a rewrite, or uses rewrite mode, you may completely rewrite the referenced passage.\n\nLUNARA rules:\n- Preserve Dalton's argument. Do not replace his authorship.\n- In rewrite mode, fully rewrite the referenced text if asked. You may change structure, rhythm, phrasing, transitions, and sentence order, but keep Dalton's meaning, factual claims, and critical position intact.\n- Support both Reviews and Journal entries. Journal work can be shorter, looser, and more process-aware, but it must still have a clear editorial reason to exist.\n- Titles should be sharp, film-specific, and printable. Avoid generic SEO sludge.\n- Deks should be one sentence when possible, italic-ready, carrying thesis, temperature, and stakes.\n- H2 subheaders must be in-world, load-bearing lines pulled from the piece's argument, images, locations, gestures, or moral transaction. Never use generic labels like The Performances, The Turn, Final Thoughts, Themes, Cinematography, or Conclusion.\n- Pull quotes must be memorable, self-contained, and accurate to the draft. Separate exact lifts from sharpened rewrites.\n- No critic names, Rotten Tomatoes, box office, or vague consensus padding.\n- Avoid these phrases: In a world where, It's a testament to, A masterclass in, The film explores, The viewer is left to, We are forced to confront, What lingers is.\n- Prefer plain force over ornate fog. The line should cut.\n- ASCII punctuation only. Use straight quotes and -- for em dashes.\n\nWhen the user asks for options, return grouped options with brief labels. When generating for insertion, keep the output clean enough to paste into WordPress. In rewrite mode, return the rewritten passage first with no preamble.";
	}

	/**
	 * Register settings page.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'LUNARA AI Assistant Classic', 'lunara-ai-assistant-classic' ),
			__( 'LUNARA AI Classic', 'lunara-ai-assistant-classic' ),
			'manage_options',
			'lunara-ai-assistant-classic',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting(
			'lunara_ai_assistant_classic_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Preserve stored secrets unless a replacement or clear checkbox is submitted.
	 *
	 * @param array  $input Raw settings.
	 * @param string $key Secret setting key.
	 * @param string $clear_key Clear flag key.
	 * @param array  $existing Existing settings.
	 * @return string
	 */
	private static function sanitize_secret_setting( $input, $key, $clear_key, $existing ) {
		if ( ! empty( $input[ $clear_key ] ) ) {
			return '';
		}

		if ( isset( $input[ $key ] ) ) {
			$value = trim( (string) wp_unslash( $input[ $key ] ) );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
		}

		return isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$existing = self::get_settings();
		$input    = is_array( $input ) ? $input : array();

		$provider = isset( $input['provider'] ) ? sanitize_key( wp_unslash( $input['provider'] ) ) : $defaults['provider'];
		if ( ! in_array( $provider, array( 'auto', 'openai', 'anthropic', 'gemini' ), true ) ) {
			$provider = $defaults['provider'];
		}

		return array(
			'provider'          => $provider,
			'api_key'           => self::sanitize_secret_setting( $input, 'api_key', 'clear_api_key', $existing ),
			'model'             => isset( $input['model'] ) ? sanitize_text_field( wp_unslash( $input['model'] ) ) : $defaults['model'],
			'anthropic_api_key' => self::sanitize_secret_setting( $input, 'anthropic_api_key', 'clear_anthropic_api_key', $existing ),
			'anthropic_model'   => isset( $input['anthropic_model'] ) ? sanitize_text_field( wp_unslash( $input['anthropic_model'] ) ) : $defaults['anthropic_model'],
			'gemini_api_key'    => self::sanitize_secret_setting( $input, 'gemini_api_key', 'clear_gemini_api_key', $existing ),
			'gemini_model'      => isset( $input['gemini_model'] ) ? sanitize_text_field( wp_unslash( $input['gemini_model'] ) ) : $defaults['gemini_model'],
			'post_types'        => isset( $input['post_types'] ) ? sanitize_text_field( wp_unslash( $input['post_types'] ) ) : $defaults['post_types'],
			'system_prompt'     => isset( $input['system_prompt'] ) ? sanitize_textarea_field( wp_unslash( $input['system_prompt'] ) ) : $defaults['system_prompt'],
		);
	}

	/**
	 * Render one secret setting row.
	 *
	 * @param string $field Field key.
	 * @param string $clear_field Clear field key.
	 * @param string $label Label.
	 * @param string $description Description.
	 * @param bool   $has_value Whether a fallback is saved.
	 */
	private static function render_secret_row( $field, $clear_field, $label, $description, $has_value ) {
		$field_id = 'lunara-classic-' . str_replace( '_', '-', $field );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="<?php echo esc_attr( $field_id ); ?>" type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $field ); ?>]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $has_value ? __( 'Stored fallback key saved', 'lunara-ai-assistant-classic' ) : __( 'Optional fallback key', 'lunara-ai-assistant-classic' ) ); ?>" />
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php if ( $has_value ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $clear_field ); ?>]" value="1" /> <?php esc_html_e( 'Clear stored fallback key on save', 'lunara-ai-assistant-classic' ); ?></label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LUNARA AI Assistant Classic', 'lunara-ai-assistant-classic' ); ?></h1>
			<p><?php esc_html_e( 'Powers the LUNARA AI meta box and the private Control Desk suggestion service.', 'lunara-ai-assistant-classic' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'lunara_ai_assistant_classic_group' ); ?>
				<table class="form-table" role="presentation">
					<?php $active_provider = self::get_active_provider(); ?>
					<tr>
						<th scope="row"><label for="lunara-classic-provider"><?php esc_html_e( 'AI provider', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td>
							<select id="lunara-classic-provider" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[provider]">
								<option value="auto" <?php selected( $settings['provider'], 'auto' ); ?>><?php esc_html_e( 'Automatic (use whichever key is set — Claude preferred)', 'lunara-ai-assistant-classic' ); ?></option>
								<option value="anthropic" <?php selected( $settings['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic (Claude)', 'lunara-ai-assistant-classic' ); ?></option>
								<option value="openai" <?php selected( $settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'lunara-ai-assistant-classic' ); ?></option>
								<option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'lunara-ai-assistant-classic' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Pick which service powers the assistant. Choosing one provider routes every task to it, so a single API key is enough. "Automatic" uses smart per-task routing when several keys are set and otherwise falls back to whichever key you have.', 'lunara-ai-assistant-classic' ); ?>
								<br />
								<strong><?php echo esc_html( sprintf( /* translators: %s: provider name */ __( 'Currently active: %s', 'lunara-ai-assistant-classic' ), self::provider_label( $active_provider ) ) ); ?></strong>
								<?php if ( '' === self::get_provider_api_key( $active_provider ) ) : ?>
									<span style="color:#b32d2e;"><?php esc_html_e( '— no API key set for this provider yet.', 'lunara-ai-assistant-classic' ); ?></span>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<?php
					self::render_secret_row(
						'api_key',
						'clear_api_key',
						__( 'OpenAI API key', 'lunara-ai-assistant-classic' ),
						__( 'Best practice: define LUNARA_OPENAI_API_KEY or OPENAI_API_KEY on the server. This field is a private fallback and is never printed back.', 'lunara-ai-assistant-classic' ),
						! empty( $settings['api_key'] )
					);
					?>
					<tr>
						<th scope="row"><label for="lunara-classic-model"><?php esc_html_e( 'OpenAI model', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td><input id="lunara-classic-model" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" class="regular-text" /></td>
					</tr>
					<?php
					self::render_secret_row(
						'anthropic_api_key',
						'clear_anthropic_api_key',
						__( 'Anthropic API key', 'lunara-ai-assistant-classic' ),
						__( 'Best practice: define LUNARA_ANTHROPIC_API_KEY or ANTHROPIC_API_KEY on the server. Used for voice, taste, and rewrite critique suggestions.', 'lunara-ai-assistant-classic' ),
						! empty( $settings['anthropic_api_key'] )
					);
					?>
					<tr>
						<th scope="row"><label for="lunara-classic-anthropic-model"><?php esc_html_e( 'Anthropic model', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td><input id="lunara-classic-anthropic-model" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[anthropic_model]" value="<?php echo esc_attr( $settings['anthropic_model'] ); ?>" class="regular-text" /></td>
					</tr>
					<?php
					self::render_secret_row(
						'gemini_api_key',
						'clear_gemini_api_key',
						__( 'Gemini API key', 'lunara-ai-assistant-classic' ),
						__( 'Best practice: define LUNARA_GEMINI_API_KEY, GEMINI_API_KEY, or GOOGLE_API_KEY on the server. Used for context and ledger suggestion passes.', 'lunara-ai-assistant-classic' ),
						! empty( $settings['gemini_api_key'] )
					);
					?>
					<tr>
						<th scope="row"><label for="lunara-classic-gemini-model"><?php esc_html_e( 'Gemini model', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td><input id="lunara-classic-gemini-model" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[gemini_model]" value="<?php echo esc_attr( $settings['gemini_model'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lunara-classic-post-types"><?php esc_html_e( 'Post types', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td>
							<input id="lunara-classic-post-types" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_types]" value="<?php echo esc_attr( $settings['post_types'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated. Defaults to post,journal,review.', 'lunara-ai-assistant-classic' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lunara-classic-system-prompt"><?php esc_html_e( 'LUNARA system prompt', 'lunara-ai-assistant-classic' ); ?></label></th>
						<td><textarea id="lunara-classic-system-prompt" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[system_prompt]" rows="16" class="large-text code"><?php echo esc_textarea( $settings['system_prompt'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get enabled post types that exist and support editor UI.
	 *
	 * @return array
	 */
	public static function get_enabled_post_types() {
		$settings = self::get_settings();
		$raw      = array_filter( array_map( 'trim', explode( ',', (string) $settings['post_types'] ) ) );
		$raw      = array_map( 'sanitize_key', $raw );
		$raw      = array_unique( $raw );
		$enabled  = array();

		foreach ( $raw as $post_type ) {
			if ( post_type_exists( $post_type ) && post_type_supports( $post_type, 'editor' ) ) {
				$enabled[] = $post_type;
			}
		}

		return apply_filters( 'lunara_ai_assistant_classic_post_types', $enabled );
	}

	/**
	 * Register Classic Editor meta box.
	 */
	public static function register_meta_boxes() {
		foreach ( self::get_enabled_post_types() as $post_type ) {
			add_meta_box(
				'lunara-ai-assistant-classic',
				__( 'LUNARA AI', 'lunara-ai-assistant-classic' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render Classic Editor meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		?>
		<div id="lunara-ai-classic-root" class="lunara-ai-classic-root" data-post-type="<?php echo esc_attr( $post->post_type ); ?>">
			<p class="description"><?php esc_html_e( 'Generate LUNARA titles, deks, H2s, pull quotes, and packaging notes from this draft.', 'lunara-ai-assistant-classic' ); ?></p>

			<label for="lunara-ai-classic-mode"><?php esc_html_e( 'Mode', 'lunara-ai-assistant-classic' ); ?></label>
			<select id="lunara-ai-classic-mode" class="widefat">
				<option value="package"><?php esc_html_e( 'Full Editorial Package', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="titles"><?php esc_html_e( 'Titles', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="dek"><?php esc_html_e( 'Deks', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="h2"><?php esc_html_e( 'Subheaders / H2s', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="quotes"><?php esc_html_e( 'Pull Quotes', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="rewrite"><?php esc_html_e( 'Rewrite Referenced Text', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="journal"><?php esc_html_e( 'Journal Packaging', 'lunara-ai-assistant-classic' ); ?></option>
				<option value="audit"><?php esc_html_e( 'Audit Current Packaging', 'lunara-ai-assistant-classic' ); ?></option>
			</select>

			<label for="lunara-ai-classic-film-title"><?php esc_html_e( 'Film title', 'lunara-ai-assistant-classic' ); ?></label>
			<input id="lunara-ai-classic-film-title" type="text" class="widefat" placeholder="<?php esc_attr_e( 'Optional', 'lunara-ai-assistant-classic' ); ?>" />

			<label for="lunara-ai-classic-film-year"><?php esc_html_e( 'Year', 'lunara-ai-assistant-classic' ); ?></label>
			<input id="lunara-ai-classic-film-year" type="text" class="widefat" placeholder="<?php esc_attr_e( 'Optional', 'lunara-ai-assistant-classic' ); ?>" />

			<label for="lunara-ai-classic-notes"><?php esc_html_e( 'Specific ask / notes', 'lunara-ai-assistant-classic' ); ?></label>
			<textarea id="lunara-ai-classic-notes" rows="5" class="widefat" placeholder="<?php esc_attr_e( 'Example: colder H2s, less poetic; pull quotes should sell the performance angle.', 'lunara-ai-assistant-classic' ); ?>"></textarea>

			<label for="lunara-ai-classic-reference"><?php esc_html_e( 'Referenced text to rewrite', 'lunara-ai-assistant-classic' ); ?></label>
			<textarea id="lunara-ai-classic-reference" rows="7" class="widefat" placeholder="<?php esc_attr_e( 'Paste the passage you want rewritten, or highlight text in the editor and use selected text.', 'lunara-ai-assistant-classic' ); ?>"></textarea>
			<button type="button" class="button lunara-ai-classic-use-selection"><?php esc_html_e( 'Use Selected Text', 'lunara-ai-assistant-classic' ); ?></button>

			<button type="button" class="button button-primary lunara-ai-classic-generate"><?php esc_html_e( 'Generate', 'lunara-ai-assistant-classic' ); ?></button>
			<div class="lunara-ai-classic-status" role="status" aria-live="polite"></div>
			<div class="lunara-ai-classic-result-wrap" hidden>
				<label for="lunara-ai-classic-line"><?php esc_html_e( 'Line to apply', 'lunara-ai-assistant-classic' ); ?></label>
				<input id="lunara-ai-classic-line" type="text" class="widefat" placeholder="<?php esc_attr_e( 'Paste one generated line here, or leave blank for first line.', 'lunara-ai-assistant-classic' ); ?>" />
				<div class="lunara-ai-classic-actions">
					<button type="button" class="button" data-lunara-apply="title"><?php esc_html_e( 'Use as Title', 'lunara-ai-assistant-classic' ); ?></button>
					<button type="button" class="button" data-lunara-apply="excerpt"><?php esc_html_e( 'Set Dek/Excerpt', 'lunara-ai-assistant-classic' ); ?></button>
					<button type="button" class="button" data-lunara-apply="h2"><?php esc_html_e( 'Insert H2', 'lunara-ai-assistant-classic' ); ?></button>
					<button type="button" class="button" data-lunara-apply="quote"><?php esc_html_e( 'Insert Pull Quote', 'lunara-ai-assistant-classic' ); ?></button>
					<button type="button" class="button" data-lunara-apply="replace"><?php esc_html_e( 'Replace Selection', 'lunara-ai-assistant-classic' ); ?></button>
					<button type="button" class="button" data-lunara-apply="full"><?php esc_html_e( 'Insert Full Result', 'lunara-ai-assistant-classic' ); ?></button>
				</div>
				<pre class="lunara-ai-classic-result"></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue Classic Editor assets.
	 *
	 * @param string $hook Admin hook.
	 */
	public static function enqueue_editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::get_enabled_post_types(), true ) ) {
			return;
		}

		$js_path = LUNARA_AI_ASSISTANT_CLASSIC_PATH . 'assets/classic-editor.js';
		wp_enqueue_script(
			'lunara-ai-assistant-classic',
			LUNARA_AI_ASSISTANT_CLASSIC_URL . 'assets/classic-editor.js',
			array(),
			file_exists( $js_path ) ? filemtime( $js_path ) : LUNARA_AI_ASSISTANT_CLASSIC_VERSION,
			true
		);

		wp_localize_script(
			'lunara-ai-assistant-classic',
			'LunaraAIClassic',
			array(
				'restUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/generate' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'working'   => __( 'Working from the open draft...', 'lunara-ai-assistant-classic' ),
					'ready'     => __( 'Ready.', 'lunara-ai-assistant-classic' ),
					'failed'    => __( 'Request failed.', 'lunara-ai-assistant-classic' ),
					'noResult'  => __( 'Generate a result first.', 'lunara-ai-assistant-classic' ),
					'noExcerpt' => __( 'Excerpt box not found. Enable Excerpt in Screen Options if needed.', 'lunara-ai-assistant-classic' ),
				),
			)
		);

		$css_path = LUNARA_AI_ASSISTANT_CLASSIC_PATH . 'assets/classic-editor.css';
		wp_enqueue_style(
			'lunara-ai-assistant-classic',
			LUNARA_AI_ASSISTANT_CLASSIC_URL . 'assets/classic-editor.css',
			array(),
			file_exists( $css_path ) ? filemtime( $css_path ) : LUNARA_AI_ASSISTANT_CLASSIC_VERSION
		);
	}

	/**
	 * Register REST endpoints.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_generate' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'mode'          => array( 'type' => 'string', 'required' => true ),
					'postType'      => array( 'type' => 'string', 'required' => false ),
					'filmTitle'     => array( 'type' => 'string', 'required' => false ),
					'filmYear'      => array( 'type' => 'string', 'required' => false ),
					'postTitle'     => array( 'type' => 'string', 'required' => false ),
					'postContent'   => array( 'type' => 'string', 'required' => false ),
					'referenceText' => array( 'type' => 'string', 'required' => false ),
					'notes'         => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/suggest',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_suggest' ),
				'permission_callback' => array( __CLASS__, 'can_access_post_suggestions' ),
				'args'                => array(
					'postId' => array( 'type' => 'integer', 'required' => true ),
					'intent' => array( 'type' => 'string', 'required' => true ),
					'note'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_suggestions' ),
				'permission_callback' => array( __CLASS__, 'can_access_post_suggestions' ),
				'args'                => array(
					'postId' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	/**
	 * Check post-specific suggestion access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_access_post_suggestions( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'postId' ) );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Handle generation request for the existing Classic Editor box.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_generate( WP_REST_Request $request ) {
		$provider = self::get_active_provider();
		$api_key  = self::get_provider_api_key( $provider );
		if ( ! $api_key ) {
			return new WP_Error( 'lunara_missing_key', sprintf( /* translators: %s: provider name */ __( '%s API key is missing. Add it under Settings > LUNARA AI Classic.', 'lunara-ai-assistant-classic' ), self::provider_label( $provider ) ), array( 'status' => 400 ) );
		}

		$model = self::get_provider_model( $provider );
		if ( ! $model ) {
			return new WP_Error( 'lunara_missing_provider_model', sprintf( /* translators: %s: provider name */ __( '%s model is missing. Set it under Settings > LUNARA AI Classic.', 'lunara-ai-assistant-classic' ), self::provider_label( $provider ) ), array( 'status' => 400 ) );
		}

		$mode           = sanitize_key( $request->get_param( 'mode' ) );
		$post_type      = sanitize_key( $request->get_param( 'postType' ) );
		$film_title     = sanitize_text_field( (string) $request->get_param( 'filmTitle' ) );
		$film_year      = sanitize_text_field( (string) $request->get_param( 'filmYear' ) );
		$post_title     = sanitize_text_field( (string) $request->get_param( 'postTitle' ) );
		$post_content   = wp_strip_all_tags( (string) $request->get_param( 'postContent' ) );
		$reference_text = wp_strip_all_tags( (string) $request->get_param( 'referenceText' ) );
		$notes          = wp_strip_all_tags( (string) $request->get_param( 'notes' ) );
		$user_prompt    = self::build_user_prompt( $mode, $post_type, $film_title, $film_year, $post_title, $post_content, $reference_text, $notes );
		$result         = self::request_provider_text( $provider, $model, $api_key, self::get_settings()['system_prompt'], $user_prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'text' => $result ) );
	}

	/**
	 * Handle Control Desk suggestion request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_suggest( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'postId' ) );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'lunara_missing_post', __( 'Post not found.', 'lunara-ai-assistant-classic' ), array( 'status' => 404 ) );
		}

		$intent = sanitize_key( $request->get_param( 'intent' ) );
		if ( ! in_array( $intent, self::suggestion_intents(), true ) ) {
			return new WP_Error( 'lunara_bad_intent', __( 'Unsupported suggestion intent.', 'lunara-ai-assistant-classic' ), array( 'status' => 400 ) );
		}

		$provider = self::provider_for_intent( $intent );
		$api_key  = self::get_provider_api_key( $provider );
		$model    = self::get_provider_model( $provider );

		if ( ! $api_key ) {
			return new WP_Error( 'lunara_missing_provider_key', sprintf( __( '%s API key is missing.', 'lunara-ai-assistant-classic' ), ucfirst( $provider ) ), array( 'status' => 400 ) );
		}

		if ( ! $model ) {
			return new WP_Error( 'lunara_missing_provider_model', sprintf( __( '%s model is missing.', 'lunara-ai-assistant-classic' ), ucfirst( $provider ) ), array( 'status' => 400 ) );
		}

		$note    = wp_strip_all_tags( (string) $request->get_param( 'note' ) );
		$prompts = self::build_suggestion_prompts( $intent, $post, $note );
		$text    = self::request_provider_text( $provider, $model, $api_key, $prompts['system'], $prompts['user'] );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$snapshot = self::build_suggestion_snapshot( $post_id, $provider, $intent, $text );
		self::save_suggestion_snapshot( $post_id, $snapshot );

		return rest_ensure_response( $snapshot );
	}

	/**
	 * Get private suggestion snapshots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_get_suggestions( WP_REST_Request $request ) {
		$post_id   = absint( $request->get_param( 'postId' ) );
		$snapshots = get_post_meta( $post_id, self::SUGGESTION_META, true );

		return rest_ensure_response(
			array(
				'postId'      => $post_id,
				'suggestions' => is_array( $snapshots ) ? array_values( $snapshots ) : array(),
			)
		);
	}

	/**
	 * Supported suggestion intents.
	 *
	 * @return array
	 */
	private static function suggestion_intents() {
		return array( 'package', 'rewrite', 'readiness', 'homepage_pitch', 'ledger_links' );
	}

	/**
	 * Role-based provider routing.
	 *
	 * @param string $intent Suggestion intent.
	 * @return string
	 */
	private static function provider_for_intent( $intent ) {
		// An explicit provider choice drives every task, so one key is enough.
		if ( self::provider_is_pinned() ) {
			return self::get_active_provider();
		}

		// Automatic: prefer a per-task model, but only when its key is set;
		// otherwise fall back to whichever provider key is configured.
		$preferred = 'openai';
		if ( in_array( $intent, array( 'rewrite', 'readiness' ), true ) ) {
			$preferred = 'anthropic';
		} elseif ( 'ledger_links' === $intent ) {
			$preferred = 'gemini';
		}

		if ( '' !== self::get_provider_api_key( $preferred ) ) {
			return $preferred;
		}

		return self::get_active_provider();
	}

	/**
	 * Build mode-specific prompt.
	 *
	 * @param string $mode Mode.
	 * @param string $post_type Post type.
	 * @param string $film_title Film title.
	 * @param string $film_year Film year.
	 * @param string $post_title Current title.
	 * @param string $post_content Current content.
	 * @param string $reference_text Referenced text.
	 * @param string $notes Notes.
	 * @return string
	 */
	private static function build_user_prompt( $mode, $post_type, $film_title, $film_year, $post_title, $post_content, $reference_text, $notes ) {
		$mode_instructions = array(
			'titles'  => 'Generate 12 title options: 4 clean/critical, 4 sharper LUNARA voice, 4 SEO-aware but not bland. Include one-line reasoning for each.',
			'dek'     => 'Generate 8 italic-ready dek options. One sentence each. Each must contain thesis, temperature, and stakes.',
			'h2'      => 'Generate 12 H2 subheader options. They must be in-world, load-bearing, film-specific when applicable, and not generic labels. Group them by function: opener, body turn, verdict closer.',
			'quotes'  => 'Extract or sharpen 10 pull quotes. Separate exact lifts from sharpened rewrites. Keep them self-contained and quote-card ready.',
			'rewrite' => 'Completely rewrite the referenced text according to Dalton\'s notes. Preserve the underlying argument, facts, names, chronology, and critical position, but freely change phrasing, structure, rhythm, transitions, and sentence order. Return the rewritten passage first with no preamble. If a brief note is necessary, put it after the rewrite under "Editor note:".',
			'package' => 'Create a full editorial package: 10 titles, 6 dek lines, 10 H2 options, 10 pull quotes, 5 social post hooks, and 3 newsletter subject lines.',
			'journal' => 'Create a Journal-ready package: 8 titles, 5 dek/excerpt lines, 8 H2 options if the piece is long enough, 6 pull-quote or promo lines, 5 social hooks, and a short note on where the Journal entry should sit in the site.',
			'audit'   => 'Audit the current title, dek, H2s, and pull-quote possibilities. Name what is weak, what is generic, and what should be sharpened. Then provide replacements.',
		);

		$instruction = isset( $mode_instructions[ $mode ] ) ? $mode_instructions[ $mode ] : $mode_instructions['package'];
		$post_type   = $post_type ? $post_type : 'post';

		return "Mode: {$mode}\nPost type: {$post_type}\nInstruction: {$instruction}\n\nFilm title: {$film_title}\nFilm year: {$film_year}\nCurrent WordPress title: {$post_title}\n\nDalton's notes or specific ask:\n{$notes}\n\nReferenced text to rewrite or alter:\n{$reference_text}\n\nCurrent draft content from the Classic Editor:\n{$post_content}";
	}

	/**
	 * Build structured suggestion prompts.
	 *
	 * @param string  $intent Suggestion intent.
	 * @param WP_Post $post Post object.
	 * @param string  $note User note.
	 * @return array
	 */
	private static function build_suggestion_prompts( $intent, WP_Post $post, $note ) {
		$settings = self::get_settings();
		$context  = self::build_post_context( $post );
		$roles    = array(
			'package'        => 'Create structured packaging suggestions for title, dek, H2s, pull quotes, and social hooks.',
			'rewrite'        => 'Critique the draft voice and identify the highest-value rewrite directions without rewriting the whole post unless the excerpt clearly demands it.',
			'readiness'      => 'Assess publish readiness with blockers, warnings, and final editorial prep notes.',
			'homepage_pitch' => 'Suggest how this draft should be framed for homepage curation and reader pull.',
			'ledger_links'   => 'Find Oscar Ledger internal-link opportunities. Do not invent facts. If unsure, mark the item as needs verification.',
		);

		$system = $settings['system_prompt'] . "\n\nControl Desk suggestion mode:\nReturn only one JSON object. Do not wrap it in Markdown. Keep every suggestion suggest-only; never claim you changed WordPress. Required object keys: summary, fields. fields must include titles, deks, h2s, pullQuotes, socialHooks, homepagePitch, readinessNotes, ledgerOpportunities. Use flat arrays of short strings for every list field; do not return nested arrays or objects inside those lists. Use one string per blocker, warning, copy note, title, dek, H2, pull quote, social hook, or ledger opportunity. Prefix readinessNotes items with Blocker:, Warning:, or Copy note: when useful. Use a string for homepagePitch.";
		$user   = "Intent: {$intent}\nInstruction: " . ( isset( $roles[ $intent ] ) ? $roles[ $intent ] : $roles['package'] ) . "\n\nOptional desk note:\n{$note}\n\nCurrent post context:\n" . wp_json_encode( $context, JSON_PRETTY_PRINT );

		return array(
			'system' => $system,
			'user'   => $user,
		);
	}

	/**
	 * Build post context for suggestion prompts.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function build_post_context( WP_Post $post ) {
		return array(
			'postId'       => $post->ID,
			'postType'     => $post->post_type,
			'status'       => $post->post_status,
			'title'        => get_the_title( $post ),
			'excerpt'      => wp_strip_all_tags( $post->post_excerpt ),
			'hasThumbnail' => has_post_thumbnail( $post->ID ),
			'terms'        => self::get_post_terms_context( $post->ID ),
			'meta'         => self::get_post_meta_context( $post->ID ),
			'contentText'  => self::limit_text( wp_strip_all_tags( $post->post_content ), 16000 ),
		);
	}

	/**
	 * Get terms as context.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function get_post_terms_context( $post_id ) {
		$out = array();

		foreach ( array( 'category', 'post_tag', 'journal_type' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = array();
				continue;
			}

			$out[ $taxonomy ] = wp_list_pluck( $terms, 'name' );
		}

		return $out;
	}

	/**
	 * Get known packaging meta as context.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function get_post_meta_context( $post_id ) {
		$keys = array(
			'_lunara_review_standfirst',
			'_lunara_pull_quote',
			'_lunara_review_pull_quote',
			'_lunara_score',
			'_lunara_review_card_image',
			'_lunara_review_hero_banner',
			'_lunara_review_home_hero_featured',
			'_lunara_review_home_featured_shelf',
			'_lunara_post_standfirst',
			'_lunara_post_hero_image_url',
			'_lunara_post_hide_hero_media',
			'_lunara_journal_featured',
			'_lunara_imdb_title_id',
		);
		$out  = array();

		foreach ( $keys as $key ) {
			$value       = get_post_meta( $post_id, $key, true );
			$out[ $key ] = is_scalar( $value ) ? self::limit_text( (string) $value, 600 ) : '';
		}

		return $out;
	}

	/**
	 * Limit text without requiring mbstring.
	 *
	 * @param string $text Text.
	 * @param int    $limit Character limit.
	 * @return string
	 */
	private static function limit_text( $text, $limit ) {
		$text = trim( (string) $text );

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return substr( $text, 0, $limit ) . "\n[trimmed]";
	}

	/**
	 * Request text from the selected provider.
	 *
	 * @param string $provider Provider key.
	 * @param string $model Model.
	 * @param string $api_key API key.
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt User prompt.
	 * @return string|WP_Error
	 */
	private static function request_provider_text( $provider, $model, $api_key, $system_prompt, $user_prompt ) {
		if ( 'anthropic' === $provider ) {
			return self::request_anthropic_text( $model, $api_key, $system_prompt, $user_prompt );
		}

		if ( 'gemini' === $provider ) {
			return self::request_gemini_text( $model, $api_key, $system_prompt, $user_prompt );
		}

		return self::request_openai_text( $model, $api_key, $system_prompt, $user_prompt );
	}

	/**
	 * Request OpenAI Responses API text.
	 *
	 * @param string $model Model.
	 * @param string $api_key API key.
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt User prompt.
	 * @return string|WP_Error
	 */
	private static function request_openai_text( $model, $api_key, $system_prompt, $user_prompt ) {
		$body = array(
			'model' => $model,
			'input' => array(
				array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $system_prompt,
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $user_prompt,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lunara_openai_request_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 > $status || 300 <= $status ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'OpenAI request failed.', 'lunara-ai-assistant-classic' );
			return new WP_Error( 'lunara_openai_error', sanitize_text_field( $message ), array( 'status' => $status ) );
		}

		return self::extract_response_text( is_array( $json ) ? $json : array() );
	}

	/**
	 * Request Anthropic Messages API text.
	 *
	 * @param string $model Model.
	 * @param string $api_key API key.
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt User prompt.
	 * @return string|WP_Error
	 */
	private static function request_anthropic_text( $model, $api_key, $system_prompt, $user_prompt ) {
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => 4096,
						'system'     => $system_prompt,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $user_prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lunara_anthropic_request_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 > $status || 300 <= $status ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'Anthropic request failed.', 'lunara-ai-assistant-classic' );
			return new WP_Error( 'lunara_anthropic_error', sanitize_text_field( $message ), array( 'status' => $status ) );
		}

		return self::extract_anthropic_text( is_array( $json ) ? $json : array() );
	}

	/**
	 * Request Gemini Generate Content API text.
	 *
	 * @param string $model Model.
	 * @param string $api_key API key.
	 * @param string $system_prompt System prompt.
	 * @param string $user_prompt User prompt.
	 * @return string|WP_Error
	 */
	private static function request_gemini_text( $model, $api_key, $system_prompt, $user_prompt ) {
		$model = preg_replace( '#^models/#', '', trim( $model ) );
		$url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'system_instruction' => array(
							'parts' => array(
								array( 'text' => $system_prompt ),
							),
						),
						'contents'           => array(
							array(
								'parts' => array(
									array( 'text' => $user_prompt ),
								),
							),
						),
						'generationConfig'   => array(
							'responseMimeType' => 'application/json',
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'lunara_gemini_request_failed', $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 > $status || 300 <= $status ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'Gemini request failed.', 'lunara-ai-assistant-classic' );
			return new WP_Error( 'lunara_gemini_error', sanitize_text_field( $message ), array( 'status' => $status ) );
		}

		return self::extract_gemini_text( is_array( $json ) ? $json : array() );
	}

	/**
	 * Build a normalized suggestion snapshot from provider text.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $provider Provider.
	 * @param string $intent Intent.
	 * @param string $raw_text Provider text.
	 * @return array
	 */
	private static function build_suggestion_snapshot( $post_id, $provider, $intent, $raw_text ) {
		$decoded = self::decode_json_from_text( $raw_text );
		$fields  = isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ? $decoded['fields'] : $decoded;
		$summary = isset( $decoded['summary'] ) && is_string( $decoded['summary'] ) ? $decoded['summary'] : self::first_text_line( $raw_text );

		return array(
			'suggestionId'        => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lunara_', true ),
			'postId'              => absint( $post_id ),
			'provider'            => sanitize_key( $provider ),
			'intent'              => sanitize_key( $intent ),
			'createdAt'           => gmdate( 'c' ),
			'summary'             => sanitize_text_field( $summary ),
			'fields'              => self::normalize_suggestion_fields( is_array( $fields ) ? $fields : array() ),
			'rawText'             => wp_strip_all_tags( (string) $raw_text ),
			'publicContentChange' => false,
		);
	}

	/**
	 * Save capped private suggestion snapshots.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $snapshot Snapshot.
	 */
	private static function save_suggestion_snapshot( $post_id, $snapshot ) {
		$current = get_post_meta( $post_id, self::SUGGESTION_META, true );
		$current = is_array( $current ) ? $current : array();

		array_unshift( $current, $snapshot );
		$current = array_slice( $current, 0, self::SUGGESTION_LIMIT );

		update_post_meta( $post_id, self::SUGGESTION_META, $current );
	}

	/**
	 * Normalize structured suggestion fields.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function normalize_suggestion_fields( $fields ) {
		return array(
			'titles'              => self::sanitize_text_list( isset( $fields['titles'] ) ? $fields['titles'] : array() ),
			'deks'                => self::sanitize_text_list( isset( $fields['deks'] ) ? $fields['deks'] : array() ),
			'h2s'                 => self::sanitize_text_list( isset( $fields['h2s'] ) ? $fields['h2s'] : array() ),
			'pullQuotes'          => self::sanitize_text_list( isset( $fields['pullQuotes'] ) ? $fields['pullQuotes'] : array() ),
			'socialHooks'         => self::sanitize_text_list( isset( $fields['socialHooks'] ) ? $fields['socialHooks'] : array() ),
			'homepagePitch'       => isset( $fields['homepagePitch'] ) ? sanitize_textarea_field( (string) $fields['homepagePitch'] ) : '',
			'readinessNotes'      => self::sanitize_text_list( isset( $fields['readinessNotes'] ) ? $fields['readinessNotes'] : array() ),
			'ledgerOpportunities' => self::sanitize_text_list( isset( $fields['ledgerOpportunities'] ) ? $fields['ledgerOpportunities'] : array() ),
		);
	}

	/**
	 * Sanitize an array-ish list of text values.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function sanitize_text_list( $value ) {
		if ( ! is_array( $value ) ) {
			$value = '' !== trim( (string) $value ) ? array( $value ) : array();
		}

		$out = array();
		foreach ( self::flatten_suggestion_items( $value ) as $item ) {
			$item = sanitize_text_field( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}

			if ( count( $out ) >= 14 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Flatten list-like and object-like suggestion values into readable lines.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function flatten_suggestion_items( $value ) {
		if ( ! is_array( $value ) ) {
			$value = trim( (string) $value );
			return '' !== $value ? array( $value ) : array();
		}

		if ( self::is_assoc_array( $value ) ) {
			$line = self::format_suggestion_object( $value );
			return '' !== $line ? array( $line ) : array();
		}

		$out = array();
		foreach ( $value as $item ) {
			$out = array_merge( $out, self::flatten_suggestion_items( $item ) );
		}

		return $out;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_assoc_array( $value ) {
		if ( array() === $value ) {
			return false;
		}

		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * Format a structured suggestion object as readable copy.
	 *
	 * @param array $item Suggestion object.
	 * @return string
	 */
	private static function format_suggestion_object( $item ) {
		$label_keys = array( 'label', 'type', 'category', 'field' );
		$text_keys  = array( 'text', 'title', 'dek', 'line', 'note', 'why', 'reason', 'url', 'target' );
		$label      = '';
		$text       = '';
		$parts      = array();

		foreach ( $label_keys as $key ) {
			if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && '' !== trim( (string) $item[ $key ] ) ) {
				$label = trim( (string) $item[ $key ] );
				break;
			}
		}

		foreach ( $text_keys as $key ) {
			if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && '' !== trim( (string) $item[ $key ] ) ) {
				if ( '' === $text ) {
					$text = trim( (string) $item[ $key ] );
				} elseif ( ! in_array( trim( (string) $item[ $key ] ), array( $label, $text ), true ) ) {
					$parts[] = trim( (string) $item[ $key ] );
				}
			}
		}

		if ( '' !== $label && '' !== $text ) {
			array_unshift( $parts, $label . ': ' . $text );
		} elseif ( '' !== $text ) {
			array_unshift( $parts, $text );
		} elseif ( '' !== $label ) {
			array_unshift( $parts, $label );
		}

		if ( empty( $parts ) ) {
			foreach ( $item as $key => $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$parts[] = trim( (string) $key ) . ': ' . trim( (string) $value );
				}
			}
		}

		return trim( implode( ' | ', array_unique( $parts ) ) );
	}

	/**
	 * Decode a JSON object that may be surrounded by prose.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function decode_json_from_text( $text ) {
		$decoded = json_decode( trim( (string) $text ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Get first useful line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function first_text_line( $text ) {
		$lines = preg_split( '/\R+/', (string) $text );

		foreach ( $lines as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				return self::limit_text( $line, 240 );
			}
		}

		return __( 'Suggestion generated.', 'lunara-ai-assistant-classic' );
	}

	/**
	 * Extract text from Responses API response.
	 *
	 * @param array $json Decoded JSON.
	 * @return string
	 */
	private static function extract_response_text( $json ) {
		if ( isset( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
			return trim( $json['output_text'] );
		}

		if ( ! empty( $json['output'] ) && is_array( $json['output'] ) ) {
			$chunks = array();
			foreach ( $json['output'] as $item ) {
				if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $content ) {
					if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
						$chunks[] = $content['text'];
					}
				}
			}

			if ( $chunks ) {
				return trim( implode( "\n", $chunks ) );
			}
		}

		return __( 'No text returned.', 'lunara-ai-assistant-classic' );
	}

	/**
	 * Extract text from Anthropic response.
	 *
	 * @param array $json Decoded JSON.
	 * @return string
	 */
	private static function extract_anthropic_text( $json ) {
		$chunks = array();

		if ( ! empty( $json['content'] ) && is_array( $json['content'] ) ) {
			foreach ( $json['content'] as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$chunks[] = $content['text'];
				}
			}
		}

		return $chunks ? trim( implode( "\n", $chunks ) ) : __( 'No text returned.', 'lunara-ai-assistant-classic' );
	}

	/**
	 * Extract text from Gemini response.
	 *
	 * @param array $json Decoded JSON.
	 * @return string
	 */
	private static function extract_gemini_text( $json ) {
		$chunks = array();

		if ( ! empty( $json['candidates'] ) && is_array( $json['candidates'] ) ) {
			foreach ( $json['candidates'] as $candidate ) {
				if ( empty( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
					continue;
				}

				foreach ( $candidate['content']['parts'] as $part ) {
					if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$chunks[] = $part['text'];
					}
				}
			}
		}

		return $chunks ? trim( implode( "\n", $chunks ) ) : __( 'No text returned.', 'lunara-ai-assistant-classic' );
	}
}

add_action( 'plugins_loaded', array( 'Lunara_AI_Assistant_Classic', 'init' ) );
