<?php
namespace mnml_woo_product_push;
/*
Plugin Name: Woo to Woo Product Push
Plugin URI:  
Description: 
Version:     2023-10-05
Author:      Andrew J Klimek
Author URI:  https://github.com/andrewklimek/woo-product-push
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/


defined('ABSPATH') || exit;
require_once( __DIR__."/settings.php" );


add_action( 'rest_api_init', __NAMESPACE__ .'\register_api_endpoint' );
function register_api_endpoint() {
	register_rest_route( 'mnmlwooproductpush/v1', '/push', ['methods' => 'GET', 'callback' => __NAMESPACE__ .'\api_process', 
	'permission_callback' => function(){ return true;//current_user_can('import');
	} ] );
}

function api_process( $request ) {
	// ini_set('display_errors', '1');
	if ( empty( $request['id'] ) ) return;

	$settings = get_option( 'mnml_woo_product_push', [] );

	for ( $i = 1; $i <= 4; $i++ ) {
	if ( !empty( $settings['domain_'.$i] ) && !empty( $settings['api_secret_'.$i] ) && !empty( $settings['api_key_'.$i] ) )
		push_item( $request['id'], $settings['domain_'.$i], $settings['api_key_'.$i] .":". $settings['api_secret_'.$i] );
	}

}

function push_item( $pid, $domain, $usrpwd ) {

	$domain = strpos( $domain, "//" ) ? $domain : "https://" . $domain;
	$pid = (int) $pid;

	$controller = new \WC_REST_Products_Controller();
	$product = $controller->get_item( [ 'id' => $pid ] );
	$product = $product->data;
	// $pid = $product['id'];
	// $product = json_decode( json_encode( $product ), true );
	$product = remove_ids_etc( $product );


	// get weight units
	$weight_unit = null;
	$weight_result = rest( $domain, $usrpwd, "settings/products/woocommerce_weight_unit" );
	if ( !empty( $weight_result->value ) ) {
		$weight_unit = $weight_result->value;
	} else {
		log( $weight_result );
	}
	if ( $weight_unit ) $product['weight'] = number_format( wc_get_weight( $product['weight'], $weight_unit ), 2 );
	
	$result = rest(  $domain, $usrpwd, "products", $product );
	log( "product results" );
	log($result);

	if ( !empty( $result->id ) ) {
		$new_pid = $result->id;

		// do variations
		if ( !empty( $product['variations'] ) ) {
			$variations = [];
			foreach( $product['variations'] as $vid ) {
				$controller = new \WC_REST_Product_Variations_Controller();
				$variation = $controller->get_item( ['id' => $vid, 'product_id' => $pid, 'context' => 'view' ] );
				$variation = $variation->data;
				$variation = remove_ids_etc( $variation );
				if ( $weight_unit ) $variation['weight'] = wc_get_weight( $variation['weight'], $weight_unit );
				if ( $variation['sku'] === $product['sku'] ) unset( $variation['sku'] );
		
				log( "VARIATION $vid" );
				log( $variation );
				$result = rest( $domain, $usrpwd, "products/{$new_pid}/variations", $variation );
				log( "VARIATION results" );
				log($result);
			}
		}

		return $new_pid;
	}
	// TODO this should be in the api_process function
	// else {
	// 	$response = new \WP_REST_Response( $result->message );
	// 	$response->set_status( 418 );
	// 	return $response;
	// }
}

function remove_ids_etc( $data ) {
	$new = [];
	foreach( $data as $i => $v ) {
		// || $i == "permalink" || substr($i, 0,8) == "date_cre" || substr($i,0,8) == "date_mod"
		if ( $i == "id" || substr($i,-3) == "_id" || substr($i,-4) == "_ids" ) {
			continue;
		}
		$new[ $i ] = is_array($v) ? remove_ids_etc($v) : $v;
	}
	return $new;
}

/**
 * product page
 */
add_action( 'woocommerce_product_options_general_product_data', __NAMESPACE__ . '\per_product_settings', 11 );

function per_product_settings(){
	global $product_object, $thepostid;

	$settings = get_option( 'mnml_woo_product_push', [] );
	if ( empty( $settings['api_secret_1'] ) ) return;

	// $url = untrailingslashit( $settings['domain'] );

	// $domain = explode( '//', $url )[1];

	echo "<div style='padding:12px;text-align:right;border-top:1px solid #eee'>";

	if ( $product_object->get_status() !== "publish" ) {
		echo "<button class=button type=button style='opacity:.3' onclick=\"this.textContent='Please publish this WordPress product first';this.style='opacity:.7'\">Push to other shops</button>";
	} else {
		// $link = "{$url}/wp-admin/post.php?action=edit&post=";
		// echo "<a href='{$link}' target='_blank' hidden>Edit on {$domain}</a> ";

		$endpoint = rest_url( 'mnmlwooproductpush/v1/push?id=' . $thepostid );
		$nonce = "x.setRequestHeader('X-WP-Nonce','". wp_create_nonce('wp_rest') ."')";
		// $js = "var t=this,l=t.parentElement.querySelector('a'),x=new XMLHttpRequest;t.disabled=1;t.textContent='working...';x.open('GET','{$endpoint}'),{$nonce},"
			// . "x.onload=function(){if(x.status==200){l.href+=x.response;l.hidden=0;t.textContent='done'}else{t.textContent=x.response}},x.send()";
		$js = "var t=this,x=new XMLHttpRequest;t.disabled=1;t.textContent='working...';x.open('GET','{$endpoint}'),{$nonce},"
			. "x.onload=function(){if(x.status==200){t.textContent='done'}else{t.textContent=x.response}},x.send()";
		echo "<button class=button type=button onclick=\"{$js}\">Push to other shops</button>";

	}

	echo "</div>";
}



function rest( $domain, $usrpwd, $endpoint='', $data=null, $method='' ) {

	$ch = curl_init();

	curl_setopt_array($ch, [
		CURLOPT_URL => untrailingslashit( $domain ) . '/wp-json/wc/v3/' . $endpoint,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		// CURLOPT_POST => true,
		CURLOPT_USERPWD => $usrpwd,
		CURLOPT_HTTPHEADER => [ "Content-Type: application/json" ],
		// CURLOPT_POSTFIELDS => json_encode( $data ),
	]);

	if ( ! $method ) $method = is_array( $data ) ? 'POST' : 'GET';
	if( $method === "POST" ) {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
	}

	$result = curl_exec($ch);

	if ( $result === false ) {
		log( "curl error: " . curl_errno($ch) ."  ". curl_error($ch) );
		curl_close($ch);
		return false;
	}

	$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	
	curl_close($ch);

	$result = json_decode($result);
	
	// if ( $code >= 300 ) {
	// 	log( "error code $code" );
	// 	if(isset($result->errors)) log( $result->errors );
	// 	return false;
	// }

	// log($result);

	return $result;
}


/**
 * Bulk Action
 */
add_filter( 'bulk_actions-edit-product', __NAMESPACE__.'\register_bulk_action' );
function register_bulk_action($bulk_actions) {
	$bulk_actions['mnml_woo_product_push'] = "Push to Woo Shops";
	return $bulk_actions;
}

add_filter( 'handle_bulk_actions-edit-product', __NAMESPACE__.'\handle_bulk_action', 10, 3 ); 
function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
	if ( $doaction !== 'mnml_woo_product_push' ) return $redirect_to;
	
	$settings = get_option( 'mnml_woo_product_push', [] );
	
	for ( $i = 1; $i <= 4; $i++ ) {	
		if ( empty( $settings['domain_'.$i] ) || empty( $settings['api_secret_'.$i] ) || empty( $settings['api_key_'.$i] ) ) continue;
		foreach ( $post_ids as $post_id ) {
			push_item( $post_id, $settings['domain_'.$i], $settings['api_key_'.$i] .":". $settings['api_secret_'.$i] );
		}
	}
	$redirect_to = add_query_arg( 'products_pushed', count( $post_ids ), $redirect_to );
	return $redirect_to;
}

add_action( 'admin_notices', __NAMESPACE__.'\bulk_action_admin_notice' );
function bulk_action_admin_notice() {
	if ( empty( $_GET['products_pushed'] ) ) return;
	echo '<div class="updated fade"><p>Pushed ' . intval( $_GET['products_pushed'] ) . ' products.</div>';
	$_SERVER['REQUEST_URI'] = remove_query_arg( 'products_pushed', $_SERVER['REQUEST_URI'] );
	unset( $_GET['products_pushed'] );
}