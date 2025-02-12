<?php
/**
 * The default checkbox field output template.
 *
 * @global \GV\Template_Context $gravityview
 * @since 2.0
 */
if ( ! isset( $gravityview ) || empty( $gravityview->template ) ) {
	gravityview()->log->error( '{file} template loaded without context', array( 'file' => __FILE__ ) );
	return;
}

$field_id       = $gravityview->field->ID;
$field          = $gravityview->field->field;
$value          = $gravityview->value;
$form           = $gravityview->view->form->form;
$display_value  = $gravityview->display_value;
$entry          = $gravityview->entry->as_entry();
$field_settings = $gravityview->field->as_configuration();

$is_single_input = floor( $field_id ) !== floatval( $field_id );

$output = '';

$display_type = \GV\Utils::get( $field_settings, 'choice_display' );

// It's the parent field, not an input
if ( ! $is_single_input ) {
	if ( 'label' === $display_type ) {
		$output = $field->get_value_entry_detail( $value, '', true );
	} else {
		$output = gravityview_get_field_value( $entry, $field_id, $display_value );
	}
} else {

	$field_value = gravityview_get_field_value( $entry, $field_id, $display_value );

	switch ( $display_type ) {
		case 'value':
			$output = $field_value;
			break;
		case 'label':
			$output = gravityview_get_field_label( $form, $field_id, $value );
			break;
		case 'tick':
		default: // Backward compatibility
			if ( '' !== $field_value ) {
				/**
				 * Change the output for a checkbox "check" symbol. Default is the "dashicons-yes" icon.
				 * @see https://developer.wordpress.org/resource/dashicons/#yes
				 *
				 * @param string $output HTML span with `dashicons dashicons-yes` class
				 * @param array $entry Gravity Forms entry array
				 * @param array $field GravityView field array
				 *
				 * @since 2.0
				 * @param \GV\Template_Context The template context.
				 */
				$output = apply_filters( 'gravityview_field_tick', '<span class="dashicons dashicons-yes"></span>', $entry, $field, $gravityview );
			}
			break;
	}
}

echo $output;
