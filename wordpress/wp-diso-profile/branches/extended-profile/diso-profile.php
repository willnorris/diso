<?php
/*
Plugin Name: Extended Profile
Plugin URI: http://wordpress.org/extend/plugins/extended-profile/
Description: Detect and import hCard data on new user, extended data for user profiles, easy hCard generation.
Version: 0.50
Author: DiSo Development Team
Author URI: http://code.google.com/p/diso/
*/

require_once dirname(__FILE__).'/recent-visitors.php';
require_once dirname(__FILE__).'/profile-widget.php';
require_once dirname(__FILE__).'/permissions.php';
require_once dirname(__FILE__).'/avatar.php';

$hkit;


/**
 * Fetch hCard for the specified URL.
 *
 * @param string $url URL to get hCard from
 * @return array array containing the hCard object (key: 'hcard') as well as the raw XML (key: 'xml')
 */
function ext_profile_hcard_from_url($url) {
	global $hkit;
	require_once dirname(__FILE__).'/hkit.class.php';
	if(function_exists('tidy_clean_repair'))
		$page = wp_remote_fopen($url);
	else
		$page = wp_remote_fopen('http://cgi.w3.org/cgi-bin/tidy?forceXML=on&docAddr='.urlencode($url));
	if(function_exists('tidy_clean_repair'))
		$page = tidy_clear_repair($page);
	$page = str_replace('&nbsp;','&#160;',$page);
	if(!$hkit) $hkit = new hKit;
	@$hcard = $hkit->getByString('hcard', $page);
	if(count($hcard['preferred'])) {
		$phcard = $hcard['preferred'][0];
	} else {
		if($hcard['all']) {
			foreach($hcard['all'] as $card) {
				if($card['uid'] == $url) { $phcard = $card; break; }
				if(!is_array($card['url']) && $card['url'] == $url) { $phcard = $card; break; }
				if(is_array($card['url']) && in_array($url,$card['url'])) { $phcard = $card; break; }
			}//end foreach all
			if(!$phcard) $phcard = $hcard['all'][0];
		}//end if hcard all
	}//end if-else preferred
	return array('hcard' => $phcard, 'xml' => $hcard['xml']);
}


/**
 * Fetch the hCard from the URL specified in the user's profile.  Use the 
 * hCard profile data to update the user's local WordPress profile.
 *
 * @param int $userid ID of user to update
 * @param bool $override should local attributes be overwritten with data from 
 *                       hCard.  If false, only empty fields in the local 
 *                       profile will be updated from the hCard.
 */
function ext_profile_hcard_import($userid, $override=false) {
	$userdata = get_userdata($userid);

	//GET HCARD
	$data = ext_profile_hcard_from_url($userdata->user_url);
	if((!$data['hcard'] || !count($data['hcard'])) && $data['xml']) {//if no hcard, follow rel=me
		$relme = $data['xml']->xpath("//*[contains(concat(' ',normalize-space(@rel),' '),' me ')]");
		foreach($relme as $tag) {
			if(substr($tag['href'],0,4) != 'http') {
				$domain = explode('/',$url);
				$domain = $domain[2];
				if(substr($tag['href'],0,1) == '/')
					$tag['href'] = 'http://'.$domain.$tag['href'];
				else
					$tag['href'] = dirname($url).'/'.$tag['href'];
			}//end if not http
			$data = ext_profile_hcard_from_url($tag['href']);
			if($data['hcard'] && count($data['hcard'])) break;
		}//end foreach
	}//end if ! hcard

	$phcard = $data['hcard'];

	if(substr($phcard['photo'],0,3) == '://') {//photo relative URL
		$photo = substr($phcard['photo'],3);
		$domain = explode('/',$userdata->user_url);
		$domain = $domain[2];
		if($photo{0} == '/')
			$photo = 'http://'.$domain.$photo;
		else
			$photo = dirname($userdata->user_url).'/'.$photo;
		$phcard['photo'] = $photo;
	}//end if photo{0-3} == ://

	//IMPORT INTO PROFILE
	/* MAP KNOWN VALUES */
	if(!is_array($phcard['n'])) $phcard['n'] = array();
	if($phcard['fn']) update_usermeta($userid, 'display_name', $phcard['fn']);
	if($phcard['nickname']) update_usermeta($userid, 'nickname', $phcard['nickname']);
	if(($override || !$userdata->first_name) && $phcard['n']['given-name']) update_usermeta($userid, 'first_name', $phcard['n']['given-name']);
	if(($override || !$userdata->additional_name) && $phcard['n']['additional-name']) update_usermeta($userid, 'additional_name', $phcard['n']['additional-name']);
	if(($override || !$userdata->last_name) && $phcard['n']['family-name']) update_usermeta($userid, 'last_name', $phcard['n']['family-name']);
	if(($override || !$userdata->description) && $phcard['note']) update_usermeta($userid, 'description', $phcard['note']);
	if(($override || !$userdata->user_email) && $phcard['email']) update_usermeta($userid, 'user_email', $phcard['email']);
	/* MAP ALL OTHER HCARD VALUES TO THEMSELVES */
	$phcard['urls'] = $phcard['url']; unset($phcard['url']);
	foreach($phcard as $key => $val)
		if($key && $val && ($override || !$userdata->$key)) update_usermeta($userid, $key, $val);
}
add_action('user_register', 'ext_profile_hcard_import');


/**
 * Extend the WordPress profile page to include the additional fields.
 */
function ext_profile_fields() {
	global $profileuser;
	$userdata = $profileuser;

	//PHOTO
	echo '<h3>Photo</h3>';
	echo '<table class="form-table">';
		echo '	<tr><th><label for="photo">Photo URL</label></th><td>'; 
	if($userdata->photo)
		echo'<a href="'.$userdata->photo.'"><img src="'.$userdata->photo.'" alt="Avatar" class="photo" style="float: right; max-height: 200px" /></a>';
	echo '<input type="text" id="photo" name="hcard[photo]" value="'.htmlentities($userdata->photo).'" onchange="preview_hcard();" />';
	echo '</td></tr></table>';

	//ORGANIZATION AND ADDRESS
	$fieldset = array(
			'Miscellaneous' => array(
				'org' => 'Organization',
			),
			'Address' => array(
				'streetaddress' => 'Street Address',
				'locality' => 'City',
				'region' => 'Province/State',
				'postalcode' => 'Postal Code',
				'countryname' => 'Country',
				'tel' => 'Telephone Number',
			),
		);
	foreach($fieldset as $legend => $fields) {
		echo '<h3>'.$legend.'</h3>';
		echo '<table class="form-table">';
		if($legend == 'Miscellaneous') {
			echo '	<tr><th><label for="additional-name">Middle Name(s)</label></th> <td><input type="text" id="additional-name" name="additional-name" value="'.$userdata->n['additional-name'].'" onkeyup="preview_hcard();" /></td></tr>';
			if(!count($userdata->urls)) $userdata->urls = array($userdata->user_url);
			if(!is_array($userdata->urls) || !count($userdata->urls)) $userdata->urls = array($userdata->user_url);
			echo '	<tr><th><label for="urls">Website(s)<br />(one per line)</label></th> <td><textarea id="urls" name="urls" onkeyup="preview_hcard();">'.htmlentities(implode("\n",$userdata->urls)).'</textarea></td></tr>';
		}//end if Miscellaneous
		foreach($fields as $key => $label)
			echo '	<tr><th><label for="'.$key.'">'.$label.'</label></th> <td><input type="text" id="'.$key.'" name="hcard['.$key.']" value="'.$userdata->$key.'" onkeyup="preview_hcard();" /></td></tr>';
		echo '</table>';
	}//end foreach fieldset
?>

	<div id="profile_preview" style="display: none">
		<h1>Profile Preview</h1>
		<p>This is how your profile looks to people allowed to see all the information.</p>
		<hr />
		<div id="hcard-preview"></div>
	</div>

	<p><a id="profile_preview_link" href="#">Preview Profile</a></p>
	<a id="profile_thickbox" href="#TB_inline?height=600&width=800&inlineId=profile_preview" class="thickbox"></a>

	<script type="text/javascript">
		jQuery(function() {
			jQuery("#hcard_link").click(function() {
				jQuery("#do_manual_hcard").val("1");
				jQuery("input[type=submit]").click();
			});

			jQuery('#profile_preview_link').click(function() {
				preview_hcard();
				jQuery('#profile_thickbox').click();
				return false;
			});
		});

	</script>
<?php
}


/**
 * Include a link at the top of the WordPress profile page to import hCard data.
 */
function ext_profile_personal_options() {
	echo '<p><input type="hidden" id="do_manual_hcard" name="do_manual_hcard" /><a href="#" id="hcard_link">Import hCard</a></p>';
}


/**
 * Save extended profile attributes.
 *
 * @param int $userid ID of user
 */
function ext_profile_update($userid) {
	if($_POST['do_manual_hcard']) {
		ext_profile_hcard_import($userid, true);
	} else {
		$userdata = get_userdata($userid);
		$n = $userdata->n ? $userdata->n : array();
		$n['given-name'] = $_POST['first_name'];
		$n['additional-name'] = $_POST['additional-name'];
		$n['family-name'] = $_POST['last_name'];
		update_usermeta($userdata->ID, 'n', $n);
		$urls = preg_split('/[\s]+/',$_POST['urls']);
		update_usermeta($userdata->ID, 'urls', $urls);
		if (is_array($_POST['hcard'])) {
			foreach($_POST['hcard'] as $key => $val)
				update_usermeta($userdata->ID, $key, $val);
		}
		update_usermeta($userdata->ID, 'user_url', $_POST['url']);
		update_usermeta($userdata->ID, 'display_name', $_POST['display_name']);
	}
}


/**
 * Get the microformatted profile for the specified user.
 *
 * @param mixed $userid username or ID of user to get profile for.  If not 
 *                      specified, administrator user will be used.
 * @param bool $echo should profile be echo()'ed
 * @param bool $actionstream_aware should profile include actionstream URLs
 * @return string microformatted profile
 */
function extended_profile($userid='', $echo=true, $actionstream_aware=false) {

	// ensure plugin doesn't break in the absence of the permissions plugin
	if (!function_exists('diso_user_is')) { function diso_user_is() { return true; } }

	$time = microtime(true);
	if(!$userid) {//get administrator
		global $wpdb;
		$userid = $wpdb->get_var("SELECT user_id FROM $wpdb->usermeta WHERE meta_key='wp_user_level' AND meta_value='10'");
	}//end if ! userid
	if(is_numeric($userid))
		$userdata = get_userdata($userid);
	else
		$userdata = get_userdatabylogin($userid);
	$template = '<div class="vcard hcard-profile">';
	if($userdata->photo && diso_user_is($userdata->profile_permissions['photo'])) $template .= '<img class="photo" alt="photo" src="'.htmlentities($userdata->photo).'" />'."\n";
	$template .= '<h2 class="fn">'.htmlentities($userdata->display_name).'</h2>';
	if( $userdata->first_name || $userdata->additional-name || $userdata->last_name ) {
		if($userdata->user_url)
			$template .= '<a class="url uid" rel="me" href="'.htmlentities($userdata->user_url).'">';
		else
			$template .= '<span class="n">';
		if($userdata->last_name && diso_user_is($userdata->profile_permissions['family-name'])) $template .= '<span class="family-name">'.htmlentities($userdata->last_name).'</span>,'."\n";
		if($userdata->first_name && diso_user_is($userdata->profile_permissions['given-name'])) $template .= '<span class="given-name">'.htmlentities($userdata->first_name).'</span>'."\n";
		if($userdata->n['additional-name'] && diso_user_is($userdata->profile_permissions['additional-name'])) $template .= '<span class="additional-name">'.htmlentities($userdata->n['additional-name']).'</span>'."\n";
		if($userdata->user_url)
			$template .= '</a>';
		else
			$template .= '</span>';
	}//end if name
	if($userdata->nickname && diso_user_is($userdata->profile_permissions['nickname'])) $template .= '"<span class="nickname">'.htmlentities($userdata->nickname).'</span>"'."\n";
	if($userdata->org && diso_user_is($userdata->profile_permissions['org'])) $template .= '(<span class="org">'.htmlentities($userdata->org).'</span>)'."\n";
	if($userdata->description && diso_user_is($userdata->profile_permissions['note'])) $template .= '<p class="note">'.htmlentities($userdata->description).'</p>'."\n";

	$template .= '<h3>Contact Information</h3>';
	$template .= '<dl class="contact">';
	if(!count($userdata->urls)) $userdata->urls = array($userdata->user_url);
	if(count($userdata->urls) && diso_user_is($userdata->profile_permissions['urls'])) {
		$template .= '<dt>On the web:</dt> <dd> <ul>';
      if($actionstream_aware) $actionstream = actionstream_services($userdata->ID, true);
      foreach($userdata->urls as $url) {
         if($actionstream_aware && in_array($url,$actionstream)) continue;
         $template .= '<li><a class="url" rel="me" href="'.htmlentities($url).'">'.htmlentities(preg_replace('/^www\./','',preg_replace('/^http:\/\//','',$url))).'</a></li>';
      }//end foreach
		$template .= '</ul> </dd>'."\n";
	}//end if urls
	if($userdata->aim && diso_user_is($userdata->profile_permissions['aim'])) $template .= '<dt>AIM:</dt> <dd><a class="url" href="aim:goim?screenname='.htmlentities($userdata->aim).'">'.htmlentities($userdata->aim).'</a></dd>'."\n";
	if($userdata->yim && diso_user_is($userdata->profile_permissions['yim'])) $template .= '<dt>Y!IM:</dt> <dd><a class="url" href="ymsgr:sendIM?'.htmlentities($userdata->yim).'">'.htmlentities($userdata->yim).'</a></dd>'."\n";
	if($userdata->jabber && diso_user_is($userdata->profile_permissions['jabber'])) $template .= '<dt>Jabber:</dt> <dd><a class="url" href="xmpp:'.htmlentities($userdata->jabber).'">'.htmlentities($userdata->jabber).'</a></dd>'."\n";
	if($userdata->user_email && diso_user_is($userdata->profile_permissions['email'])) $template .= '<dt>Email:</dt> <dd><a class="email" href="mailto:'.htmlentities($userdata->user_email).'">'.htmlentities($userdata->user_email).'</a></dd>'."\n";
	if($userdata->tel && diso_user_is($userdata->profile_permissions['tel'])) $template .= '<dt>Telephone:</dt> <dd class="tel">'.htmlentities($userdata->tel).'</dd>'."\n";
	if( ($userdata->streetaddress || $userdata->locality || $userdata->region || $userdata->postalcode || $userdata->countryname)  &&
	 (diso_user_is($userdata->profile_permissions['street-address']) || diso_user_is($userdata->profile_permissions['locality']) || diso_user_is($userdata->profile_permissions['region']) || diso_user_is($userdata->profile_permissions['postal-code']) || diso_user_is($userdata->profile_permissions['country-name']) ) ) {
		$template .= '<dt>Current Address:</dt> <dd class="adr">';
		if($userdata->streetaddress && diso_user_is($userdata->profile_permissions['street-address'])) $template .= '<div class="street-address">'.htmlentities($userdata->streetaddress).'</div>'."\n";
		if($userdata->locality && diso_user_is($userdata->profile_permissions['locality'])) $template .= '<span class="locality">'.htmlentities($userdata->locality).'</span>,'."\n";
		if($userdata->region && diso_user_is($userdata->profile_permissions['region'])) $template .= '<span class="region">'.htmlentities($userdata->region).'</span>'."\n";
		if($userdata->postalcode && diso_user_is($userdata->profile_permissions['postal-code'])) $template .= '<div class="postal-code">'.htmlentities($userdata->postalcode).'</div>'."\n";
		if($userdata->countryname && diso_user_is($userdata->profile_permissions['country-name'])) $template .= '<div class="country-name">'.htmlentities($userdata->countryname).'</div>'."\n";
		$template .= '</dd>';
	}//end if adr
	$template .= '</dl>';
	$template .= '</div>';

	$template = apply_filters('ext_profile_template', $template);

	if($echo) {echo $template; echo '<!-- ext-profile time : '.(microtime(true)-$time).' seconds -->';}
	return $template;
}//end function extended_profile


/**
 * Include stylesheet for displaying profiles.
 */
function ext_profile_style() {
	wp_enqueue_style('ext-profile', plugins_url('extended-profile/profile.css'));
}
add_action('init', 'ext_profile_style');
add_action('wp_head', 'wp_print_styles', 9); // for pre-2.7


/**
 * Load the javascript necessary for the WordPress profile page.
 */
function ext_profile_admin_js() {
	add_thickbox();
	wp_enqueue_script('ext-profile', plugins_url('extended-profile/profile_preview.js'), array('thickbox'));
}

/**
 * Register WordPress admin hooks.
 */
function ext_profile_admin() {
	add_action('profile_update', 'ext_profile_update');
	add_action('profile_personal_options', 'ext_profile_personal_options');
	add_action('show_user_profile', 'ext_profile_fields');
	add_action('edit_user_profile', 'ext_profile_fields');

	add_action('load-profile.php', 'ext_profile_admin_js');
	add_action('load-user-edit.php', 'ext_profile_admin_js');
	add_action('admin_head-profile.php', 'ext_profile_style');
	add_action('admin_head-user-edit.php', 'ext_profile_style');
}
add_action('admin_init', 'ext_profile_admin');


/**
 * Handle the 'profile' shortcode.
 *
 * @param array $attr attributes passed to the shortcode
 * @param string $content content passed to the shortcode.  This should 
 *                        include the name or ID of the user to print the 
 *                        profile for.
 * @return string microformatted profile
 */
function ext_profile_shortcode($attr, $content) {
	return extended_profile($content, false);
}
add_shortcode('profile', 'ext_profile_shortcode');


/**
 * Provide value of 'country' SREG attribute.
 *
 * @param string $value current value for attribute
 * @param id $user_id ID of user to get attribute for
 * @return string new value for attribute
 */
function ext_profile_openid_sreg_country($value, $user_id) {
	$country = get_usermeta($user_id, 'countryname');
	return $country ? $country : $value;
}
add_filter('openid_server_sreg_country', 'ext_profile_openid_sreg_country', 10, 2);


/**
 * Provide value of 'postcode' SREG attribute.
 *
 * @param string $value current value for attribute
 * @param id $user_id ID of user to get attribute for
 * @return string new value for attribute
 */
function ext_profile_openid_sreg_postcode($value, $user_id) {
	$postcode = get_usermeta($user_id, 'postalcode');
	return $postcode ? $postcode : $value;
}
add_filter('openid_server_sreg_postcode', 'ext_profile_openid_sreg_postcode', 10, 2);
?>
