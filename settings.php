<?php
 namespace mnml_woo_product_push;
/**
 * 
 * Settings & Logging
 * 
 */


add_action( 'rest_api_init', __NAMESPACE__ .'\register_settings_endpoint' );
function register_settings_endpoint() {
	register_rest_route( 'mnmlwooproductpush/v1', '/s', ['methods' => 'POST', 'callback' => __NAMESPACE__ .'\api_settings', 'permission_callback' => function(){ return current_user_can('import');} ] );
}


function api_settings( $request ) {
	$data = $request->get_body_params();
	foreach ( $data as $k => $v ) update_option( $k, $v, false );
	return "Saved";
}


add_action( 'admin_menu', __NAMESPACE__ .'\admin_menu' );
function admin_menu() {
	add_submenu_page( 'options-general.php', 'Woo to Woo Product Push', 'Woo > Woo', 'edit_users', 'mnmlwooproductpush', __NAMESPACE__ .'\settings_page', 99 );
}

function settings_page() {

	$url = rest_url('mnmlwooproductpush/v1/');
	$nonce = "x.setRequestHeader('X-WP-Nonce','". wp_create_nonce('wp_rest') ."')";
	?>
<div class=wrap>
	<h1>Woo to Woo Product Push</h1>
	<form onsubmit="event.preventDefault();var t=this,b=t.querySelector('.button-primary'),x=new XMLHttpRequest;x.open('POST','<?php echo $url.'s'; ?>'),<?php echo $nonce; ?>,x.onload=function(){b.innerText=JSON.parse(x.response);t.addEventListener('input',function(){b.innerText='Save Changes'})},x.send(new FormData(t))">
	<?php
	
	$main = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$main += array_fill_keys( [ 'api_key_'.$i, 'api_secret_'.$i, 'domain_'.$i ], ['type' => 'text'] );
	}

	$options = [ 'mnml_woo_product_push' => $main ];

	//*~*

	$values = [];
	foreach ( $options as $g => $fields ) {
		$values += get_option( $g, [] );
	}
	
	//TEMP migrate settings
	if (isset($values['api_key'])) {
		$values['api_key_1'] = $values['api_key'];
		$values['api_secret_1'] = $values['api_secret'];
		$values['domain_1'] = $values['domain'];
	}
	
	$script = '';
	echo '<table class=form-table>';
	foreach ( $options as $g => $fields ) {
		// $values = get_option($g);
		echo "<input type=hidden name='{$g}[x]' value=1>";// hidden field to make sure things still update if all options are empty (defaults)
		foreach ( $fields as $k => $f ) {
			if ( !empty( $f['before'] ) ) echo "<tr><th>" . $f['before'];
			$v = isset( $values[$k] ) ? $values[$k] : '';
			$l = isset( $f['label'] ) ? $f['label'] : str_replace( '_', ' ', $k );
			$size = !empty( $f['size'] ) ? $f['size'] : 'regular';
			$hide = '';
			if ( !empty( $f['show'] ) ) {
				if ( is_string( $f['show'] ) ) $f['show'] = [ $f['show'] => 'any' ];
				foreach( $f['show'] as $target => $cond ) {
					$hide = " style='display:none'";
					$script .= "\ndocument.querySelector('#tr-{$target}').addEventListener('change', function(e){";
					if ( $cond === 'any' ) {
						$script .= "if( e.target.checked !== false && e.target.value )";
						if ( !empty( $values[$target] ) ) $hide = "";
					}
					elseif ( $cond === 'empty' ) {
						$script .= "if( e.target.checked === false || !e.target.value )";
						if ( empty( $values[$target] ) ) $hide = "";
					}
					else {
						$script .= "if( !!~['". implode( "','", (array) $cond ) ."'].indexOf(e.target.value) && e.target.checked!==false)";
						if ( !empty( $values[$target] ) && in_array( $values[$target], (array) $cond ) ) $hide = "";
					}
					$script .= "{document.querySelector('#tr-{$k}').style.display='revert'}";
					$script .= "else{document.querySelector('#tr-{$k}').style.display='none'}";
					$script .= "});";
				}
			}
			if ( empty( $f['type'] ) ) $f['type'] = !empty( $f['options'] ) ? 'radio' : 'checkbox';// checkbox is default

			if ( $f['type'] === 'section' ) { echo "<tbody id='tr-{$k}' {$hide}>"; continue; }
			elseif ( $f['type'] === 'section_end' ) { echo "</tbody>"; continue; }
			else echo "<tr id=tr-{$k} {$hide}><th>";
			
			if ( !empty( $f['callback'] ) && function_exists( __NAMESPACE__ .'\\'. $f['callback'] ) ) {
				echo "<label for='{$g}-{$k}'>{$l}</label><td>";
				call_user_func( __NAMESPACE__ .'\\'. $f['callback'], $g, $k, $v, $f );
	        } else {
				switch ( $f['type'] ) {
					case 'textarea':
						echo "<label for='{$g}-{$k}'>{$l}</label><td><textarea id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' rows=8 class={$size}-text>{$v}</textarea>";
						break;
					case 'number':
						$size = !empty( $f['size'] ) ? $f['size'] : 'small';
						echo "<label for='{$g}-{$k}'>{$l}</label><td><input id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' value='{$v}' class={$size}-text type=number>";
						break;
					case 'radio':
						if ( !empty( $f['options'] ) && is_array( $f['options'] ) ) {
							echo "{$l}<td>";
							foreach ( $f['options'] as $ov => $ol ) {
								if ( ! is_string( $ov ) ) $ov = $ol;
								echo "<label><input name='{$g}[{$k}]' value='{$ov}'"; if ( $v == $ov ) echo " checked"; echo " type=radio>{$ol}</label> ";
							}
						}
						break;
					case 'select':
						if ( !empty( $f['options'] ) && is_array( $f['options'] ) ) {
							echo "<label for='{$g}-{$k}'>{$l}</label><td><select id='{$g}-{$k}' name='{$g}[{$k}]'>";
							echo "<option value=''></option>";// placeholder
							foreach ( $f['options'] as $key => $value ) {
								echo "<option value='{$key}'" . selected( $v, $key, false ) . ">{$value}</option>";
							}
							echo "</select>";
						}
						break;
					case 'text':
						echo "<label for='{$g}-{$k}'>{$l}</label><td><input id='{$g}-{$k}' name='{$g}[{$k}]' placeholder='' value='{$v}' class={$size}-text>";
						break;
					case 'checkbox':
					default:
						echo "<label for='{$g}-{$k}'>{$l}</label><td><input id='{$g}-{$k}' name='{$g}[{$k}]'"; if ( $v ) echo " checked"; echo " type=checkbox >";
						break;
				}
			}
			if ( !empty( $f['desc'] ) ) echo "&nbsp; " . $f['desc'];
		}
	}
	if ( $script ) echo "<script>$script</script>";
	echo '</table>';

	?><button class=button-primary>Save Changes</button>
	</form>
</div>
<?php
}


/* Debug */
function log( $var, $note='', $file='debug.log', $time='m-d H:i:s' ){
	if ( $note ) $note = "***{$note}***\n";
	ob_start();
	var_dump($var);
	$var = ob_get_clean();
	error_log("\n[". date($time) ."] ". $note . $var, 3, __DIR__ ."/". $file );
}