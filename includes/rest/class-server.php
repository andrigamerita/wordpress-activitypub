<?php
namespace Activitypub\Rest;

use WP_REST_Response;
use Activitypub\Signature;

/**
 * ActivityPub Server REST-Class
 *
 * @author Django Doucet
 *
 * @see https://www.w3.org/TR/activitypub/#security-verification
 */
class Server {

	/**
	 * Initialize the class, registering WordPress hooks
	 */
	public static function init() {
		\add_filter( 'rest_request_before_callbacks', array( self::class, 'authorize_activitypub_requests' ), 10, 3 );
		\add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register routes
	 */
	public static function register_routes() {
		\register_rest_route(
			'activitypub/1.0',
			'/application',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'application_actor' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Render Application actor profile
	 *
	 * @return WP_REST_Response The JSON profile of the Application Actor.
	 */
	public static function application_actor() {
		$json = new \stdClass();

		$json->{'@context'} = \Activitypub\get_context();
		$json->id = \get_rest_url( null, 'activitypub/1.0/application' );
		$json->type = 'Application';
		$json->preferredUsername = wp_parse_url( get_site_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.NamingConventions
		$json->name = get_bloginfo( 'name' );
		$json->summary = 'WordPress-ActivityPub application actor';
		$json->manuallyApprovesFollowers = true; // phpcs:ignore WordPress.NamingConventions
		$json->icon = array( get_site_icon_url() ); // phpcs:ignore WordPress.NamingConventions short array syntax
		$json->publicKey = (object) array( // phpcs:ignore WordPress.NamingConventions
			'id' => \get_rest_url( null, 'activitypub/1.0/application#main-key' ),
			'owner' => \get_rest_url( null, 'activitypub/1.0/application' ),
			'publicKeyPem' => Signature::get_public_key( -1 ), // phpcs:ignore WordPress.NamingConventions
		);

		$response = new WP_REST_Response( $json, 200 );

		$response->header( 'Content-Type', 'application/activity+json' );

		return $response;
	}

	/**
	 * Callback function to authorize each api requests
	 *
	 * @see \WP_REST_Request
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
	 *                                                                   Usually a WP_REST_Response or WP_Error.
	 * @param array                                            $handler  Route handler used for the request.
	 * @param WP_REST_Request                                  $request  Request used to generate the response.
	 *
	 * @return mixed|WP_Error The response, error, or modified response.
	 */
	public static function authorize_activitypub_requests( $response, $handler, $request ) {
		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/activitypub' ) ) {
			return $response;
		}

		if ( 'POST' === $request->get_method() ) {
			$verified_request = Signature::verify_http_signature( $request );
			if ( \is_wp_error( $verified_request ) ) {
				return $verified_request;
			}
		} else {
			if ( '/activitypub/1.0/webfinger' !== $route ) {
				// SecureMode/Authorized fetch.
				if ( \get_option( 'activitypub_use_secure_mode', '0' ) ) {
					$verified_request = Signature::verify_http_signature( $request );
					if ( \is_wp_error( $verified_request ) ) {
						return $verified_request;
					}
				}
			}
		}

		return $response;
	}
}
