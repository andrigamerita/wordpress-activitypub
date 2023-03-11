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
		\add_filter( 'comment_excerpt', array( '\Activitypub\Comments', 'comment_excerpt' ), 10, 3 );
		\add_filter( 'comment_text', array( '\Activitypub\Comments', 'comment_content_filter' ), 10, 3 );
		\add_filter( 'comment_post', array( '\Activitypub\Comments', 'postprocess_comment' ), 10, 3 );
		\add_action( 'edit_comment', array( '\Activitypub\Comments', 'edit_comment' ), 20, 2 ); //schedule_admin_comment_activity
		\add_action( 'transition_comment_status', array( '\Activitypub\Comments', 'schedule_comment_activity' ), 20, 3 );
	}

	/**
	 * preprocess_comment()
	 * preprocess local comments for federated replies
	 */
	public static function preprocess_comment( $commentdata ) {
		// only process replies from authorized local actors, for ap enabled post types
		if ( ! isset( $commentdata['user_id'] ) ) {
			return $commentdata;
		}
		$user = \get_userdata( $commentdata['user_id'] );
		$comment_parent_post = \get_post_type( $commentdata['comment_post_ID'] );
		$post_types = \get_option( 'activitypub_support_post_types', array( 'post', 'page' ) );

		if ( $user->has_cap( 'publish_post', $commentdata['comment_post_ID'] ) && \in_array( $comment_parent_post, $post_types, true ) ) {
			$commentdata['comment_meta']['protocol'] = 'activitypub';
		}
		return $commentdata;
	}

	/**
	 * Filters the comment text to display webfinger in the Recently Published Dashboard Widget.
	 * comment_excerpt( $comment_excerpt, $comment_id )
	 *
	 * doesn't work on received webfinger links as get_comment_excerpt strips tags
	 * https://developer.wordpress.org/reference/functions/get_comment_excerpt/
	 * @param string $comment_text
	 * @param int $comment_id
	 * @param array $args
	 * @return void
	 */
	public static function comment_excerpt( $comment_excerpt, $comment_id ) {
		$comment = get_comment( $comment_id );
		$comment_excerpt = \apply_filters( 'the_content', $comment_excerpt, $comment );
		return $comment_excerpt;
	}

	/**
	 * comment_text( $comment )
	 * Filters the comment text for display.
	 *
	 * @param string $comment_text
	 * @param WP_Comment $comment
	 * @param array $args
	 * @return void
	 */
	public static function comment_content_filter( $comment_text, $comment, $args ) {
		$comment_text = \apply_filters( 'the_content', $comment_text, $comment );
		$protocol = \get_comment_meta( $comment->comment_ID, 'protocol', true );
		// TODO Test if this is returned by Model/Comment
		if ( 'activitypub' === $protocol ) {
			$updated = \get_comment_meta( $comment->comment_ID, 'ap_last_modified', true );
			if ( $updated ) {
				$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
				$formatted_datetime = \date_i18n( $format, \strtotime( $updated ) );
				$iso_date = \wp_date( 'c', \strtotime( $updated ) );
				$i18n_text = sprintf(
					/* translators: %s: Displays comment last modified date and time */
					__( '(Last edited on %s)', 'activitypub' ),
					"<time class='modified' datetime='{$iso_date}'>$formatted_datetime</time>"
				);
				$comment_text .= "<small>$i18n_text<small>";
			}
		}
		return $comment_text;
	}

	/**
	 * comment_post()
	 * postprocess_comment for federating replies and inbox-forwarding
	 */
	public static function postprocess_comment( $comment_id, $comment_approved, $commentdata ) {
		//Administrator role users comments bypass transition_comment_status (auto approved)
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
		update_comment_meta( $comment_id, 'ap_last_modified', \wp_date( 'Y-m-d H:i:s' ) );
		$user = \get_userdata( $data['user_id'] );
		if ( \in_array( 'administrator', $user->roles ) ) {
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

}
