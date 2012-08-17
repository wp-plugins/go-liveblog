<?php
/**
 * Plugin name: GigaOM LiveBlog
 * Description: Provides live blogging functionality
 * Author: GigaOM Network
 * Author URI: http://gigaomnetwork.com/
 * Version: a1
 *
 * @author Matthew Batchelder <matt.batchelder@gigaom.com>
 * @author Casey Bisson       <casey@gigaom.com>
 * @author Vasken Hauri       <vasken@gigaom.com>
 * @author Oren Kredo         <oren.kredo@gigaom.com>
 * @author Bernadette Opine   <b@gigaom.com>
 * @author Jamie Poitra       <jamie.poitra@gigaom.com>
 * @author Zachary Tirrell    <zach.tirrell@gigaom.com>
 */

// include the core components
require_once dirname( __FILE__ ) .'/components/class-go-liveblog.php' ;
require_once dirname( __FILE__ ) .'/components/class-go-liveblog-media.php' ;

global $go_liveblog;
global $go_liveblog_media;

$go_liveblog       = GO_LiveBlog::get( __FILE__ );
$go_liveblog_media = GO_LiveBlog_Media::get();
