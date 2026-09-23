<?php
/**
 * [surge_evaluation_popup] shortcode.
 *
 * @package SurgeEvaluationPopup
 */

declare( strict_types=1 );

namespace SurgeEvaluationPopup\Frontend;

use SurgeEvaluationPopup\Rest\Controller;
use SurgeEvaluationPopup\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders hidden popup markup; the script decides (via an uncached REST call) whether to open it.
 */
final class Shortcode {
	/**
	 * Shortcode tag.
	 */
	public const TAG = 'surge_evaluation_popup';

	/**
	 * Whether the popup has already been rendered on this page.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets; the shortcode enqueues them on demand.
	 */
	public function register_assets(): void {
		wp_register_style(
			'surge-eval-popup',
			plugins_url( 'assets/css/popup.css', SURGE_EVAL_POPUP_FILE ),
			array(),
			SURGE_EVAL_POPUP_VERSION
		);

		wp_register_script(
			'surge-eval-popup',
			plugins_url( 'assets/js/popup.js', SURGE_EVAL_POPUP_FILE ),
			array(),
			SURGE_EVAL_POPUP_VERSION,
			true
		);
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function render( $atts ): string {
		if ( self::$rendered || ! Settings::get( 'enabled' ) ) {
			return '';
		}

		$s    = Settings::all();
		$atts = shortcode_atts(
			array(
				'trigger'  => $s['trigger'],
				'delay'    => $s['delay_seconds'],
				'scroll'   => $s['scroll_percent'],
				'geo'      => 'yes',
				'badge'    => $s['badge_text'],
				'headline' => $s['headline'],
				'button'   => $s['button_text'],
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		self::$rendered = true;

		if ( ! wp_style_is( 'surge-eval-popup', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'surge-eval-popup' );
		wp_enqueue_script( 'surge-eval-popup' );

		$config = array(
			'geoUrl'        => rest_url( Controller::REST_NAMESPACE . '/geo' ),
			'submitUrl'     => rest_url( Controller::REST_NAMESPACE . '/submit' ),
			// Only logged-in pages get a nonce: they bypass page caching, so it is never stale.
			'nonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'trigger'       => sanitize_key( (string) $atts['trigger'] ),
			'delay'         => max( 0, (int) $atts['delay'] ),
			'scroll'        => min( 100, max( 1, (int) $atts['scroll'] ) ),
			'geo'           => 'no' !== strtolower( (string) $atts['geo'] ),
			'dismissDays'   => (int) $s['dismiss_days'],
			'submittedDays' => (int) $s['submitted_days'],
			'mobile'        => (bool) $s['show_on_mobile'],
		);

		$phone_digits = preg_replace( '/[^\d+]/', '', (string) $s['phone_number'] );

		ob_start();
		?>
		<div class="surge-eval" id="surge-eval-popup" data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>" hidden>
			<div class="surge-eval__overlay" data-surge-eval-close></div>
			<div class="surge-eval__dialog" role="dialog" aria-modal="true" aria-labelledby="surge-eval-title" tabindex="-1">
				<button type="button" class="surge-eval__close" aria-label="<?php esc_attr_e( 'Close', 'surge-evaluation-popup' ); ?>" data-surge-eval-close>
					<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
				</button>

				<div class="surge-eval__body" data-surge-eval-form-view>
					<?php if ( $s['show_icon'] ) : ?>
						<div class="surge-eval__icon" aria-hidden="true">
							<svg viewBox="0 0 64 72" width="56" height="63">
								<ellipse cx="32" cy="68" rx="18" ry="3.5" fill="#000" opacity=".12"/>
								<path d="M32 2 6 11v19c0 17 11 29 26 36 15-7 26-19 26-36V11L32 2z" fill="#f26b1d"/>
								<path d="M32 2 6 11v19c0 17 11 29 26 36V2z" fill="#ff8a3d"/>
								<path d="M36 14 22 38h9l-3 18 15-26h-9l2-16z" fill="#fff"/>
							</svg>
						</div>
					<?php endif; ?>

					<?php if ( '' !== (string) $atts['badge'] ) : ?>
						<div class="surge-eval__badge"><?php echo esc_html( (string) $atts['badge'] ); ?></div>
					<?php endif; ?>

					<h2 class="surge-eval__title" id="surge-eval-title"><?php echo esc_html( (string) $atts['headline'] ); ?></h2>

					<form class="surge-eval__form" novalidate>
						<div class="surge-eval__row">
							<label class="surge-eval__field">
								<span class="screen-reader-text"><?php echo esc_html( $s['name_placeholder'] ); ?></span>
								<input type="text" name="name" autocomplete="name" required maxlength="100" placeholder="<?php echo esc_attr( $s['name_placeholder'] ); ?>">
							</label>
							<label class="surge-eval__field">
								<span class="screen-reader-text"><?php echo esc_html( $s['phone_placeholder'] ); ?></span>
								<input type="tel" name="phone" autocomplete="tel-national" inputmode="tel" required maxlength="20" placeholder="<?php echo esc_attr( $s['phone_placeholder'] ); ?>">
							</label>
						</div>
						<div class="surge-eval__row surge-eval__row--submit">
							<label class="surge-eval__field surge-eval__field--zip">
								<span class="screen-reader-text"><?php echo esc_html( $s['zip_placeholder'] ); ?></span>
								<input type="text" name="zip" autocomplete="postal-code" inputmode="numeric" pattern="\d{5}" required maxlength="5" placeholder="<?php echo esc_attr( $s['zip_placeholder'] ); ?>">
							</label>
							<button type="submit" class="surge-eval__submit"><?php echo esc_html( (string) $atts['button'] ); ?></button>
						</div>
						<div class="surge-eval__hp" aria-hidden="true">
							<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
						</div>
						<p class="surge-eval__error" role="alert" hidden></p>
					</form>

					<?php if ( '' !== $phone_digits ) : ?>
						<a class="surge-eval__call" href="tel:<?php echo esc_attr( $phone_digits ); ?>">
							<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>
							<span><?php echo esc_html( $s['call_text'] ); ?></span>
						</a>
					<?php endif; ?>

					<?php if ( $s['show_rating'] ) : ?>
						<div class="surge-eval__rating">
							<span class="surge-eval__stars" aria-hidden="true">★★★★★</span>
							<span><?php echo esc_html( $s['rating_text'] ); ?></span>
						</div>
					<?php endif; ?>

					<?php if ( '' !== (string) $s['trust_text'] ) : ?>
						<div class="surge-eval__trust"><?php echo esc_html( $s['trust_text'] ); ?></div>
					<?php endif; ?>
				</div>

				<div class="surge-eval__body surge-eval__success" data-surge-eval-success-view hidden>
					<div class="surge-eval__check" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="40" height="40"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</div>
					<h2 class="surge-eval__title" data-surge-eval-success-title><?php echo esc_html( $s['success_title'] ); ?></h2>
					<p class="surge-eval__success-text" data-surge-eval-success-message><?php echo esc_html( $s['success_message'] ); ?></p>
					<button type="button" class="surge-eval__submit" data-surge-eval-close><?php esc_html_e( 'Close', 'surge-evaluation-popup' ); ?></button>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
