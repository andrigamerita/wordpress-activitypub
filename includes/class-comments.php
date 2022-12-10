<?php
namespace Activitypub;

/**
 * Comments Class
 *
 * @author Django
 */
class Comments {
	/**
	 * Initialize the class, registering WordPress hooks.
	 */
	public static function init() {

		\add_filter( 'preprocess_comment', array( '\Activitypub\Comments', 'preprocess_comment' ) );
		\add_filter( 'comment_post', array( '\Activitypub\Comments', 'postprocess_comment' ), 10, 3 );
		\add_filter( 'wp_update_comment_data', array( '\Activitypub\Comments', 'comment_updated_published' ), 20, 3 );
		\add_action( 'transition_comment_status', array( '\Activitypub\Comments', 'schedule_comment_activity' ), 20, 3 );
		\add_action( 'edit_comment', array( '\Activitypub\Comments', 'edit_comment' ), 20, 2 );//schedule_admin_comment_activity
		\add_filter( 'get_comment_text', array( '\Activitypub\Comments', 'comment_append_edit_datetime' ), 10, 3 );

	}

	/**
	 * preprocess_comment()
	 * preprocess local comments for federated replies
	 */
	public static function preprocess_comment( $commentdata ) {
		// only process replies from local actors
		$user = \get_userdata( $commentdata['user_id'] );
		if ( $user->has_cap( 'publish_post' ) ) {
			// transform webfinger mentions to links and add @mentions to cc
			$tagged_content = \Activitypub\transform_tags( $commentdata['comment_content'] );
			$commentdata['comment_content'] = $tagged_content['content'];
			$commentdata['comment_meta']['mentions'] = $tagged_content['mentions'];
		}
		return $commentdata;
	}

	/**
	 * comment_post()
	 * postprocess_comment for federating replies and inbox-forwarding
	 */
	public static function postprocess_comment( $comment_id, $comment_approved, $commentdata ) {
		//Adminstrator role users comments bypass transition_comment_status (auto approved)
		$user = \get_userdata( $commentdata['user_id'] );
		if (
			( 1 === $comment_approved ) &&
			\in_array( 'administrator', $user->roles )
		) {
			// Only for Admins
			\wp_schedule_single_event( \time(), 'activitypub_send_comment_activity', array( $comment_id ) );
		}
	}

	/**
	 * edit_comment()
	 *
	 * Fires immediately after a comment is updated in the database.
	 * Fires immediately before comment status transition hooks are fired. (useful only for admin)
	 */
	public static function edit_comment( $comment_id, $data ) {
		if ( ! is_null( $data['user_id'] ) ) {
			\wp_schedule_single_event( \time(), 'activitypub_send_update_comment_activity', array( $comment_id ) );
		}
	}

	/**
	 * Schedule Activities
	 *
	 * transition_comment_status()
	 * @param int $comment
	 */
	public static function schedule_comment_activity( $new_status, $old_status, $activitypub_comment ) {
		if ( 'approved' === $new_status && 'approved' !== $old_status ) {
			//should only federate replies from local actors
			//should only federate replies to federated actors

			$ap_object = unserialize( \get_comment_meta( $activitypub_comment->comment_ID, 'ap_object', true ) );
			if ( empty( $ap_object ) ) {
				\wp_schedule_single_event( \time(), 'activitypub_send_comment_activity', array( $activitypub_comment->comment_ID ) );
			} else {
				$local_user = \get_author_posts_url( $ap_object['user_id'] );
				if ( ! is_null( $local_user ) ) {
					if ( in_array( $local_user, $ap_object['to'] )
						|| in_array( $local_user, $ap_object['cc'] )
						|| in_array( $local_user, $ap_object['audience'] )
						|| in_array( $local_user, $ap_object['tag'] )
						) {
						//if inReplyTo, object, target and/or tag are (local-wp) objects
						\wp_schedule_single_event( \time(), 'activitypub_inbox_forward_activity', array( $activitypub_comment->comment_ID ) );
					}
				}
			}
		} elseif ( 'trash' === $new_status ) {
			\wp_schedule_single_event( \time(), 'activitypub_send_delete_comment_activity', array( $activitypub_comment ) );
		} elseif ( $old_status === $new_status ) {
			//TODO Test with non-admin user
			\wp_schedule_single_event( \time(), 'activitypub_send_update_comment_activity', array( $activitypub_comment->comment_ID ) );
		}
	}

	/**
	 * get_comment_text( $comment )
	 *
	 * Filters the comment content before it is updated in the database.
	 */
	public static function comment_append_edit_datetime( $comment_text, $comment, $args ) {
		if ( 'activitypub' === $comment->comment_type ) {
			$updated = \wp_date( 'Y-m-d H:i:s', \strtotime( \get_comment_meta( $comment->comment_ID, 'ap_last_modified', true ) ) );
			if ( $updated ) {
				$append_updated = "<div>(Last edited on <time class='modified' datetime='{$updated}'>$updated</time>)</div>";
				$comment_text .= $append_updated;
			}
		}
		return $comment_text;
	}

}
