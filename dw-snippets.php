<?php

/*
  Plugin Name: DW Snippets
  Description: Creates configurable content snippets which can be used across the site
  Author: Ryan Jarrett
  Version: 0.1
  Author URI: http://sparkdevelopment.co.uk

  Changelog
  ---------
  0.1 - initial release
 */

class DwSnippets {

  private $post_type_name = 'Snippet';
  private $shortcode_name = 'snippet';

  function __construct() {
    add_action('init',array($this,'create_snippet_categories'),10);
    add_action('init',array($this,'create_post_type'),11);
    add_action('init',array($this,'create_snippet_taxonomy'),12);
    add_action('save_post', array($this,'snippet_shortcode_metabox_save'),10);
    add_action('save_post', array($this,'clean_tracking_tags'),1);
    add_shortcode($this->shortcode_name,array($this,'display_snippet'));
    add_action( 'register_shortcode_ui', array($this,'snippets_shortcode_ui') );
    add_action('admin_enqueue_scripts', array($this,'admin_styles') );
  }

  function create_post_type() {
    register_post_type( strtolower($this->post_type_name), $args = array(
      'labels' => array(
        'name' => $this->post_type_name."s",
        'singular_name' => $this->post_type_name,
        'all_items' => "All " . $this->post_type_name."s",
        'add_new_item' => "Add New ".$this->post_type_name,
        'edit_item' => "Edit " . $this->post_type_name,
        'new_item' => "New " . $this->post_type_name,
        'view_item' => "View " . $this->post_type_name . "s",
        'search_items' => "Search " . $this->post_type_name . "s",
        'not_found' => "No " . strtolower($this->post_type_name) . "s found",
        'not_found_in_trash' => "No " . strtolower($this->post_type_name) . "s found in Trash"
        ),
      'description' => 'Content snippet for DW Snippets plugin',
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-tickets-alt',
      'supports' => array(
          'title',
          'editor',
          'wpcom-markdown'
        ),
      'taxonomies' => array(
          'snippet_categories'
        ),
      'register_meta_box_cb' => array($this, 'snippet_metaboxes')
      )
    );
  }

  function create_snippet_categories() {
    register_taxonomy( 'snippet_categories', $this->post_type_name, array(
        'labels' => array(
            'name' => "Snippet Categories"
          ),
        'hierarchical' => true,
        'show_in_quick_edit' => false
      ) );
  }

  function create_snippet_taxonomy() {
    $supported_post_types = array('post','page','news','webchat','event');
    $args = array(
      'show_ui' => true,
      'labels' => array(
        'name' => "Snippets Used"
      )
    );
    register_taxonomy('dw_'.$this->shortcode_name,$supported_post_types,$args);
  }

  function snippet_metaboxes() {
    // Remove Relevanssi metabox for Snippet CPT
    remove_meta_box('relevanssi_hidebox', $this->post_type_name, 'advanced' );
    // Remove category metabox (temporarily)
    remove_meta_box( 'snippet_categoriesdiv', $this->post_type_name, 'side' );
    // Add shortcode metabox
    add_meta_box( 'dw_snippet_shortcode', "Snippet Shortcode", array($this, 'snippet_shortcode_metabox_callback'), $this->post_type_name, 'side','core');
    // Add back category metabox
    add_meta_box ('snippet_categoriesdiv',"Snippet Categories",'post_categories_meta_box',$this->post_type_name,'side','default',array('taxonomy' => 'snippet_categories'));
  }


  function snippet_shortcode_metabox_callback($post) {
    wp_nonce_field( 'snippet_shortcode_metabox', 'snippet_shortcode_metabox_nonce' );
    $post_meta = get_post_meta($post->ID);
    ?>
    <table style="width: 100%;">
      <tr>
        <td>Shortcode tag:</td>
      </tr>
      <tr>
        <td>
          <input type="text" class="widefat" id='dw_snippet_shortcode' name='dw_snippet_shortcode' value='<?=$post_meta['_dw_snippet_shortcode'][0]?>'>
        </td>
      </tr>
    </table>
    <?php
  }

  function snippet_shortcode_metabox_save($post_id) {
    if(get_post_type( $post_id )=="snippet") {
      $shortcode_tag = $_POST['dw_snippet_shortcode'];
      $old_shortcode_tag = get_post_meta($post_id,'_dw_snippet_shortcode',true);
      $snippet_track_taxonomy = 'dw_'.$this->shortcode_name;

      if ( ! isset( $_POST['snippet_shortcode_metabox_nonce'] ) ) {
          return;
      }
      if ( ! wp_verify_nonce( $_POST['snippet_shortcode_metabox_nonce'], 'snippet_shortcode_metabox' ) ) {
          return;
      }
      if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
          return;
      }
      if ( isset( $_POST['post_type'] ) && 'snippet' == $_POST['post_type'] ) {
          if ( ! current_user_can( 'edit_page', $post_id ) ) {
              return;
          }
      } else {
          if ( ! current_user_can( 'edit_post', $post_id ) ) {
              return;
          }
      }
      $field_array=array('dw_snippet_shortcode');

      // Validation
      $data_ok = true;
      $mojintranet_errors = get_option( 'mojintranet_errors');
      if(in_array(get_post_status( $post_id ),array('publish','future'))) { // Only validate on publish
        // If dw_snippet_shortcode is not unique
        $args = array(
            'post_type' => $this->post_type_name,
            'post__not_in' => array($post_id), // exclude current post
            'meta_query' => array(
                array(
                    'key' => '_dw_snippet_shortcode',
                    'value' => $shortcode_tag
                  )
              )
          );
        $validation_query = new WP_Query($args);
        $validation_results = $validation_query->posts;
        if(!empty($validation_results)) {
          $mojintranet_errors[]= "Shortcode text is not unique. Please amend and save.";
          $data_ok = false;
        } else {
          Debug::full([$shortcode_tag,$old_shortcode_tag]);
          if(!$old_shortcode_tag ) {
            // echo "new tag";
            wp_insert_term($shortcode_tag,$snippet_track_taxonomy);
            // die;
          } else {
            // echo "rename tag"; die;
            $current_term = get_term_by( 'name', $old_shortcode_tag, $snippet_track_taxonomy);
            wp_update_term( $current_term->term_id, $snippet_track_taxonomy, $args = array('name' => $shortcode_tag) );
          }
        }
      } // end if

      foreach ($field_array as $field) {
        if (isset($_POST[$field])) {
            $data = sanitize_text_field( $_POST[$field] );
            update_post_meta( $post_id, "_" . $field, $data );
        } else {
            delete_post_meta( $post_id, "_" . $field);
        }
      } // end foreach

      if(!$data_ok) {
        // save error messages
        update_option('mojintranet_errors',$mojintranet_errors);

        // unhook this function to prevent indefinite loop
        remove_action('save_post', 'event_details_save',5);

        // update the post to change post status
        wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));

        // re-hook this function again
        add_action('save_post', 'event_details_save',5);
      } // end if
    }
  }

  function display_snippet($atts) {
    global $post;
    $post_id = $post->ID;
    // Extract arguments with single or double quotes (WordPress fail)
    foreach($atts as $index => $att) {
      $matches = array();
      preg_match('%(.*)=[\'"](.*)[\'"]%', $att, $matches);
      $atts[$matches[1]] = $matches[2];
    }

    // Ensure it works with both 'tag' and 'snippet_id' arguments
    // Tag has preference
    if(isset($atts['tag'])) {
      $snippet_tag = $atts['tag'];
      $inline =  $atts['inline'];
      $args = array(
        'post_type' => 'snippet',
        'meta_query' => array(
          array(
            'key' => '_dw_snippet_shortcode',
            'value' => $snippet_tag,
            'compare' => '='
          )
        )
      );
    } elseif(isset($atts['snippet_id'])) {
      $args = array(
        'post_type' => 'snippet',
        'p' => (int) $atts['snippet_id'],
      );
    }
    $query = new WP_Query($args);


    if($query->posts) {
      $query->the_post(); 
      $content = get_the_content();
      $content=do_shortcode($content);
      if(!$inline) {
        $content = wpautop($content);
      }

      // Update snippet taxonomy
      if(!isset($snippet_tag)) {
        $snippet_tag = get_post_meta( get_the_ID(), '_dw_snippet_shortcode', true );
      }
      if($post_id) {
        $terms_updated = wp_set_object_terms($post_id,$snippet_tag,'dw_'.$this->shortcode_name,true);
        $updated_term = get_term_by( 'term_taxonomy_id', $terms_updated[0], 'dw_'.$this->shortcode_name);
        wp_update_term($updated_term->term_id,'dw_'.$this->shortcode_name,array(
          'description' => get_the_title( get_the_ID() )
        ));
      }

      wp_reset_query();

      return $content;
    }

    return false;
  }

  function snippets_shortcode_ui() {
    shortcode_ui_register_for_shortcode($this->shortcode_name, array(
      'label' => 'Snippets',
      'listItemImage' => 'dashicons-tickets-alt',
      'attrs' => array(
        array(
          'label' => 'Choose a snippet',
          'attr' => 'snippet_id',
          'type' => 'post_select',
          'query' => array('post_type' => 'snippet'),
          'multiple' => false
        )
      )
    ));
  }

  function clean_tracking_tags($post_id) {
    // Clean all shortcode tracking tags
    wp_set_object_terms( $post_id, null, 'dw_'.$this->shortcode_name );

  }

  function admin_styles() {
    $plugin_url = plugins_url( '', __FILE__ );
    wp_enqueue_style('admin-styles', $plugin_url .'/css/snippets-taxonomy.css');
  }
}

new DwSnippets();