<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

class ACF_Auto_Blocks_Updater {

  protected static $instance;

  public $parent;

  public $plugin;
  public $basename;
  public $active;
  public $username = 'benplum';
  public $repository = 'ACF-Auto-Blocks';
  public $response;

  public static function get_instance() {
    if ( empty( self::$instance ) && ! ( self::$instance instanceof ACF_Auto_Blocks_Updater ) ) {
      self::$instance = new ACF_Auto_Blocks_Updater();
    }

    return self::$instance;
  }

  public function __construct() {
    $this->parent = ACF_Auto_Blocks::get_instance();

    add_action( 'admin_init', array( $this, 'admin_init' ) );

    add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
    add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3);
    add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
  }

  // Set props
  public function admin_init() {
    $this->plugin = get_plugin_data( $this->parent->file );
    $this->basename = plugin_basename( $this->parent->file );
    $this->active = is_plugin_active( $this->basename );
  }

  // Get repo
  public function get_repository() {
    if ( is_null( $this->response ) ) {
      $request_uri = sprintf( 'https://api.github.com/repos/%s/%s/tags', $this->username, $this->repository );
      $response = json_decode( wp_remote_retrieve_body( wp_remote_get( $request_uri ) ), true );

      if ( is_array( $response ) ) {
        $response = current( $response );
      }

      $this->response = $response;
    }
  }

  // Check our
  public function modify_transient( $transient ) {
    if ( property_exists( $transient, 'checked' ) ) {
      $this->get_repository();

      $checked = $transient->checked;
      $should_update = version_compare( $this->response['name'], $checked[ $this->basename ], 'gt' );

      if ( $should_update ) {
        $package = $this->response['zipball_url'];

        $slug = current( explode('/', $this->basename ) );

        $plugin = array(
          'url' => $this->plugin['PluginURI'],
          'slug' => $slug,
          'package' => $package,
          'new_version' => $this->response['name'],
        );

        $transient->response[ $this->basename ] = (object) $plugin;
      }
    }

    return $transient;
  }

  public function plugins_api( $result, $action, $args ) {
    if ( ! empty( $args->slug ) && $args->slug == current( explode( '/' , $this->basename ) ) ) {
      $this->get_repository();

      $plugin = array(
        'name' => $this->plugin['Name'],
        'slug' => $this->basename,
        'requires' => '5.0',
        'tested' => '5.0.2',
        'version' => $this->response['name'],
        'author' => $this->plugin['AuthorName'],
        'author_profile' => $this->plugin['AuthorURI'],
        'last_updated' => $this->response['published_at'],
        'homepage' => $this->plugin['PluginURI'],
        'short_description' => $this->plugin['Description'],
        'sections' => array(
          'Description' => $this->plugin['Description'],
          'Updates' => $this->response['body'],
        ),
        'download_link' => $this->response['zipball_url']
      );

      return (object) $plugin;
    }

    return $result;
  }

  public function after_install( $response, $hook_extra, $result ) {
    global $wp_filesystem;

    $install_directory = plugin_dir_path( $this->parent->file );
    $wp_filesystem->move( $result['destination'], $install_directory );
    $result['destination'] = $install_directory;

    if ( $this->active ) {
      activate_plugin( $this->basename );
    }

    return $result;
  }
}


// Instance

ACF_Auto_Blocks_Updater::get_instance();
