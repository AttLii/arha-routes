<?php

if (!defined('WPINC')) {
  die;
}

class ArhaHelpers {
  /**
   * Function checks if passed in $post_type is found in results of passed in $filter.
   *
   * @param String $filter
   * @param String $post_type
   */
  public static function check_excluded_post_types($filter, $post_type) {
    $excluded_post_types = apply_filters($filter, []);
    if (sizeof($excluded_post_types) === 0) {
      return;
    }

    if (in_array($post_type, $excluded_post_types)) {
      throw new Exception("Post_type '$post_type' is excluded from routes");
    }
  }

  /**
   * Checks if request object has all required parameters.
   *
   * @param WP_REST_Request $request
   * @param Array $required_params
   */
  public static function check_required_params($request, $require_params) {
    $params = $request->get_params();
    foreach ($require_params as $required_param) {
      if (!isset($params[$required_param])) {
        throw new Exception("$required_param not specified in GET-params");
      }
    }
  }

  /**
   * Function checks if passed in string is valid to be orderby argument for new WP_Query().
   *
   * @param String $orderby
   * @see https://developer.wordpress.org/reference/classes/wp_query/#order-orderby-parameters
   */
  public static function check_orderby_param(String $orderby) {
    $orderby_values = [
      'none',
      'ID',
      'author',
      'title',
      'name',
      'type',
      'date',
      'modified',
      'parent',
      'rand',
      'comment_count',
      'relevance',
      'menu_order',
      'meta_value',
      'meta_value_num',
      'post__in',
      'post_name__in',
      'post_parent__in'
    ];
    if (!in_array($orderby, $orderby_values)) {
      throw new Exception("orderby-param is not acceptable");
    }
  }

  /**
   * Function checks if passed in post's language is same as second params value.
   *
   * @param WP_Post $post
   * @param String $lang
   * @return Boolean
   */
  public static function check_post_language($post, $lang) {
    $post_type = $post->post_type;
    if (function_exists('pll_get_post_language')) {
      $post_lang = pll_get_post_language($post->ID);
      return $lang === $post_lang;
    } else {
      throw new Exception("System cannot check language");
    }
  }

  /**
   * Function checks if language code has been set to any of Wordpress by Polylang-plugin.
   * if not, function throws exception.
   *
   * @param String $lang - language code
   * @return void
   */
  public static function check_language_availability($lang) {
    if (!function_exists('pll_languages_list')) {
      throw new Exception("System cannot check language");
    }

    $langs = pll_languages_list();
    if (!in_array($lang, $langs)) {
      throw new Exception("Language not found");
    }
  }

  /**
   * Sets Polylang-plugin's current language model to one passed in as param
   * until end of the process
   *
   * @param String $lang - language code
   */
  public static function set_polylang_curlang($lang) {
    self::check_language_availability($lang);

    PLL()->curlang = PLL()->model->get_language($lang);
  }

  /**
   * Gets homepage from Reading settings (/wp-admin/options-reading.php)
   *
   * Remember to call ArhaHelpers::set_polylang_curlang($lang) before this function, if you use polylang.
   *
   * @return WP_Post - WP post
   */
  public static function get_front_page() {
    $frontpage_id = (int)get_option('page_on_front');
    if ($frontpage_id === 0) {
      throw new Exception("Homepage not set in reading settings");
    }

    if (class_exists('Polylang')) {
      $frontpage_id = pll_get_post($frontpage_id);

      if ($frontpage_id === 0) {
        throw new Exception("Homepage not set in reading settings");
      }
    }
    return get_page($frontpage_id);
  }

  /**
   * This function is used when fetching posts from WP.
   *
   * Main reason using this over regular WP functions (get_posts() and get_page_by_path()) is that native functions work unreliably while Polylang installed, especially when different language posts share same slug (Polylang Pro feature).
   *
   * Function uses mostly code from get_page_by_path() source code to fetch posts from WP database.
   * Additionally when Polylang is activated, it filters out other language posts with 'lang'-property from passed in argument.
   *
   * @param Array $args - Expected properties: path/slug, post_type, lang if polylang activated
   * @return WP_Post $post - WP post or null
   * @see https://developer.wordpress.org/reference/functions/get_page_by_path/
   */
  public static function get_post($args) {
    global $wpdb;

    $page_path   = isset($args['path']) ? $args['path'] : $args['slug'];
    $post_type   = $args['post_type'];
    $post_status = $args['post_type'] == 'attachment' ? 'inherit' : 'publish';

    $in_string = self::prepare_path_to_sql($page_path);
    $sql       = "
    SELECT
      ID, post_name, post_parent, post_type, post_status
    FROM
      $wpdb->posts
    WHERE
      post_name IN ('$in_string') AND
      post_status IN ('$post_status') AND
      post_type IN ('$post_type')
    ";

    $posts = $wpdb->get_results($sql, ARRAY_A);

    $posts = array_map(function ($post) {
      $path              = self::build_path($post['ID']);
      $post['in_string'] = self::prepare_path_to_sql($path);
      return $post;
    }, $posts);

    $posts = array_filter($posts, function ($post) use ($in_string) {
      return $post['in_string'] === $in_string;
    });

    $posts = array_values($posts);
    if (sizeof($posts) === 0) {
      return null;
    }

    $id = null;
    if (class_exists('Polylang')) {
      $lang = $args['lang'];
      $id   = self::filter_posts_with_lang($lang, $posts);
    } else {
      $id = $posts[0]['ID'];
    }

    return get_post($id);
  }

  /**
   * Uses get_page_by_path()'s steps to prepare $page_path to sql query
   *
   * @param String $page_path
   * @return String $in_string
   */
  public static function prepare_path_to_sql($page_path) {
    $page_path     = rawurlencode(urldecode($page_path));
    $page_path     = str_replace('%2F', '/', $page_path);
    $page_path     = str_replace('%20', ' ', $page_path);
    $parts         = explode('/', trim($page_path, '/'));
    $parts         = array_map('sanitize_title_for_query', $parts);
    $escaped_parts = esc_sql($parts);
    $in_string     = implode("','", $escaped_parts);
    return $in_string;
  }

  /**
   * Self-iterating function that build array of slugs from a post and it's parent posts.
   * When iteration gets to top level, function implodes built array with "/" and returns the result.
   *
   * @param Object $post - post with ID, post_name, post_parent, post_type, post_status properties
   * @param Array $path_arr - array of slugs, used by function itself
   * @return String $path
   */
  public static function build_path($id, $path_arr = []) {
    $post      = get_post($id);
    $parent_id = (int)$post->post_parent;
    array_unshift($path_arr, $post->post_name);
    if ($parent_id === 0) {
      $path = implode('/', $path_arr);
      return $path;
    } else {
      return self::build_path($parent_id, $path_arr);
    }
  }

  /**
   *
   * @param String $lang - language code
   * @param Array $posts - list of WP posts
   * @return WP_Post $found_id
   */
  public static function filter_posts_with_lang($lang, $posts) {
    if (!function_exists('pll_get_post_language')) {
      throw new Exception('Polylang not activated');
    }

    $found_id = null;
    foreach ($posts as $post) {
      $id = $post['ID'];
      if (pll_get_post_language($id) === $lang) {
        $found_id = $id;
        break;
      }
    }
    return $found_id;
  }
}