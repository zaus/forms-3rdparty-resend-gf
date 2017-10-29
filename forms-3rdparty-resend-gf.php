<?php
/*

Plugin Name: Forms-3rdparty Gravity Forms Resubmit Entry
Plugin URI: https://github.com/zaus/forms-3rdparty-gf-resend
Description: Resend Gravity Forms entries to 3rdparty endpoint
Author: zaus
Version: 0.1.2
Author URI: http://drzaus.com
Changelog:
	0.1	initial
	0.1.2 fixed checkboxes
*/

class F3iGfResend {

	const N = 'F3iGfResend';
	const B = 'Forms3rdPartyIntegration';

	public function __construct() {
		add_action('admin_init', array(&$this, 'init'));
	}

	public function init() {
		// adds to entry page toolbar
		add_filter( 'gform_toolbar_menu', array(&$this, 'toolbar'), 10, 2 );
		// adds to entry listing toolbar
		add_action('gform_entries_first_column_actions', array(&$this, 'add_action'), 10, 5);
		// ajax behavior on entry list
		add_action( 'admin_print_footer_scripts', array(&$this, 'scripts') );
		add_action( 'wp_ajax_' . self::N, array(&$this, 'resend_ajax') );

		// whenever you edit the entry?
		//add_action( 'gform_after_update_entry', array(&$this, 'update_entry'), 10, 3 );

		// other potentials?
		//RGForms::post( 'action' )
	}

	public function toolbar($menu_items, $form_id) {
		// gravityforms.php -- https://www.gravityhelp.com/documentation/article/gform_toolbar_menu/

		$menu_items['my_custom_link'] = array(
			'label' => 'Resend 3rdparty', // the text to display on the menu for this link
			'title' => 'Resend submission to 3rdparty', // the text to be displayed in the title attribute for this link
			'url' => self_admin_url( 'admin.php?page=' . self::N . '&id=' . $form_id ),  // the URL this link should point to, but not js
			'menu_class' => 'gf_form_toolbar_f3i_resend', // optional, class to apply to menu list item (useful for providing a custom icon)
			'link_class' => rgget( 'page' ) == self::N ? 'gf_toolbar_active' : '', // class to apply to link (useful for specifying an active style when this link is the current page)
			'capabilities' => array( 'gravityforms_edit_forms' ) // the capabilities the user should possess in order to access this page
			//, 'priority' => 500 // optional, use this to specify the order in which this menu item should appear; if no priority is provided, the menu item will be append to end
		);

		return $menu_items;
	}

	public function add_action($form_id, $field_id, $value, $entry, $query_string) {
		// entry_list.php

		?>
		| <span class="edit">
			<a id="f3i_resend_<?php echo esc_attr( $entry['id'] ); ?>" title="Resend submission to 3rdparty" href="<?php echo $this->format_ajax_url($entry['id']); ?>" style="display:inline"><?php esc_html_e( '3rdparty', self::N ); ?></a>
		<?php
	}

	function format_ajax_url($entry_id) {
		return sprintf("javascript:%s(%s);", self::N, $entry_id);
	}

	function update_entry( $form, $entry_id, $original_entry ) {
		// https://www.gravityhelp.com/documentation/article/gform_after_update_entry/

		$entry = GFAPI::get_entry( $entry_id );

		// do stuff...

		GFCommon::log_debug( 'gform_after_update_entry: original_entry => ' . print_r( $original_entry, 1 ) );
		GFCommon::log_debug( 'gform_after_update_entry: updated entry => ' . print_r( $entry, 1 ) );
	}

	const PARAM_ENTRY_ID = 'lead_id';

	public function scripts() {
		// found 'mysack' in GF entry_list.php as well, adapted to use use https://codex.wordpress.org/AJAX_in_Plugins
		$N = self::N;
		?><script>
		(function($) {
			// quick cheat for entry page
			var $resendButton = $('.gf_form_toolbar_f3i_resend a');
			var entryId = $resendButton.prop('href').split('id=')[1];
			$resendButton.prop('href', '<?php echo $this->format_ajax_url("' + entryId + '"); ?>');

			// expose global so GF link callback can reach it
			window.<?php echo self::N ?> = function(entry_id, name, value) {
				var data = <?php echo json_encode(array(
					'action' => $N
					, $N => wp_create_nonce( $N )
					//, self::PARAM_ENTRY_ID => 'entry_id'
				)); ?>;
				data['<?php echo self::PARAM_ENTRY_ID ?>'] = entry_id;
				if(name) data.name = name;
				if(value) data.value = value;

				return $.post(ajaxurl, data, null, 'json')
					.fail(function(x) {
						alert(<?php echo json_encode( __( 'Ajax error while resending submission', self::N ) ); ?>)
					})
					.done(function(response) {
						if(!response.entry_id) alert('Unable to resend entry: ' + JSON.stringify(response));
						else alert('Success resending entry ' + response.entry_id);
					});
			}
		})(jQuery);

		</script><?php
	}

	public function resend_ajax() {
		check_admin_referer(self::N, self::N);

		$entry_id = $_POST[self::PARAM_ENTRY_ID];

		$entry = GFAPI::get_entry( $entry_id );
		// get the form so we can 'restart' the original f3i processing
		$form = GFAPI::get_form( $entry['form_id'] ); // same thing? GFFormsModel::get_form_meta( $entry['form_id'] );

		### _log($entry, $form['fields'] );

		$submission = array();
		foreach ( $form['fields'] as $field ) {
			$id = $field->id;
			if($field->type == 'checkbox') {
				$i = count($field->choices);
				$submission['input_' . $id] = array();
				$submission[$field->label] = array();
				while($i-- > 0) {
					$k = sprintf('%d.%d', $id, $i+1);
					### _log($i, $k);
					if(isset($entry[$k]) && !empty($entry[$k])) {
						$submission['input_' . $id][$i] = $entry[$k];
						$submission[$field->label][$i] = $entry[$k];
					}
				}
			}
			else {
				$submission['input_' . $id] = $entry[$id];
				$submission[$field->label] = $entry[$id];
			}
		}

		$f3p = Forms3rdPartyIntegration::$instance;
		// just call the whole shebang
		$f3p->before_send($form, $submission);

		echo json_encode(array('entry_id' => $entry_id));
		wp_die(); // this is required to terminate immediately and return a proper response
	}


}//---	class	F3iGfResend

// engage!
new F3iGfResend();


