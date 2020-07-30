<?php

/**
 * Register the FancyBox lightbox
 *
 * @internal
 */
class GravityView_Lightbox_Provider_FancyBox extends GravityView_Lightbox_Provider {

	public static $slug = 'fancybox';

	public static $script_slug = 'gravityview-fancybox';

	public static $style_slug = 'gravityview-fancybox';

	/**
	 * Options to pass to Fancybox
	 *
	 * @see http://fancyapps.com/fancybox/3/docs/#options
	 *
	 * @return array
	 */
	protected function default_settings() {

		$defaults = array(
			'animationEffect' => 'fade',
			'toolbar'         => true,
			'closeExisting'   => true,
			'arrows'          => true,
			'buttons'         => array(
					'thumbs',
					'close',
			),
		);

		return $defaults;
	}

	public function output_footer() {

		$settings = self::get_settings();

		$settings = json_encode( $settings );

		?>
		<style>
			.fancybox-container {
				z-index: 100000; /** Divi is 99999 */
			}

			.admin-bar .fancybox-container {
				margin-top: 32px;
			}
		</style>
		<script>
			jQuery( '.gv-fancybox' ).fancybox(<?php echo $settings; ?>);
		</script>
		<?php
	}

	/**
	 * Enqueue scripts for the lightbox
	 */
	public function enqueue_scripts() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( self::$script_slug, plugins_url( 'assets/lib/fancybox/dist/jquery.fancybox' . $min . '.js', GRAVITYVIEW_FILE ), array( 'jquery' ), GV_PLUGIN_VERSION );
	}

	/**
	 * Enqueue styles for the lightbox
	 */
	public function enqueue_styles() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_style( self::$style_slug, plugins_url( 'assets/lib/fancybox/dist/jquery.fancybox' . $min . '.css', GRAVITYVIEW_FILE ), array(), GV_PLUGIN_VERSION );
	}

	/**
	 * Modify the attributes allowed in an anchor tag generated by GravityView
	 *
	 * @param array $atts Attributes allowed in an anchor tag
	 *
	 * @return array
	 */
	public function allowed_atts( $atts = array() ) {

		$atts['data-fancybox'] = null;

		return $atts;
	}

	/**
	 * @inheritDoc
	 */
	public function fileupload_link_atts( $link_atts, $field_compat = array(), $context = null ) {

		if ( ! $context->view->settings->get( 'lightbox', false ) ) {
			return $link_atts;
		}

		$link_atts['class'] = 'gv-fancybox';

		if ( $context && ! empty( $context->field->field ) ) {
			if ( $context->field->field->multipleFiles ) {
				$link_atts['data-fancybox'] = 'gallery';
			}
		}

		return $link_atts;
	}

}

GravityView_Lightbox::register( 'GravityView_Lightbox_Provider_FancyBox' );
