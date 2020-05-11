<?php
/*
Plugin Name: Arha Routes
Description: Adds REST endpoints for Headless setup
Version: 1.3
Author: Atte Liimatainen
 */

if (!defined('WPINC')) {
  die;
}

require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';

class ArhaRoutes {
  protected $_slug;
  protected $_namespace;
  protected $_api_version;

  public function __construct() {
    $this->_slug        = 'arha-routes';
    $this->_namespace   = 'arha';
    $this->_api_version = 'v1';
  }

  public function attach_hooks() {
    add_action('rest_api_init', [$this, 'register_api_endpoints']);
  }

  public function register_api_endpoints() {
    $namespace = $this->_namespace . '/' . $this->_api_version;

    register_rest_route($namespace, "/page", [
      'methods'  => WP_REST_Server::READABLE,
      'callback' => [$this, "get_page"],
    ]);

    register_rest_route($namespace, "/post", [
      'methods'  => WP_REST_Server::READABLE,
      'callback' => [$this, "get_post"],
    ]);

    register_rest_route($namespace, "/options", [
      'methods'  => WP_REST_Server::READABLE,
      'callback' => [$this, "get_options"],
    ]);

    register_rest_route($namespace, "/archive", [
      'methods'  => WP_REST_Server::READABLE,
      'callback' => [$this, "get_archive"],
    ]);
  }

  public function get_post(WP_REST_Request $request) {
    $return_message;
    try {

      $required_params = ['slug', 'post_type'];
      if (class_exists('Polylang')) {
        $required_params[] = 'lang';
      }
      ArhaHelpers::check_required_params($request, $required_params);

      $post_type = $request->get_param('post_type');
      if (!post_type_exists($post_type)) {
        throw new Exception("post_type wasn't found on System");
      }

      $filter = 'arha_routes/post_excluded_post_types';
      ArhaHelpers::check_excluded_post_types($filter, $post_type);

      $slug = $request->get_param('slug');

      $args = [
        'slug'      => $slug,
        'post_type' => $post_type,
      ];

      $lang;
      if (class_exists('Polylang')) {
        $lang = $request->get_param('lang');
        ArhaHelpers::check_language_availability($lang);
        $args['lang'] = $lang;
      }

      $post = ArhaHelpers::get_post($args);
      if (!$post) {
        throw new Exception("System didn't find post with post_type '${post_type}' and slug '${slug}'");
      }

      $content = apply_filters('arha_routes/format_post', $post);

      $return_message = [
        'status'  => 'success',
        'content' => $content,
      ];
    } catch (Exception $e) {
      $return_message = [
        'status'  => 'error',
        'content' => $e->getMessage(),
      ];
    }
    return $return_message;
  }

  public function get_page(WP_REST_Request $request) {
    $return_message;
    try {
      $required_params = ['path'];
      if (class_exists('Polylang')) {
        $required_params[] = 'lang';
      }

      ArhaHelpers::check_required_params($request, $required_params);

      $unescaped_path = $request->get_param('path');
      $path           = stripslashes($unescaped_path);

      $page = null;

      $lang;
      if (class_exists('Polylang')) {
        $lang = $request->get_param('lang');
        ArhaHelpers::set_polylang_curlang($lang);
      }

      if ($path === '/') {
        $page = ArhaHelpers::get_front_page();
      } else {
        $args = [
          'post_type' => 'page',
          'path'      => $path,
        ];

        if (class_exists('Polylang')) {
          $args['lang'] = $lang;
        }
        $page = ArhaHelpers::get_post($args);
      }

      if (!$page) {
        throw new Exception("Didn't find page with path '${path}'");
      }

      $content = apply_filters('arha_routes/format_page', $page);

      $return_message = [
        'status'  => 'success',
        'content' => $content,
      ];
    } catch (Exception $e) {
      $return_message = [
        'status'  => 'error',
        'content' => $e->getMessage(),
      ];
    }
    return $return_message;
  }

  public function get_options(WP_REST_Request $request) {
    $return_message;
    try {
      $content;
      if (class_exists('Polylang')) {
        $required_params = ['lang'];
        ArhaHelpers::check_required_params($request, $required_params);
        $lang    = $request->get_param('lang');
        $content = apply_filters('arha_routes/format_options', [], $lang);
      } else {
        $content = apply_filters('arha_routes/format_options', []);
      }

      $return_message = [
        'status'  => 'success',
        'content' => $content,
      ];
    } catch (Exception $e) {
      $return_message = [
        'status'  => 'error',
        'content' => $e->getMessage(),
      ];
    }
    return $return_message;
  }

  public function get_archive(WP_REST_Request $request) {
    $return_message;
    try {
      $required_params = ['post_type', 'posts_per_page', 'paged', 'orderby', 'order'];
      if (class_exists('Polylang')) {
        $required_params[] = 'lang';
      }
      ArhaHelpers::check_required_params($request, $required_params);

      $post_type  = $request->get_param('post_type');
      $post_types = gettype($post_type) === 'array' ? $post_type : [$post_type];
      foreach ($post_types as $post_type) {
        if (!post_type_exists($post_type)) {
          throw new Exception("Post_type wasn't found on System");
        }
        $filter = 'arha_routes/archive_excluded_post_types';
        ArhaHelpers::check_excluded_post_types($filter, $post_type);
      }

      $posts_per_page = $request->get_param('posts_per_page');
      $posts_per_page = (int)$posts_per_page;
      if ($posts_per_page < 1 || $posts_per_page > 100) {
        throw new Exception('posts_per_page needs to a number be between 1 and 100');
      }

      $paged = $request->get_param('paged');
      $paged = (int)$paged;
      if ($paged < 1) {
        throw new Exception('paged needs to be a number above 1');
      }

      $orderby = $request->get_param('orderby');
      ArhaHelpers::check_orderby_param($orderby);

      $order = $request->get_param('order');
      $order = strtoupper($order);
      if ($order !== 'ASC' && $order !== 'DESC') {
        throw new Exception('Order param can only be ASC or DESC');
      }

      $status = in_array('attachment', $post_types) ? 'inherit' : 'publish';

      $args = [
        'post_type'      => $post_types,
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
        'orderby'        => $orderby,
        'order'          => $order,
        'status'         => $status,
      ];

      if ($orderby == 'meta_value' || $orderby == 'meta_value_num') {
        $meta_key = $request->get_param('meta_key');
        if (!$meta_key) {
          throw new Exception("To order posts by meta-field, define meta_key param with meta-field's name");
        }

        $args['meta_key'] = $meta_key;
      }

      if (class_exists('Polylang')) {
        $lang = $request->get_param('lang');
        ArhaHelpers::set_polylang_curlang($lang);
        $args['lang'] = $lang;
      }

      $s = $request->get_param('s');
      if ($s) {
        if (!class_exists('SWP_Query')) {
          throw new Exception("s-param is disabled");
        }

        $args['s'] = $s;
      }

      $tax_query = $request->get_param('tax_query');
      if ($tax_query) {
        $tax_query         = json_decode($tax_query, true);
        $args['tax_query'] = $tax_query;
      }

      $meta_query = $request->get_param('meta_query');
      if ($meta_query) {
        $meta_query         = json_decode($meta_query, true);
        $args['meta_query'] = $meta_query;
      }

      $query = $s ? new SWP_Query($args) : new WP_Query($args);

      $found_posts = (int)$query->found_posts;

      $query_posts = is_array($query->posts) ? $query->posts : [];

      $posts = array_map(function ($post) {
        return apply_filters('arha_routes/format_archive_post', $post);
      }, $query_posts);

      $return_message = [
        'status'  => 'success',
        'content' => [
          'found_posts' => $found_posts,
          'posts'       => $posts,
        ],
      ];

    } catch (Exception $e) {
      $return_message = [
        'status'  => 'error',
        'content' => $e->getMessage(),
      ];
    }

    return $return_message;
  }
}

$runner = function () {
  $plugin = new ArhaRoutes();
  $plugin->attach_hooks();
};

$runner();
unset($runner);