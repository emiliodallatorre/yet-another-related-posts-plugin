<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if (have_posts()) {
	$output .= '<ol>';
	while (have_posts()) {
		the_post();
		
		$excerpt = strip_tags((string) get_the_excerpt());
		preg_replace('/([,;.-]+)\s*/','\1 ', $excerpt);
		$excerpt = implode(' ', array_slice(preg_split('/\s+/',$excerpt), 0, $excerpt_length)).'...';
		
		$output .= '<li><a href="'.get_permalink().'" rel="bookmark">'.get_the_title().'</a>';
//		$output .= ' ('.round(get_the_score(),3).')';
		$output .= '</li>';
		$output .= '<br>'.$excerpt;
	}
	$output .= '</ol>';
} else {
	$output .= '<p><em>'.__('No related posts.','yarpp').'</em></p>';
}
