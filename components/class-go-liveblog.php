<?php

class GO_LiveBlog {

	public $bootstrap_file = null;
	public $plugin_dir     = null;
	public $plugin_url     = null;

	private $batch_max     = 21;
	private $date_start    = null;
	private $date_end      = null;
	private $refresh       = null;
	private $url           = null;
	private $comment_type  = 'go_liveblog';
	private $ep_name       = 'liveblog';

	/**
	 * Initialize the plugin and register hoosk.
	 */
	public function __construct( $bootstrap_file )
	{
		$this->bootstrap_file = $bootstrap_file;
		$this->plugin_url = plugins_url( '', $this->bootstrap_file );
		$this->plugin_dir = plugin_dir_path( $this->bootstrap_file );

		add_shortcode( 'go_liveblog', array( $this, 'shortcode' ) );

		add_action( 'init', array( $this, 'init' ) );
		//add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		if( is_admin() )
		{
			add_action( 'wp_ajax_go_liveblog_insert', array( $this, 'liveblog_insert' ) );
		} // end if

		// admin ajax methodology is not cache-able, so not preferred and commented out
		
		//add_filter( 'wp_ajax_go_liveblog', array( &$this, 'admin_ajax_get_json' ) );
		//add_filter( 'wp_ajax_nopriv_go_liveblog', array( &$this, 'admin_ajax_get_json' ) );
	}//end constructor

	public function add_query_var( $qvars )
	{
		$qvars[] = $this->ep_name;
		return $qvars;
	}//end add_query_var

	// this function is temporary until the rewrite goodness is in place
	/*
	public function admin_ajax_get_json() {
		$post_id = $_GET['post_id'];
		$comment_id = $_GET['comment_id'];
		echo $this->get_json( $post_id, $comment_id );
		die;
	}//end admin_ajax_get_json
	*/

	/**
	 * This method prepares the handlebars template for PHP templating
	 */
	private function clean_handlebars_comment_template( $template ) 
	{
		// strip off the junk from the template
		$template = preg_replace(
			array(
				'/\n/', /* get rid of carriage returns */
				'/>[\t\s]*</', /* get rid of spaces */
			),
			array(
				'',
				'><',
			),
			$template
		);
		
		$template = preg_replace( '/^.*\{\{#each updates\}\}(.*)\{\{\/each\}\}.*$/', '\1', $template );

		return $template;
	}//end clean_handlebars_template

	/**
	 * function to enqueue the necessary scripts to enable liveblogging
	 */
	public function enqueue_scripts()
	{
		wp_register_style( 'go-liveblog', $this->plugin_url . '/components/css/go-liveblog.css' );
		wp_enqueue_script( 'handlebars', $this->plugin_url . '/components/js/handlebars.js', array('jquery'), 1, TRUE );
		wp_enqueue_script( 'jquery-dotimeout', $this->plugin_url . '/components/js/jquery.ba-dotimeout.min.js', array('jquery'), 1, TRUE );
		wp_enqueue_script( 'go-liveblog-controller', $this->plugin_url . '/components/js/go-liveblog-controller.js', array(
			'jquery',
			'jquery-dotimeout',
		), 1, TRUE );
		wp_enqueue_script( 'go-liveblog-behavior', $this->plugin_url . '/components/js/go-liveblog-behavior.js', array('go-liveblog-controller'), 1, TRUE );

		wp_enqueue_style( 'go-liveblog' );
	}//end enqueue_scripts

	public function get_comments( $args, $default_params = array() ) 
	{
		$comments = get_comments( $args );

		foreach( $comments as &$comment )
		{
			$comment->comment_id = $comment->comment_ID;
			$comment->post_id = $comment->comment_post_ID;
			$comment->author_id = $comment->user_id;

			unset( 
				  $comment->comment_ID
				, $comment->comment_post_ID
			);

			if ( ! $comment->date_gmt )
			{
				$comment->date_gmt = strtotime( $comment->comment_date_gmt );
			}//end if

			if ( ! $comment->formatted_time )
			{
				$comment->formatted_time = date( 'h:ia', strtotime( $comment->comment_date_gmt ) + ( $default_params['gmt_offset'] * 3600 ) );
			}//end if

			$comment->comment_content = wpautop( wptexturize( $comment->comment_content ) );
			$comment->share_url = get_permalink( $comment->post_id ) . '#go-liveblog-' . $comment->comment_id;
		}//end foreach

		return $comments;
	}//end get_comments

	public function get_field_id( $field_name )
	{
		return $this->comment_type . '_' . $field_name;
	}//end get_field_id

	public function get_field_name( $field_name )
	{
		return $this->comment_type . '[' . $field_name . ']';
	}//end get_field_name

	public function get_handlebars_comment_template() 
	{
		static $template;

		if ( ! $template ) {
			ob_start();
			require_once $this->plugin_dir . 'components/templates/handlebars-comment.php';
			$template = ob_get_clean();
		}//end if

		return $template;
	}//end get_handlebars_comment_template

	public function get_handlebars_more() 
	{
		global $post;
		static $template;

		if ( ! $template ) {
			ob_start();
			$url = get_permalink( $post->ID );
			require_once $this->plugin_dir . 'components/templates/handlebars-more.php';
			$template = ob_get_clean();
		}//end if

		return $template;
	}//end get_handlebars_more

	/**
	 * Function inserts liveblog custom comment to WP
	 * @return array $updates Most recent 20 updates including the one just inserted
	 */
	public function get_json( $post_id = FALSE, $comment_id = FALSE )
	{
		$post_id = ( $post_id ) ? $post_id : $_GET['post_id'];
		$comment_id = ( $comment_id ) ? $comment_id : $_GET['comment_id'];

		$post_id = absint( $post_id );
		$comment_id = absint( $comment_id );

		$GLOBALS['go_liveblog_comment_id'] = $comment_id;

		$args = array(
			'post_id' => $post_id,
			'type'    => $this->comment_type,
			'number'  => $this->batch_max,
			'order' => 'DESC',
		);

		$arr = array();

		add_filter( 'comments_clauses', array( 'GO_LiveBlog', 'filter_liveblog' ) );
		$arr['updates'] = $this->get_comments( $args );
		remove_filter( 'comments_clauses', array( 'GO_LiveBlog', 'filter_liveblog' ) );

		// if we got back {$this->batch_max} elements, there are more than 20 to fetch, so let the client know
		if( $this->batch_max === count( $arr['updates'] ) )
		{
			array_pop( $arr['updates'] );
			$arr[ 'incomplete' ] = true;
		} // end if

		$arr = apply_filters( 'go_liveblog_arr', $arr );
		return json_encode( $arr );
	} // end get_json

	public static function filter_liveblog( $clauses )
	{
		$clauses['where'] .= ' AND comment_ID > '.$GLOBALS['go_liveblog_comment_id'];
		$clauses['fields'] = '
				comment_ID
			, comment_post_ID
			, user_id
			, comment_author
			, comment_content
			, UNIX_TIMESTAMP(comment_date_gmt) AS date_gmt
			, DATE_FORMAT( comment_date_gmt, \'%h:%i %p\' ) AS formatted_time
		';
		return $clauses;
	} // end filter_liveblog

	public static function get( $file ) 
	{
		static $instance = null;

		if ( ! $instance ) 
		{
			$instance = new self( $file );
		}//end if

		return $instance;
	}//end get

	public function init()
	{
		add_rewrite_endpoint( $this->ep_name, EP_ALL );
		add_filter( 'request' , array( $this, 'request' ) );
	}//end init

	public function liveblog_form( $comments )
	{
		global $post;

		if ( ! current_user_can( 'edit_posts', $post->ID ) )
		{
			return false;
		}//end if
		
		$current_user = wp_get_current_user();
		?>
			<div id="liveblog-respond">
				<form id='commentform' action="<?php echo admin_url();?>admin-ajax.php?action=go_liveblog_insert" method="post" class="validate clearfix">
					<div id="comment-textarea" class="clearfix">
						<label for="<?php echo $this->get_field_name( 'comment' ); ?>"><span id="social-identity-pic"><?php echo get_avatar( $current_user->user_email, 25 ); ?></span> Welcome <?php echo $current_user->display_name; ?>, please enter text.</label>
						<div class="error"></div>
						<textarea title="Welcome <?php echo $current_user->display_name; ?>, please enter text." name="<?php echo $this->get_field_name( 'comment' ); ?>" id="<?php echo $this->get_field_id( 'comment' );?>" rows="3" cols="40" tabindex="5" class="validate valid-required"></textarea>
						<input type="hidden" name="action" value="go_liveblog_insert" />
						<input type="hidden" name="post_id" id="post_id" value="<?php echo $post->ID; ?>" /> 
						<input type="hidden" name="comment_id" id="comment_id" value="" />
						<?php $this->nonce_field(); ?>
						<div class="throbber"></div>
						<input name="liveblog_submit" type="submit" class="secondary-button" value="<?php esc_attr_e('Add Update', 'gigaom'); ?>" tabindex="5" />
					</div>
				</form>
			</div>
		<?php
	}//end liveblog_form

	/**
	 * Function inserts liveblog custom comment to WP
	 * @return array $updates Most recent 20 updates including the one just inserted
	 */
	public function liveblog_insert()
	{
		$post_id = absint( $_POST['post_id'] );

		// don't allow blank comments
		if ( '' == trim( $_POST['comment'] ) )
		{
			die;
		}//end if

		if ( ! current_user_can( 'edit_post', $post_id ) )
		{
			die;
		}//end if

		if( ! $this->verify_nonce() )
		{
			die;
		}//end if

		$recent_comment_id = absint( $_POST['comment_id'] );
		$post_id = absint( $_POST['post_id'] );

		$user = wp_get_current_user();;

		$liveblog = array(
			'comment_post_ID'      => $post_id,
			'comment_author'       => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_url'   => $user->user_email,
			'comment_content'      => wp_filter_post_kses( $_POST['comment'] ),
			'comment_type'         => $this->comment_type,
			'comment_parent'       => 0,
			'user_id'              => $user->ID,
			'comment_date'         => current_time( 'mysql' ),
			'comment_date_gmt'     => current_time( 'mysql', 1 ),
			'comment_approved'     => 1,
		);

		if ( $comment_id = wp_insert_comment( $liveblog ) ) 
		{
			echo $this->get_json( $post_id, $recent_comment_id );
		}//end if

		die;
	}//end liveblog_insert

	public function liveblog_output()
	{
		global $post;

		$start = $this->date_start_object;
		$end = $this->date_end_object;

		$start->setTimezone( new DateTimeZone( 'GMT' ) );
		$end->setTimezone( new DateTimeZone( 'GMT' ) );

		$now = gmmktime();

		$not_started = $now < $start->getTimestamp();
		$ended = $now > $end->getTimestamp();

		//get comments
		$args = array(
			'post_id' => $post->ID,
			'type'    => $this->comment_type,
			'order' => $ended ? 'ASC' : 'DESC',
		);
		//get initial comments
		$comments = $this->get_comments( $args, $default_params );

		if ( $ended )
		{
			add_action( 'go-liveblog-tool', function() {
				?><span class="go-liveblog-tool go-liveblog-ended">This event is now finished.</span><?php
			});
		}//end if
		else
		{
			add_action( 'go-liveblog-tool', array( $this, 'stream_controls' ) );

			//output liveblog form
			$this->liveblog_form( $comments );
		}//end else

		//output stream toolbar
		$this->stream_tools();

		ob_start();

		$default_params = array(
			'start' => $start->getTimestamp(),
			'end' => $end->getTimestamp(),
			'start_time_formatted' => $this->date_start_object->format('h:ia'),
			'end_time_formatted' => $this->date_end_object->format('h:ia'),
			'refresh' => $this->refresh,
			'gmt_offset' => get_option('gmt_offset'),
		);
		?>
		<script type="text/javascript">
			var go_liveblog = <?php echo json_encode( $default_params ); ?>
		</script>
		<ul class="go-liveblog">
			<?php

			if ( $not_started && ! $comments) 
			{
				?>
				<li class="not-started">
					<div class="go-liveblog-meta">
						No updates yet
					</div>
					<p>
						The event goes live at <?php echo $this->date_start_object->format('h:ia'); ?> Pacific Standard Time.
						Stay tuned for up to the minute updates.
					</p>
				</li>
				<?php
			}//end if

			?>
			<li class="cached">
				<a class="message" href="#" title="Updates Available">
					<span class="number">0</span> new update<span class="plural">s</span> available
				</a>
				<ul class="updates">
				</ul>
			</li>
		<?php
			$template = $this->get_handlebars_comment_template();
			$template = $this->clean_handlebars_comment_template( $template );

			foreach ( $comments as $field => $content )
			{
				echo str_replace(
					array(
						'{{comment_id}}',
						'{{author_id}}',
						'{{comment_author}}',
						'{{date_gmt}}',
						'{{formatted_time}}',
						'{{{comment_content}}}',
						'{{share_url}}',
					),
					array(
						$content->comment_id,
						$content->user_id,
						$content->comment_author,
						$content->date_gmt,
						$content->formatted_time,
						$content->comment_content,
						$content->share_url,
					),
					$template
				);
			}
		?>
		</ul>
		<?php
		$buffered_content = ob_get_clean();
		return $buffered_content;
	}//end liveblog_output

	public function nonce_field()
	{
		wp_nonce_field( plugin_basename( __FILE__ ) , $this->comment_type .'_nonce' );
	}//end nonce_field

	public function request( $request )
	{
		if( isset( $request[ $this->ep_name ] ) )
		{
			add_filter( 'template_redirect' , array( $this, 'redirect' ), 0 );
		}//end if

		return $request;
	}//end request

	/**
	 * WordPress shortcode handler.  Populate class variables with
	 * attributes
	 * @param array $atts
	 * @return string
	 */
	public function shortcode($atts)
	{
		global $post;

		//enqueue the relevant javascripts
		$this->enqueue_scripts();

		extract(shortcode_atts(array(
			'date_start' => '',
			'date_end'   => '',
			'refresh'    => 15,
		), $atts));

		$this->date_start = $date_start;
		$this->date_end   = $date_end;
		$this->refresh    = $refresh;

		if ( empty( $date_start ) )
		{
			$this->date_start = $post->post_date;
		}//end if

		$this->date_start_object = new DateTime( $this->date_start, new DateTimeZone( 'America/Los_Angeles' ) );

		if( empty( $date_end ) )
		{
			$this->date_end = $this->date_start;
		}//end if

		$this->date_end_object = new DateTime( $this->date_end, new DateTimeZone( 'America/Los_Angeles' ) );
		// TODO: figure out why we are adding 6 hours to the date end object
		$this->date_end_object->add( new DateInterval( 'PT6H' ) );

		$this->render_handlebars_comment_template();
		$this->render_handlebars_more();
		
		wp_localize_script( 'go-liveblog-controller', 'go_liveblog', array( 'ep_url' => get_permalink().$this->epname.'/', 'ajax_url' => admin_url( 'admin-ajax.php' ) ) );

		//create jSON object.
		return $this->liveblog_output();
	}//end shortcode

	public function stream_tools()
	{
		?>
		<div class="liveblog-tools">
			<?php do_action( 'go-liveblog-tool' ); ?>
		</div>
		<?php
	}//end stream_tools

	public function stream_controls()
	{
		?>
		<ul class="liveblog-controls">
			<li class="pause-play">
				<a href="#pause_play" title="Pause Stream"><span class="text">Pause Stream</span><span class="toggle">Pause Stream</span></a>
			</li>
		</ul>
		<?php
	}//end stream_controls

	public function verify_nonce()
	{
		return wp_verify_nonce( $_POST[ $this->comment_type .'_nonce' ] , plugin_basename( __FILE__ ));
	}//end verify_nonce

	public function redirect()
	{
		$post_id = get_queried_object_id();
		$comment_id = get_query_var( $this->ep_name );
		echo $this->get_json( $post_id, $comment_id );
		die;
	} // end redirect

	/**
	 * output the handlebars template for the comment loop so we can use it
	 * to add comments retrieved via ajax
	 */
	public function render_handlebars_comment_template()
	{
		echo $this->get_handlebars_comment_template();
	}//end render_handlebars_comment_template

	/**
	 * output the handlebars template for the "more" link
	 */
	public function render_handlebars_more()
	{
		echo $this->get_handlebars_more();
	}//end render_handlebars_more
}//end class
