<?php

class ReactifyWP {
	public $v8;

	public function __construct() { }

	public function render() {
		do_action( 'reactifywp_render' );

		$server = file_get_contents( __DIR__ . '/../js/server.js');

		$this->v8->executeString( $server, V8Js::FLAG_FORCE_ARRAY );

		exit;
	}

	public function register_template_tag( $tag_name, $tag_function, $constant = true, $on_action = 'reactifywp_render' ) {
		if ( ! $constant && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		$context = $this->v8->context;

		$register = function() use ( &$context, $tag_name, $tag_function ) {
			ob_start();

			$tag_function();

			$output = ob_get_clean();

			$context->template_tags[ $tag_name ] = $output;
		};

		if ( ! empty( $on_action ) ) {
			add_action( $on_action, $register );
		} else {
			$register();
		}
	}

	public function register_post_tag( $tag_name, $tag_function ) {
		$context = $this->v8->context;

		add_filter( 'reactifywp_register_post_tags', function( $post ) use ( $tag_function, $tag_name ) {
			global $post;
			setup_postdata( $post );

			ob_start();

			$tag_function();

			wp_reset_postdata();

			$post->{$tag_name} = ob_get_clean();

			return $post;
		} );
	}

	public function setup() {
		$this->v8 = new \V8Js();
		$this->v8->context = new stdClass(); // v8js didn't like an array here :(
		$this->v8->context->template_tags = [];
		$this->v8->context->route = [];
		$this->v8->context->posts = [];
		$this->v8->context->nav_menus = [];
		$this->v8->context->sidebars = [];

		add_action( 'after_setup_theme', array( $this, 'register_menus' ), 11 );
		add_action( 'reactifywp_render', array( $this, 'register_route' ), 11 );
		add_action( 'reactifywp_render', array( $this, 'register_posts' ), 9 );
		add_action( 'reactifywp_render', array( $this, 'register_sidebars' ), 9 );
		add_action( 'template_redirect', array( $this, 'render' ) );
	}

	public function register_route() {
		$route = [
			'type'        => null,
			'object_id'   => null,
		];

		if ( is_home() || is_front_page() ) {

			if ( is_home() ) {
				$route['type'] = 'home';
			} else {
				$route['type'] = 'front_page';
			}
		} else {
			$object = get_queried_object();

			if ( is_single() || is_page() ) {
				$route['type'] = 'single';
				$route['object_type'] = $object->post_type;
				$route['object_id'] = $object->ID;
			} else {
				$route['type'] = 'archive';

				if ( is_author() ) {
					$route['object_type'] = 'author';
				} elseif ( is_post_type_archive() ) {
					$route['object_type'] = $object->name;
				} elseif ( is_tax() ) {
					$route['object_type'] = $object->taxonomy;
				}
			}
		}

		$this->v8->context->route = $route;
	}

	public function register_sidebars() {
		global $wp_registered_sidebars;

		foreach ( $wp_registered_sidebars as $sidebar ) {
			ob_start();

			dynamic_sidebar( $sidebar['id'] );

			$this->v8->context->sidebars[ $sidebar['id'] ] = ob_get_clean();
		}
	}

	public function register_menus() {
		$menus = get_nav_menu_locations();

		foreach ( $menus as $location => $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );

			$ref_map = [];
			$menu = [];

			foreach ( $items as $item_key => $item ) {
				$menu_item = new stdClass(); // We use a class so we can modify objects in place
				$menu_item->url = $item->url;
				$menu_item->title = apply_filters( 'the_title', $item->title, $item->ID );
				$menu_item->children = [];

				if ( empty( $item->menu_item_parent ) ) {
					$index = ( empty( $menu ) ) ? 0 : count( $menu );
					$menu[ $index ] = $menu_item;

					$ref_map[ $item->ID ] = $menu_item;
				} else {
					$ref_map[ $item->menu_item_parent ]->children[] = $menu_item;
				}
			}

			// Convert to arrays
			foreach ( $menu as $key => $menu_item ) {
				$menu[ $key ] = $this->_convert_to_arrays( $menu_item );
			}

			$this->v8->context->nav_menus[ $location ] = $menu;
		}
	}

	private function _convert_to_arrays( $menu_item ) {
		$menu_item = (array) $menu_item;

		if ( ! empty( $menu_item['children'] ) ) {
			foreach ( $menu_item['children'] as $child_key => $child_item ) {
				$menu_item['children'][ $child_key ] = $this->_convert_to_arrays( $child_item );
			}

			return $menu_item;
		} else {
			return $menu_item;
		}
	}

	public function register_posts() {
		$GLOBALS['wp_the_query']->query( [] );

		$this->v8->context->posts = $GLOBALS['wp_the_query']->posts;

		foreach ( $this->v8->context->posts as $key => $post ) {
			$this->v8->context->posts[ $key ]->the_title = apply_filters( 'the_title', $post->post_title );
			$this->v8->context->posts[ $key ]->the_content = apply_filters( 'the_content', $post->post_content );
			$this->v8->context->posts[ $key ]->post_class = get_post_class( '', $post->ID );
			$this->v8->context->posts[ $key ]->permalink = get_permalink( $post->ID );

			$this->v8->context->posts[ $key ] = (array) apply_filters( 'reactifywp_register_post_tags', $this->v8->context->posts[ $key ] );
		}
	}

	public function setup_api() {
		require_once __DIR__ . '/class-reactify-api.php';

		add_action( 'rest_api_init', function() {
			$reactify_api = new ReactifyWP_API();
			$reactify_api->register_routes();
		} );
	}

	public static function instance() {
		static $instance;

		if ( empty( $instance ) ) {
			$instance = new self();
			$instance->setup();
			$instance->setup_api();
		}

		return $instance;
	}
}

