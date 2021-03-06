<?php
/**
 * GitHub Updater
 *
 * @author    Andy Fragen, Mikael Lindqvist
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 * @package   github-updater
 */

namespace Fragen\GitHub_Updater;

use Fragen\Singleton;
use Fragen\GitHub_Updater\Traits\GHU_Trait;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Class Rest_Update
 *
 * Updates a single plugin or theme, in a way suitable for rest requests.
 * This class inherits from Base in order to be able to call the
 * set_defaults function.
 */
class Rest_Update {
	use GHU_Trait;

	/**
	 * Holds REST Upgrader Skin.
	 *
	 * @var Rest_Upgrader_Skin $upgrader_skin
	 */
	protected $upgrader_skin;

	/**
	 * Holds sanitized $_REQUEST.
	 *
	 * @var array
	 */
	protected static $request;

	/**
	 * Holds regex pattern for version number.
	 * Allows for leading 'v'.
	 *
	 * @var string
	 */
	protected static $version_number_regex = '@(?:v)?[0-9\.]+@i';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_options();
		$this->upgrader_skin = new Rest_Upgrader_Skin();
		self::$request       = $this->sanitize( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Update plugin.
	 *
	 * @param string $plugin_slug
	 * @param string $tag
	 *
	 * @throws \UnexpectedValueException Plugin not found or not updatable.
	 */
	public function update_plugin( $plugin_slug, $tag = 'master' ) {
		$plugin           = null;
		$is_plugin_active = false;

		foreach ( (array) Singleton::get_instance( 'Plugin', $this )->get_plugin_configs() as $config_entry ) {
			if ( $config_entry->slug === $plugin_slug ) {
				$plugin = $config_entry;
				break;
			}
		}

		if ( ! $plugin ) {
			throw new \UnexpectedValueException( 'Plugin not found or not updatable with GitHub Updater: ' . $plugin_slug );
		}

		if ( is_plugin_active( $plugin->file ) ) {
			$is_plugin_active = true;
		}

		Singleton::get_instance( 'Base', $this )->get_remote_repo_meta( $plugin );
		$repo_api = Singleton::get_instance( 'API', $this )->get_repo_api( $plugin->git, $plugin );

		$update = [
			'slug'        => $plugin->slug,
			'plugin'      => $plugin->file,
			'new_version' => null,
			'url'         => $plugin->uri,
			'package'     => $repo_api->construct_download_link( $tag ),
		];

		add_filter(
			'site_transient_update_plugins',
			function ( $current ) use ( $plugin, $update ) {
				$current->response[ $plugin->file ] = (object) $update;

				return $current;
			}
		);

		$upgrader = new \Plugin_Upgrader( $this->upgrader_skin );
		$upgrader->upgrade( $plugin->file );

		if ( $is_plugin_active ) {
			$activate = is_multisite() ? activate_plugin( $plugin->file, null, true ) : activate_plugin( $plugin->file );
			if ( ! $activate ) {
				$this->upgrader_skin->messages[] = 'Plugin reactivated successfully.';
			}
		}
	}

	/**
	 * Update a single theme.
	 *
	 * @param string $theme_slug
	 * @param string $tag
	 *
	 * @throws \UnexpectedValueException Theme not found or not updatable.
	 */
	public function update_theme( $theme_slug, $tag = 'master' ) {
		$theme = null;

		foreach ( (array) Singleton::get_instance( 'Theme', $this )->get_theme_configs() as $config_entry ) {
			if ( $config_entry->slug === $theme_slug ) {
				$theme = $config_entry;
				break;
			}
		}

		if ( ! $theme ) {
			throw new \UnexpectedValueException( 'Theme not found or not updatable with GitHub Updater: ' . $theme_slug );
		}

		Singleton::get_instance( 'Base', $this )->get_remote_repo_meta( $theme );
		$repo_api = Singleton::get_instance( 'API', $this )->get_repo_api( $theme->git, $theme );

		$update = [
			'theme'       => $theme->slug,
			'new_version' => null,
			'url'         => $theme->uri,
			'package'     => $repo_api->construct_download_link( $tag ),
		];

		add_filter(
			'site_transient_update_themes',
			function ( $current ) use ( $theme, $update ) {
				$current->response[ $theme->slug ] = $update;

				return $current;
			}
		);

		$upgrader = new \Theme_Upgrader( $this->upgrader_skin );
		$upgrader->upgrade( $theme->slug );
	}

	/**
	 * Is there an error?
	 */
	public function is_error() {
		return $this->upgrader_skin->error;
	}

	/**
	 * Get messages during update.
	 */
	public function get_messages() {
		return $this->upgrader_skin->messages;
	}

	/**
	 * Process request.
	 *
	 * Relies on data in $_REQUEST, prints out json and exits.
	 * If the request came through a webhook, and if the branch in the
	 * webhook matches the branch specified by the url, use the latest
	 * update available as specified in the webhook payload.
	 *
	 * @throws \UnexpectedValueException Under multiple bad or missing params.
	 */
	public function process_request() {
		$start = microtime( true );
		try {
			if ( ! isset( self::$request['key'] ) ||
				get_site_option( 'github_updater_api_key' ) !== self::$request['key']
			) {
				throw new \UnexpectedValueException( 'Bad API key.' );
			}

			/**
			 * Allow access into the REST Update process.
			 *
			 * @since  7.6.0
			 * @access public
			 */
			do_action( 'github_updater_pre_rest_process_request' );

			$tag = 'master';
			if ( isset( self::$request['tag'] ) ) {
				$tag = self::$request['tag'];
			} elseif ( isset( self::$request['committish'] ) ) {
				$tag = self::$request['committish'];
			}

			$this->get_webhook_source();
			$override       = isset( self::$request['override'] );
			$current_branch = $this->get_local_branch();

			if ( ! ( 0 === preg_match( self::$version_number_regex, $tag ) ) ) {
				$remote_branch = 'master';
			}
			if ( isset( self::$request['branch'] ) ) {
				$tag = $remote_branch = self::$request['branch'];
			}
			$remote_branch  = isset( $remote_branch ) ? $remote_branch : $tag;
			$current_branch = $override ? $remote_branch : $current_branch;
			if ( $remote_branch !== $current_branch && ! $override ) {
				throw new \UnexpectedValueException( 'Webhook tag and current branch are not matching. Consider using `override` query arg.' );
			}

			if ( isset( self::$request['plugin'] ) ) {
				$this->update_plugin( self::$request['plugin'], $tag );
			} elseif ( isset( self::$request['theme'] ) ) {
				$this->update_theme( self::$request['theme'], $tag );
			} else {
				throw new \UnexpectedValueException( 'No plugin or theme specified for update.' );
			}
		} catch ( \Exception $e ) {
			$http_response = [
				'success'      => false,
				'messages'     => $e->getMessage(),
				'webhook'      => $_GET, // phpcs:ignore WordPress.Security.NonceVerification
				'elapsed_time' => round( ( microtime( true ) - $start ) * 1000, 2 ) . ' ms',
			];
			$this->log_exit( $http_response, 417 );
		}

		// Only set branch on successful update.
		if ( ! $this->is_error() ) {
			$slug    = isset( self::$request['plugin'] ) ? self::$request['plugin'] : false;
			$slug    = isset( self::$request['theme'] ) ? self::$request['theme'] : $slug;
			$options = $this->get_class_vars( 'Base', 'options' );

			// Set branch, delete repo cache, and spawn cron.
			$options[ 'current_branch_' . $slug ] = $current_branch;
			update_site_option( 'github_updater', $options );
			delete_site_option( 'ghu-' . md5( $slug ) );
			wp_cron();
		}

		$response = [
			'success'      => true,
			'messages'     => $this->get_messages(),
			'webhook'      => $_GET, // phpcs:ignore WordPress.Security.NonceVerification
			'elapsed_time' => round( ( microtime( true ) - $start ) * 1000, 2 ) . ' ms',
		];

		if ( $this->is_error() ) {
			$response['success'] = false;
			$this->log_exit( $response, 417 );
		}
		$this->log_exit( $response, 200 );
	}

	/**
	 * Returns the current branch of the local repository referenced in the webhook.
	 *
	 * @return string $current_branch Default return is 'master'.
	 */
	private function get_local_branch() {
		$repo = false;
		if ( isset( self::$request['plugin'] ) ) {
			$repos = Singleton::get_instance( 'Plugin', $this )->get_plugin_configs();
			$repo  = isset( $repos[ self::$request['plugin'] ] ) ? $repos[ self::$request['plugin'] ] : false;
		}
		if ( isset( self::$request['theme'] ) ) {
			$repos = Singleton::get_instance( 'Theme', $this )->get_theme_configs();
			$repo  = isset( $repos[ self::$request['theme'] ] ) ? $repos[ self::$request['theme'] ] : false;
		}
		$current_branch = $repo ?
			Singleton::get_instance( 'Branch', $this )->get_current_branch( $repo ) :
			'master';

		return $current_branch;
	}

	/**
	 * Sets the source of the webhook to $_GET variable.
	 */
	private function get_webhook_source() {
		switch ( $_SERVER ) {
			case isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ):
				$webhook_source = 'GitHub webhook';
				break;
			case isset( $_SERVER['HTTP_X_EVENT_KEY'] ):
				$webhook_source = 'Bitbucket webhook';
				break;
			case isset( $_SERVER['HTTP_X_GITLAB_EVENT'] ):
				$webhook_source = 'GitLab webhook';
				break;
			case isset( $_SERVER['HTTP_X_GITEA_EVENT'] ):
				$webhook_source = 'Gitea webhook';
				break;
			default:
				$webhook_source = 'browser';
				break;
		}
		$_GET['webhook_source'] = $webhook_source;
	}

	/**
	 * Append $response to debug.log and wp_die().
	 *
	 * @param array $response
	 * @param int   $code
	 *
	 * 128 == JSON_PRETTY_PRINT
	 * 64 == JSON_UNESCAPED_SLASHES
	 */
	private function log_exit( $response, $code ) {
		$json_encode_flags = 128 | 64;

		error_log( json_encode( $response, $json_encode_flags ) );

		/**
		 * Action hook after processing REST process.
		 *
		 * @since 8.6.0
		 *
		 * @param array $response
		 * @param int   $code     HTTP response.
		 */
		do_action( 'github_updater_post_rest_process_request', $response, $code );

		unset( $response['success'] );
		if ( 200 === $code ) {
			wp_die( wp_send_json_success( $response, $code ) );
		} else {
			wp_die( wp_send_json_error( $response, $code ) );
		}
	}
}
