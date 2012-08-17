<?php

class GO_LiveBlog_Media {

	public $comment_type = 'go_liveblog';
	public $media_taxonomy_name = 'post_tag';

	public function __construct()
	{
 		add_action( 'add_attachment', array( $this, 'filter_attachment' ) );
		add_action( 'edit_attachment' , array( $this, 'filter_attachment' ) );
	
		add_action( 'delete_attachment', array( $this, 'delete_attachment_comment' ) );
	
		add_action( 'wp_insert_post', array( $this, 'save_post' ) );
	}//end constructor

	public function delete_attachment_comment( $attachment_id )
	{
		$comment_id = get_post_meta( $attachment_id, 'go_liveblog_comment', TRUE );
		
		if( $comment_id )
		{
			wp_delete_comment( $comment_id, true ); //second param forces delete instead of trashing
		}//end if
	}//end delete_attachment_comment

	public function filter_attachment( $attachment_id )
	{
		if( $parent_attachment_id = wp_is_post_revision( $attachment_id ) )
		{
			$attachment_id = $parent_attachment_id;
		}//end if

		$attachment = get_post( $attachment_id );

		if( $attachment->post_parent && $this->is_liveblog( $attachment->post_parent ) && wp_attachment_is_image( $attachment->ID ) )
		{
			//@TODO: we need to make sure we only do this on liveblogs
			$user = wp_get_current_user();
			
			$commentdata = array(
				'comment_post_ID' => $attachment->post_parent,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_content'      => $this->make_img_tag( $attachment->ID ),
				'comment_type'         => $this->comment_type,
				'comment_parent'       => 0,
				'user_id'              => $user->ID,
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', 1 ),
				'comment_approved'     => 1,
			);
			
			$comment_id = wp_insert_comment( $commentdata );

			update_post_meta( $attachment->ID, 'go_liveblog_comment', $comment_id );
		
		}//end if
	}//end filter_attachment

	public static function get() 
	{
		static $instance = null;

		if ( ! $instance )
		{
			$instance = new self;
		}//end if

		return $instance;
	}//end get

	public function is_liveblog( $post_id )
	{
		$slug_arr = wp_get_object_terms( $post_id, $this->media_taxonomy_name,  array( 'fields' => 'slugs'  ) ); 
		
		return in_array( 'liveblog', $slug_arr );
	}//end is_liveblog

	public function make_img_tag( $attachment_id )
	{
		return '<img src="' . wp_get_attachment_url( $attachment_id ) . '" width="600" />';
	}//end make_img_tag

	public function save_post( $post_id )
	{
		if( wp_is_post_revision( $post_id ) )
		{
			return;
		}//end if
		
		$post = get_post( $post_id );
		
		//@TODO: make this more portable by removing the tax dependency in here
		if( preg_match( '/.*\[go_liveblog.*\]/', $post->post_content ) )
		{
			wp_set_object_terms( $post_id, 'liveblog', $this->media_taxonomy_name, TRUE );
		}//end if
	}//end make_img_tag
}//end class
