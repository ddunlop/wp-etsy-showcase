<?php
/*
Plugin Name: Etsy Showcase
Plugin URI: 
Description: Allows for the use of Short codes to display etsy information
Version: 0.1a
Author: ddunlop
Author URI: http://ddunlop.com
License: GPL2
*/


/*  Copyright 2011  ddunlop  (email : etsy-showcase@ddunlop.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


class etsy_showcase {
	private $api_url = 'http://openapi.etsy.com/v2/sandbox/public/';
	private $api_key = false;
	private $api_cache_time = 10800; // default of 3 hours
	private $api_cache_dir = 'etsy-showcase';
	
	public function __construct() {
		// hook to add a link in the settings menu
		add_action('admin_menu', array($this, 'options'));
		
		// hook to the settings page
		add_action('admin_init', array($this, 'admin_init'));
		
		// hook to handle the shortcode
		add_shortcode('etsy_showcase', array($this, 'shortcode'));
		
		// Load the Options into the class
		$options = get_option('etsy_showcase_options');
		if(false === $options) {
			$options = array();
		}
		
		$keys = array('api_key', 'api_cache_time');
		foreach($keys as $key) {
			$this->{$key} = $this->arr_get($options, $key, $this->{$key});
//			$this->api_cache_time = $this->arr_get($options, 'api_cache_time', $this->api_cache_time);
		}
	}
	
	public function shortcode($attr) {
		$shop_id = $this->arr_get($attr, 'shop', false);
		$listing_id = $this->arr_get($attr, 'listing', false);
		if(false !== $shop_id) {
			return $this->get_etsy_shop($shop_id);
		}
		else if(false !== $listing_id) {
			return $this->get_etsy_listing($listing_id);
		}
	}
	
	private function get_etsy_shop($shop_id) {
		$shop = $this->etsy_api('shops/' . $shop_id . '/listings/active',
			array('includes' => 'MainImage')
		);
		if(false == $shop) {
			return 'non valid response';
		}

		$out = '';
		if($shop->count > 0) {
			$out .= $this->listings_view($shop->results);
		}
		
		return $out;
	}

	private function get_etsy_listing($listing_id) {
		$listing = $this->etsy_api('listings/' . $listing_id, array('includes'=>'MainImage'));
		$out = '';
		if($listing->count > 0) {
			$out .= $this->listings_view($listing->results);
		}
		
		return $out;
	}
	
	private function listings_view($listings) {
		$out = '<div class="etsy-showcase">';
		for($i = 0; $i < count($listings) ; $i++) {
			$class = false;
			if($i%3==0) {
				$class = 'clear';
			}
			$out .= $this->listing_view($listings[$i], $class);
		}
		return $out . '</div>';
	}

	private function listing_view($listing, $class = false) {
		return '<div class="etsy-showcase-thumb' . ($class !== false ? ' '.$class:'') . '"><a href="' . $listing->url . '"><img src="' . $listing->MainImage->url_170x135 . '" width="170" height="135">' . $listing->title . '</a></div>';
	}
	
	private function etsy_api($uri, $params) {
		if(false === $this->api_key) {
			return false;
		}
		$url = $uri;

		$params = array_merge( $params, array(
				'api_key' => $this->api_key,
			)
		);

		$encoded_params = array();
		foreach($params as $key => $value) {
			$encoded_params []= $key . '=' . urlencode($value);
		}
		if(count($encoded_params)>0) {
			$url .= '?' . implode('&', $encoded_params);
		}

		$cache_file = sys_get_temp_dir() . $this->api_cache_dir .'/' . sanitize_file_name( hash('md5', $url) . '.cache' );

		if(($data = $this->read_cache($cache_file)) === false) {
			$url = $this->api_url . $url;

			$get = wp_remote_get($url, $params);
			if(is_wp_error($get)) {
				return false;
			}
			if(200 != wp_remote_retrieve_response_code($get)) {
				return false;
			}
			$data = wp_remote_retrieve_body($get);
			$this->write_cache($cache_file, $data);
		}
		return json_decode($data);
	}
	
	private function clear_cache() {
		$dir = sys_get_temp_dir() . $this->api_cache_dir;
		if(is_dir($dir)) {
			$files = glob($dir . '/*');
			$results = array_unique(array_map('unlink', $files));

			if(count($results) > 0 && $results != array(true)) {
				return false;
			}
		}
		return true;
	}
	
	private function read_cache($file) {
		if(!file_exists($file) || (time() - filemtime($file) >= $this->api_cache_time)) {
			return false;
		}

		return file_get_contents($file);
	}
	
	private function write_cache($file, $data) {
		$dir = dirname($file);
		if(!file_exists($dir)) {
			mkdir($dir, 0755, true);
		}
		$tmp_file = $file . rand() . '.tmp';
		file_put_contents($tmp_file, $data);
		rename($tmp_file, $file);
	}
	
	private function arr_get($arr, $key, $default = false) {
		if(array_key_exists($key, $arr)) {
			return $arr[$key];
		}
		return $default;
	}
	
	public function options() {
		add_options_page(__('Etsy Showcase Options'), __('Etsy Showcase'), 'manage_options', basename(__FILE__), array($this,'options_page'));
	}

	public function admin_init() {
		register_setting( 'etsy_showcase_options', 'etsy_showcase_options', array($this,'options_validate') );
		add_settings_section('etsy_showcase', null, array($this, 'options_text'), 'etsy_showcase');
		add_settings_field('api_key', 'Your Etsy Api Key', array($this,'api_key_input'), 'etsy_showcase', 'etsy_showcase');
		add_settings_field('api_cache_time', 'How long to cache Etsy API responses for (in seconds)', array($this,'api_cache_time_input'), 'etsy_showcase', 'etsy_showcase');
		add_settings_field('clear_cache', 'Clear the Cache', array($this, 'clear_cache_input'), 'etsy_showcase', 'etsy_showcase');
	}
	
	public function options_validate($input) {
		$newinput['api_key'] = trim($input['api_key']);
		
		$input['api_cache_time'] = trim($input['api_cache_time']);
		if(ctype_digit($input['api_cache_time'])) {
			$newinput['api_cache_time'] = (int)$input['api_cache_time'];
		}
		else {
			$newinput['api_cache_time'] = $this->api_cache_time;
			add_settings_error('api_cache_time', 'api_cache_time_error', 'The cache time must be an integer', 'error');
		}

		if('Clear Cache' == $this->arr_get($_POST,'clear')) {
			if($this->clear_cache()) {
				add_settings_error('clear_cache', 'clear_cache_update', 'The cache has been cleared', 'updated');
			}
			else {
				add_settings_error('clear_cache', 'clear_cache_error', 'The cache was not able to be cleared, check the permissions', 'error');
			}
		}

		return $newinput;
	}
	
	public function options_text() {
	}
	
	public function api_key_input() {
		echo '<input id="api_key" name="etsy_showcase_options[api_key]" size="40" type="text" value="',$this->api_key,'">';
	}
	
	public function api_cache_time_input() {
		echo '<input id="api_cache_time" name="etsy_showcase_options[api_cache_time]" size="10" type="text" value="',$this->api_cache_time,'">';
	}
	
	public function clear_cache_input() {
		echo '<input name="clear" type="submit" value="', _e('Clear Cache'), '">';
	}
	
	public function options_page() {
		echo '<div class="wrap">';
		echo '<h2>Etsy Showcase Settings</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields('etsy_showcase_options');
		do_settings_sections('etsy_showcase');
		echo '<p class="submit"><input name="clear" type="submit" value="', _e('Save Changes'), '"></p>';
		echo '</form>';
		echo '</div>';
	}
}

$etsy_showcase = new etsy_showcase();