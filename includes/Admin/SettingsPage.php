<?php
/**
 * Admin settings, testing tools and submissions log.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Admin;

use SurgeEvaluationPopup\Database\LeadRepository;
use SurgeEvaluationPopup\Frontend\Shortcode;
use SurgeEvaluationPopup\Geo\IpResolver;
use SurgeEvaluationPopup\Geo\Locator;
use SurgeEvaluationPopup\Integrations\HousecallPro;
use SurgeEvaluationPopup\Integrations\Mailgun;
use SurgeEvaluationPopup\Leads\SubmissionHandler;
use SurgeEvaluationPopup\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the "Evaluation Popup" admin screen.
 */
final class SettingsPage {
	/**
	 * Page slug.
	 */
	private const SLUG = 'surge-evaluation-popup';

	/**
	 * Sample locations for the coordinate tester (approximate city centers).
	 *
	 * @var array<string, array{0: float, 1: float, 2: string, 3: string}>
	 */
	private const SAMPLE_LOCATIONS = array(
		'Seattle, WA'       => array( 47.6062, -122.3321, 'Washington', 'US' ),
		'Bellevue, WA'      => array( 47.6101, -122.2015, 'Washington', 'US' ),
		'Everett, WA'       => array( 47.9790, -122.2021, 'Washington', 'US' ),
		'Tacoma, WA'        => array( 47.2529, -122.4443, 'Washington', 'US' ),
		'Issaquah, WA'      => array( 47.5301, -122.0326, 'Washington', 'US' ),
		'Bremerton, WA'     => array( 47.5673, -122.6326, 'Washington', 'US' ),
		'Olympia, WA'       => array( 47.0379, -122.9007, 'Washington', 'US' ),
		'Bellingham, WA'    => array( 48.7519, -122.4787, 'Washington', 'US' ),
		'Spokane, WA'       => array( 47.6588, -117.4260, 'Washington', 'US' ),
		'Portland, OR'      => array( 45.5152, -122.6784, 'Oregon', 'US' ),
		'Vancouver, BC'     => array( 49.2827, -123.1207, 'British Columbia', 'CA' ),
	);

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SURGE_EVAL_POPUP_FILE ), array( $this, 'action_links' ) );

		foreach ( array( 'save', 'test_geo', 'test_coords', 'test_mailgun', 'test_hcp', 'test_lead', 'clear_cache', 'retry', 'delete' ) as $action ) {
			add_action( 'admin_post_surge_eval_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Add the admin menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Evaluation Popup', 'surge-evaluation-popup' ),
			__( 'Evaluation Popup', 'surge-evaluation-popup' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			27
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'surge-evaluation-popup' ) . '</a>' );

		return $links;
	}

	/**
	 * Enqueue admin CSS on this screen.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'surge-eval-popup-admin', plugins_url( 'assets/css/admin.css', SURGE_EVAL_POPUP_FILE ), array(), SURGE_EVAL_POPUP_VERSION );
	}

	/**
	 * Admin page URL.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, mixed> $args Extra query args.
	 */
	private static function url( string $tab = 'general', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'surge-evaluation-popup' ) );
		}

		$tabs = Settings::tabs() + array( 'submissions' => __( 'Submissions', 'surge-evaluation-popup' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="wrap surge-eval-admin">
			<h1><?php esc_html_e( 'Evaluation Popup', 'surge-evaluation-popup' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: shortcode. */
					esc_html__( 'Add %s to any page (or a site-wide widget/footer) to load the popup. It only opens for visitors inside the service area.', 'surge-evaluation-popup' ),
					'<code>[' . esc_html( Shortcode::TAG ) . ']</code>'
				);
				?>
			</p>

			<?php $this->render_notice(); ?>
			<?php $this->render_status_bar(); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'submissions' === $tab ) {
				$this->render_submissions();
			} else {
				$this->render_settings_form( $tab );

				if ( 'testing' === $tab ) {
					$this->render_testing_tools();
				}

				if ( 'content' === $tab || 'general' === $tab ) {
					$this->render_shortcode_help();
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Quick status of each integration.
	 */
	private function render_status_bar(): void {
		$items = array(
			__( 'Popup', 'surge-evaluation-popup' )          => Settings::get( 'enabled' ) ? array( 'ok', __( 'Enabled', 'surge-evaluation-popup' ) ) : array( 'off', __( 'Disabled', 'surge-evaluation-popup' ) ),
			__( 'Targeting', 'surge-evaluation-popup' )      => Settings::get( 'geo_enabled' ) ? array( 'ok', sprintf( '%s mi of %s, %s', Settings::get( 'radius_miles' ), Settings::get( 'center_lat' ), Settings::get( 'center_lng' ) ) ) : array( 'warn', __( 'Off: shows everywhere', 'surge-evaluation-popup' ) ),
			__( 'Mailgun', 'surge-evaluation-popup' )        => ! Settings::get( 'mailgun_enabled' ) ? array( 'off', __( 'Disabled', 'surge-evaluation-popup' ) ) : ( ( new Mailgun() )->is_configured() ? array( 'ok', __( 'Configured', 'surge-evaluation-popup' ) ) : array( 'err', __( 'Missing settings', 'surge-evaluation-popup' ) ) ),
			__( 'Housecall Pro', 'surge-evaluation-popup' )  => ! Settings::get( 'hcp_enabled' ) ? array( 'off', __( 'Disabled', 'surge-evaluation-popup' ) ) : ( ( new HousecallPro() )->is_configured() ? array( 'ok', '' === (string) Settings::get( 'hcp_api_key' ) ? __( 'Using HCP_API_KEY constant', 'surge-evaluation-popup' ) : __( 'API key saved', 'surge-evaluation-popup' ) ) : array( 'err', __( 'No API key', 'surge-evaluation-popup' ) ) ),
			__( 'Test mode', 'surge-evaluation-popup' )      => 'off' === Settings::get( 'test_mode' ) ? array( 'ok', __( 'Off', 'surge-evaluation-popup' ) ) : array( 'warn', 'everyone' === Settings::get( 'test_mode' ) ? __( 'Showing to EVERYONE', 'surge-evaluation-popup' ) : __( 'Admins always see it', 'surge-evaluation-popup' ) ),
		);
		?>
		<div class="surge-eval-status">
			<?php foreach ( $items as $label => $item ) : ?>
				<div class="surge-eval-status__item surge-eval-status__item--<?php echo esc_attr( $item[0] ); ?>">
					<strong><?php echo esc_html( $label ); ?></strong>
					<span><?php echo esc_html( $item[1] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the settings form for a tab.
	 *
	 * @param string $tab Tab.
	 */
	private function render_settings_form( string $tab ): void {
		$values = Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="surge_eval_save">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( 'surge_eval_save' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( Settings::schema() as $key => $field ) : ?>
					<?php
					if ( $field['tab'] !== $tab ) {
						continue;
					}
					$id    = 'surge-eval-' . $key;
					$name  = 'settings[' . $key . ']';
					$value = $values[ $key ];
					?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<?php
							switch ( $field['type'] ) {
								case 'checkbox':
									printf( '<input type="checkbox" id="%s" name="%s" value="1" %s>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
									break;
								case 'select':
									printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
									foreach ( $field['options'] as $option => $option_label ) {
										printf( '<option value="%s" %s>%s</option>', esc_attr( $option ), selected( (string) $value, (string) $option, false ), esc_html( $option_label ) );
									}
									echo '</select>';
									break;
								case 'textarea':
									printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
									break;
								case 'password':
									$saved = '' !== (string) $value;
									printf(
										'<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
										esc_attr( $id ),
										esc_attr( $name ),
										esc_attr( $saved ? '•••••••• ' . __( 'saved (leave blank to keep, "-" to clear)', 'surge-evaluation-popup' ) : '' )
									);
									break;
								case 'number':
									printf(
										'<input type="number" id="%s" name="%s" value="%s" class="small-text" %s %s>',
										esc_attr( $id ),
										esc_attr( $name ),
										esc_attr( (string) $value ),
										isset( $field['min'] ) ? 'min="' . esc_attr( (string) $field['min'] ) . '"' : '',
										isset( $field['max'] ) ? 'max="' . esc_attr( (string) $field['max'] ) . '"' : ''
									);
									break;
								default:
									printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', 'email' === $field['type'] ? 'email' : 'text', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
							}

							if ( ! empty( $field['help'] ) ) {
								echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Shortcode usage reference.
	 */
	private function render_shortcode_help(): void {
		?>
		<div class="surge-eval-card">
			<h2><?php esc_html_e( 'Shortcode reference', 'surge-evaluation-popup' ); ?></h2>
			<p><code>[surge_evaluation_popup]</code> &mdash; <?php esc_html_e( 'uses the settings above.', 'surge-evaluation-popup' ); ?></p>
			<p><?php esc_html_e( 'Optional attributes override the settings for that page:', 'surge-evaluation-popup' ); ?></p>
			<ul class="ul-disc">
				<li><code>trigger="immediate|delay|scroll|exit|delay_or_exit|manual"</code></li>
				<li><code>delay="5"</code>, <code>scroll="50"</code></li>
				<li><code>geo="no"</code> &mdash; <?php esc_html_e( 'skip location targeting on this page', 'surge-evaluation-popup' ); ?></li>
				<li><code>badge="..."</code>, <code>headline="..."</code>, <code>button="..."</code></li>
			</ul>
			<p><?php esc_html_e( 'Any link or button with class "surge-eval-open" (or href="#surge-eval") opens the popup on click, for any visitor.', 'surge-evaluation-popup' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Testing tools shown under the Testing tab.
	 */
	private function render_testing_tools(): void {
		$result    = get_transient( 'surge_eval_test_result_' . get_current_user_id() );
		$my_ip     = IpResolver::visitor_ip();
		$pages     = $this->pages_with_shortcode();
		$base_url  = ! empty( $pages ) ? get_permalink( $pages[0] ) : home_url( '/' );
		$form_open = static function ( string $action ): void {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="surge-eval-inline-form">';
			echo '<input type="hidden" name="action" value="surge_eval_' . esc_attr( $action ) . '">';
			wp_nonce_field( 'surge_eval_' . $action );
		};
		?>
		<?php if ( is_array( $result ) ) : ?>
			<?php delete_transient( 'surge_eval_test_result_' . get_current_user_id() ); ?>
			<div class="surge-eval-card surge-eval-result surge-eval-result--<?php echo ! empty( $result['eligible'] ) ? 'ok' : 'err'; ?>">
				<h2>
					<?php echo esc_html( (string) $result['title'] ); ?>:
					<?php echo ! empty( $result['eligible'] ) ? esc_html__( 'POPUP WOULD SHOW', 'surge-evaluation-popup' ) : esc_html__( 'POPUP WOULD NOT SHOW', 'surge-evaluation-popup' ); ?>
				</h2>
				<p><?php echo esc_html( (string) $result['reason'] ); ?></p>
				<?php if ( ! empty( $result['location'] ) ) : ?>
					<pre><?php echo esc_html( (string) wp_json_encode( $result['location'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="surge-eval-grid">
			<div class="surge-eval-card">
				<h2><?php esc_html_e( '1. Preview on the site', 'surge-evaluation-popup' ); ?></h2>
				<p><?php esc_html_e( 'While logged in as an admin, these links open the popup immediately and ignore "already closed/submitted" memory. Results are also logged to the browser console.', 'surge-evaluation-popup' ); ?></p>
				<?php if ( empty( $pages ) ) : ?>
					<p class="surge-eval-warning"><?php esc_html_e( 'No published page contains the shortcode yet. Add [surge_evaluation_popup] to a page first.', 'surge-evaluation-popup' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Pages using the shortcode:', 'surge-evaluation-popup' ); ?>
						<?php foreach ( $pages as $page_id ) : ?>
							<a href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $page_id ) ); ?></a>&nbsp;
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<p>
					<a class="button button-primary" target="_blank" href="<?php echo esc_url( add_query_arg( 'surge_eval_preview', '1', (string) $base_url ) ); ?>"><?php esc_html_e( 'Force-show preview', 'surge-evaluation-popup' ); ?></a>
					<a class="button" target="_blank" href="<?php echo esc_url( add_query_arg( 'surge_eval_reset', '1', (string) $base_url ) ); ?>"><?php esc_html_e( 'Reset my "closed" memory & view as visitor', 'surge-evaluation-popup' ); ?></a>
				</p>
				<form method="get" action="<?php echo esc_url( (string) $base_url ); ?>" target="_blank" class="surge-eval-inline-form">
					<label><?php esc_html_e( 'Simulate a visitor IP:', 'surge-evaluation-popup' ); ?>
						<input type="text" name="surge_eval_test_ip" placeholder="e.g. 8.8.8.8" class="regular-text" required>
					</label>
					<button class="button"><?php esc_html_e( 'Open page as that IP', 'surge-evaluation-popup' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Tip: to test from a real Seattle-area connection, use a VPN endpoint in Seattle, or ask someone local to visit with the "Reset" link. Visitor IPs of recent submissions are shown under Submissions and can be pasted here.', 'surge-evaluation-popup' ); ?></p>
			</div>

			<div class="surge-eval-card">
				<h2><?php esc_html_e( '2. Test an IP lookup', 'surge-evaluation-popup' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: IP address. */
						esc_html__( 'Your detected IP: %s', 'surge-evaluation-popup' ),
						'<code>' . esc_html( '' !== $my_ip ? $my_ip : 'unknown' ) . '</code>'
					);
					if ( '' !== $my_ip && ! IpResolver::is_public( $my_ip ) ) {
						echo ' <em>' . esc_html__( '(private/local, as expected on a local dev site; use a public test IP)', 'surge-evaluation-popup' ) . '</em>';
					}
					?>
				</p>
				<?php $form_open( 'test_geo' ); ?>
					<input type="text" name="ip" value="<?php echo esc_attr( IpResolver::is_public( $my_ip ) ? $my_ip : '' ); ?>" placeholder="IP address" class="regular-text" required>
					<label><input type="checkbox" name="fresh" value="1" checked> <?php esc_html_e( 'Bypass cache', 'surge-evaluation-popup' ); ?></label>
					<button class="button button-primary"><?php esc_html_e( 'Look up', 'surge-evaluation-popup' ); ?></button>
				</form>
				<p class="description"><?php esc_html_e( 'Calls the configured provider and applies your radius/state rules.', 'surge-evaluation-popup' ); ?></p>
			</div>

			<div class="surge-eval-card">
				<h2><?php esc_html_e( '3. Test the service-area rules', 'surge-evaluation-popup' ); ?></h2>
				<p><?php esc_html_e( 'Checks the radius/state logic against known city coordinates, with no API call.', 'surge-evaluation-popup' ); ?></p>
				<?php $form_open( 'test_coords' ); ?>
					<select name="sample">
						<?php foreach ( array_keys( self::SAMPLE_LOCATIONS ) as $label ) : ?>
							<option value="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						<option value="all"><?php esc_html_e( 'Run all', 'surge-evaluation-popup' ); ?></option>
					</select>
					<button class="button button-primary"><?php esc_html_e( 'Evaluate', 'surge-evaluation-popup' ); ?></button>
				</form>
				<?php $form_open( 'test_coords' ); ?>
					<input type="text" name="lat" placeholder="Latitude" class="small-text" required>
					<input type="text" name="lng" placeholder="Longitude" class="small-text" required>
					<button class="button"><?php esc_html_e( 'Evaluate coordinates (assumes WA, US)', 'surge-evaluation-popup' ); ?></button>
				</form>
			</div>

			<div class="surge-eval-card">
				<h2><?php esc_html_e( '4. Test the integrations', 'surge-evaluation-popup' ); ?></h2>
				<?php $form_open( 'test_mailgun' ); ?>
					<button class="button"><?php esc_html_e( 'Send Mailgun test email', 'surge-evaluation-popup' ); ?></button>
				</form>
				<?php $form_open( 'test_hcp' ); ?>
					<button class="button"><?php esc_html_e( 'Check Housecall Pro connection (read-only)', 'surge-evaluation-popup' ); ?></button>
				</form>
				<?php $form_open( 'clear_cache' ); ?>
					<button class="button"><?php esc_html_e( 'Clear geo lookup cache', 'surge-evaluation-popup' ); ?></button>
				</form>
			</div>

			<div class="surge-eval-card surge-eval-card--wide">
				<h2><?php esc_html_e( '5. Send a full test submission', 'surge-evaluation-popup' ); ?></h2>
				<p><?php esc_html_e( 'Runs the complete pipeline (save, Housecall Pro, Mailgun) exactly like a real visitor. The email is prefixed [TEST] and HCP records are tagged "Test".', 'surge-evaluation-popup' ); ?></p>
				<?php $form_open( 'test_lead' ); ?>
					<input type="text" name="name" value="Test Lead" placeholder="Name" required>
					<input type="text" name="phone" value="" placeholder="Phone (use your own)" required>
					<input type="text" name="zip" value="98101" placeholder="ZIP" class="small-text" required>
					<label><input type="checkbox" name="include_hcp" value="1"> <?php esc_html_e( 'Also create REAL records in Housecall Pro', 'surge-evaluation-popup' ); ?></label>
					<button class="button button-primary"><?php esc_html_e( 'Send test submission', 'surge-evaluation-popup' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Submissions log.
	 */
	private function render_submissions(): void {
		$repo     = new LeadRepository();
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total    = $repo->count();
		$rows     = $repo->recent( $per_page, ( $paged - 1 ) * $per_page );
		?>
		<p><?php echo esc_html( sprintf( /* translators: %d: count. */ _n( '%d submission', '%d submissions', $total, 'surge-evaluation-popup' ), $total ) ); ?></p>
		<table class="widefat striped surge-eval-leads">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'Name', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'ZIP', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'IP / Location', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'Housecall Pro', 'surge-evaluation-popup' ); ?></th>
					<th><?php esc_html_e( 'Mailgun', 'surge-evaluation-popup' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No submissions yet.', 'surge-evaluation-popup' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php echo esc_html( get_date_from_gmt( (string) $row['created_at'], 'M j, Y g:i a' ) ); ?>
							<?php if ( ! empty( $row['is_test'] ) ) : ?>
								<span class="surge-eval-pill surge-eval-pill--warn">TEST</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row['name'] ); ?></td>
						<td><a href="tel:<?php echo esc_attr( (string) $row['phone'] ); ?>"><?php echo esc_html( Mailgun::format_phone( (string) $row['phone'] ) ); ?></a></td>
						<td><?php echo esc_html( (string) $row['zip'] ); ?></td>
						<td>
							<code><?php echo esc_html( (string) $row['ip'] ); ?></code><br>
							<small><?php echo esc_html( trim( $row['geo_city'] . ', ' . $row['geo_region'], ', ' ) ); ?></small>
						</td>
						<td>
							<?php $this->status_pill( (string) $row['hcp_status'] ); ?>
							<?php if ( ! empty( $row['hcp_customer_id'] ) ) : ?>
								<a href="<?php echo esc_url( 'https://pro.housecallpro.com/app/customers/' . rawurlencode( (string) $row['hcp_customer_id'] ) ); ?>" target="_blank"><?php esc_html_e( 'Customer', 'surge-evaluation-popup' ); ?></a>
							<?php endif; ?>
							<br><small><?php echo esc_html( (string) $row['hcp_message'] ); ?></small>
						</td>
						<td>
							<?php $this->status_pill( (string) $row['mailgun_status'] ); ?>
							<br><small><?php echo esc_html( (string) $row['mailgun_message'] ); ?></small>
						</td>
						<td class="surge-eval-actions">
							<?php if ( 'success' !== $row['hcp_status'] || 'success' !== $row['mailgun_status'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="surge_eval_retry">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
									<?php wp_nonce_field( 'surge_eval_retry' ); ?>
									<button class="button button-small"><?php esc_html_e( 'Retry failed', 'surge-evaluation-popup' ); ?></button>
								</form>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Delete this submission from the log? (Does not touch HCP.)');">
								<input type="hidden" name="action" value="surge_eval_delete">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
								<?php wp_nonce_field( 'surge_eval_delete' ); ?>
								<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'surge-evaluation-popup' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per_page );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'submissions' ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Output a status pill.
	 *
	 * @param string $status Status.
	 */
	private function status_pill( string $status ): void {
		$map = array(
			'success' => 'ok',
			'error'   => 'err',
			'skipped' => 'off',
			'pending' => 'warn',
		);

		printf( '<span class="surge-eval-pill surge-eval-pill--%s">%s</span>', esc_attr( $map[ $status ] ?? 'off' ), esc_html( $status ) );
	}

	/**
	 * Published pages/posts whose content or SiteOrigin layout contains the shortcode.
	 *
	 * @return int[]
	 */
	private function pages_with_shortcode(): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '[' . Shortcode::TAG ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'panels_data'
				WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision','nav_menu_item')
				AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )
				LIMIT 10",
				$like,
				$like
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	// ---------------------------------------------------------------------
	// Action handlers.
	// ---------------------------------------------------------------------

	/**
	 * Verify capability and nonce for an action.
	 *
	 * @param string $action Action suffix.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'surge-evaluation-popup' ) );
		}

		check_admin_referer( 'surge_eval_' . $action );
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param string               $tab     Tab.
	 * @param string               $type    success|error|warning|info.
	 * @param string               $message Message.
	 * @param array<string, mixed> $args    Extra args.
	 */
	private function back( string $tab, string $type, string $message, array $args = array() ): void {
		set_transient(
			'surge_eval_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			120
		);

		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Render and clear the pending notice.
	 */
	private function render_notice(): void {
		$key    = 'surge_eval_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( (string) $notice['type'] ),
			nl2br( esc_html( (string) $notice['message'] ) )
		);
	}

	/**
	 * Store a test result for display.
	 *
	 * @param array<string, mixed> $result Result.
	 */
	private function store_result( array $result ): void {
		set_transient( 'surge_eval_test_result_' . get_current_user_id(), $result, 300 );
	}

	/**
	 * Save a settings tab.
	 */
	public function handle_save(): void {
		$this->guard( 'save' );

		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		// Each value is sanitized per field type in Settings::save_tab().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();

		if ( ! isset( Settings::tabs()[ $tab ] ) ) {
			$tab = 'general';
		}

		Settings::save_tab( $tab, $input );

		if ( 'geo' === $tab ) {
			Locator::clear_cache();
		}

		$this->back( $tab, 'success', __( 'Settings saved.', 'surge-evaluation-popup' ) );
	}

	/**
	 * Test an IP lookup.
	 */
	public function handle_test_geo(): void {
		$this->guard( 'test_geo' );

		$ip      = isset( $_POST['ip'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ip'] ) ) ) : '';
		$fresh   = ! empty( $_POST['fresh'] );
		$locator = new Locator();
		$lookup  = $locator->lookup( $ip, ! $fresh );

		if ( is_wp_error( $lookup ) ) {
			$this->store_result(
				array(
					'title'    => 'IP ' . $ip,
					'eligible' => 'show' === Settings::get( 'lookup_failure' ),
					'reason'   => 'Lookup failed: ' . $lookup->get_error_message() . ' (failure policy: ' . Settings::get( 'lookup_failure' ) . ')',
				)
			);
		} else {
			$this->store_result(
				array_merge(
					$locator->evaluate( $lookup ),
					array(
						'title'    => 'IP ' . $ip,
						'location' => $lookup,
					)
				)
			);
		}

		$this->back( 'testing', 'info', __( 'Lookup complete. See the result below.', 'surge-evaluation-popup' ) );
	}

	/**
	 * Test the rules against coordinates.
	 */
	public function handle_test_coords(): void {
		$this->guard( 'test_coords' );

		$locator = new Locator();
		$sample  = isset( $_POST['sample'] ) ? sanitize_text_field( wp_unslash( $_POST['sample'] ) ) : '';

		if ( 'all' === $sample ) {
			$lines    = array();
			$eligible = 0;

			foreach ( self::SAMPLE_LOCATIONS as $label => $coords ) {
				$eval     = $locator->evaluate( self::sample_location( $label, $coords ) );
				$eligible += $eval['eligible'] ? 1 : 0;
				$lines[]  = sprintf( '%s %s: %s', $eval['eligible'] ? '✔' : '✘', $label, $eval['reason'] );
			}

			$this->store_result(
				array(
					'title'    => sprintf( 'All samples (%d of %d eligible)', $eligible, count( self::SAMPLE_LOCATIONS ) ),
					'eligible' => $eligible > 0,
					'reason'   => '',
					'location' => $lines,
				)
			);
		} elseif ( isset( self::SAMPLE_LOCATIONS[ $sample ] ) ) {
			$location = self::sample_location( $sample, self::SAMPLE_LOCATIONS[ $sample ] );
			$this->store_result( array_merge( $locator->evaluate( $location ), array( 'title' => $sample, 'location' => $location ) ) );
		} else {
			$lat = isset( $_POST['lat'] ) ? sanitize_text_field( wp_unslash( $_POST['lat'] ) ) : '';
			$lng = isset( $_POST['lng'] ) ? sanitize_text_field( wp_unslash( $_POST['lng'] ) ) : '';

			if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				$this->back( 'testing', 'error', __( 'Enter numeric latitude and longitude.', 'surge-evaluation-popup' ) );
			}

			$location = array(
				'city'        => '',
				'region'      => 'Washington',
				'region_code' => 'WA',
				'country'     => 'US',
				'lat'         => (float) $lat,
				'lng'         => (float) $lng,
			);

			$this->store_result( array_merge( $locator->evaluate( $location ), array( 'title' => $lat . ', ' . $lng, 'location' => $location ) ) );
		}

		$this->back( 'testing', 'info', __( 'Evaluation complete. See the result below.', 'surge-evaluation-popup' ) );
	}

	/**
	 * Build a normalized location from a sample.
	 *
	 * @param string                                     $label  Label.
	 * @param array{0: float, 1: float, 2: string, 3: string} $coords Coordinates, region, country.
	 * @return array<string, mixed>
	 */
	private static function sample_location( string $label, array $coords ): array {
		return array(
			'city'        => trim( explode( ',', $label )[0] ),
			'region'      => $coords[2],
			'region_code' => '',
			'country'     => $coords[3],
			'lat'         => $coords[0],
			'lng'         => $coords[1],
		);
	}

	/**
	 * Send a Mailgun test email.
	 */
	public function handle_test_mailgun(): void {
		$this->guard( 'test_mailgun' );

		$result = ( new Mailgun() )->send(
			'[TEST] Surge Evaluation Popup: Mailgun is working',
			"This is a test email from the Surge Evaluation Popup plugin.\n\nSite: " . home_url( '/' ),
			'<p>This is a test email from the <strong>Surge Evaluation Popup</strong> plugin.</p><p>Site: ' . esc_html( home_url( '/' ) ) . '</p>'
		);

		if ( is_wp_error( $result ) ) {
			$this->back( 'testing', 'error', 'Mailgun test failed: ' . $result->get_error_message() );
		}

		$this->back( 'testing', 'success', sprintf( 'Mailgun accepted the test email for %s (id %s).', implode( ', ', Settings::csv( 'mailgun_to' ) ), $result ) );
	}

	/**
	 * Check the Housecall Pro connection.
	 */
	public function handle_test_hcp(): void {
		$this->guard( 'test_hcp' );

		$result = ( new HousecallPro() )->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->back( 'testing', 'error', 'Housecall Pro test failed: ' . $result->get_error_message() );
		}

		$this->back( 'testing', 'success', __( 'Housecall Pro API key works (read-only customers request succeeded). Note: creating leads requires the MAX plan and API Leads enabled in Job Inbox settings.', 'surge-evaluation-popup' ) );
	}

	/**
	 * Run a full test submission.
	 */
	public function handle_test_lead(): void {
		$this->guard( 'test_lead' );

		$handler = new SubmissionHandler();
		$fields  = $handler->validate(
			array(
				'name'  => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'phone' => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
				'zip'   => isset( $_POST['zip'] ) ? sanitize_text_field( wp_unslash( $_POST['zip'] ) ) : '',
			)
		);

		if ( is_wp_error( $fields ) ) {
			$this->back( 'testing', 'error', $fields->get_error_message() );
		}

		$include_hcp = ! empty( $_POST['include_hcp'] );

		if ( ! $include_hcp ) {
			Settings::override( array( 'hcp_enabled' => 0 ) );
		}

		$lead = $handler->submit(
			$fields,
			array(
				'ip'         => IpResolver::visitor_ip(),
				'geo_city'   => 'Seattle',
				'geo_region' => 'Washington',
				'page_url'   => admin_url( 'admin.php?page=' . self::SLUG ),
				'is_test'    => 1,
			)
		);

		if ( is_wp_error( $lead ) ) {
			$this->back( 'testing', 'error', $lead->get_error_message() );
		}

		$message = sprintf(
			"Test submission #%d saved.\nHousecall Pro: %s %s\nMailgun: %s %s",
			$lead['id'],
			$lead['hcp_status'],
			$lead['hcp_message'],
			$lead['mailgun_status'],
			$lead['mailgun_message']
		);

		$ok = 'error' !== $lead['hcp_status'] && 'error' !== $lead['mailgun_status'];

		$this->back( 'testing', $ok ? 'success' : 'warning', $message );
	}

	/**
	 * Clear the geo cache.
	 */
	public function handle_clear_cache(): void {
		$this->guard( 'clear_cache' );

		$deleted = Locator::clear_cache();

		$this->back( 'testing', 'success', sprintf( 'Cleared %d cached geo rows.', $deleted ) );
	}

	/**
	 * Retry failed integrations for a lead.
	 */
	public function handle_retry(): void {
		$this->guard( 'retry' );

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$lead = ( new SubmissionHandler() )->deliver( $id );

		if ( empty( $lead ) ) {
			$this->back( 'submissions', 'error', __( 'Submission not found.', 'surge-evaluation-popup' ) );
		}

		$this->back( 'submissions', 'info', sprintf( "Retried #%d.\nHousecall Pro: %s\nMailgun: %s", $id, $lead['hcp_status'], $lead['mailgun_status'] ) );
	}

	/**
	 * Delete a lead from the log.
	 */
	public function handle_delete(): void {
		$this->guard( 'delete' );

		( new LeadRepository() )->delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );

		$this->back( 'submissions', 'success', __( 'Submission deleted.', 'surge-evaluation-popup' ) );
	}
}
