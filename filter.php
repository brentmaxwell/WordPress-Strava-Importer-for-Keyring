<?php
class StravaFilter {
	var $add_styles;

	public function __construct() {
		add_filter('strava', array($this, 'strava_post_filter'));
		add_action('wp_head', array($this, 'styles'));
	}

	public function strava_post_filter($post) {
		wp_enqueue_style('strava',plugins_url('styles.css',__FILE__));
		include('template.php');
	} // handler

	public function styles() {
		if ($this->add_styles) {
			
		}
	}
}

// Initialize short code
$tbStravaFilter = new StravaFilter();
