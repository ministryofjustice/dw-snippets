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
    add_action('save_post', array($this,'snippet_shortcode_metabox_save'),10);
    add_shortcode($this->shortcode_name,array($this,'display_snippet'));
    add_action( 'register_shortcode_ui', array($this,'snippets_shortcode_ui') );
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

  function create_snippet_categories() {
    register_taxonomy( 'snippet_categories', $this->post_type_name, array(
        'labels' => array(
            'name' => "Snippet Categories"
          ),
        'hierarchical' => true
      ) );
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
                  'value' => $_POST['dw_snippet_shortcode']
                )
            )
        );
      $validation_query = new WP_Query($args);
      $validation_results = $validation_query->posts;
      if(!empty($validation_results)) {
        $mojintranet_errors[]= "Shortcode text is not unique. Please amend and save.";
        $data_ok = false;
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

  function display_snippet($atts) {
      foreach($atts as $index => $att) {
        $matches = array();
        preg_match('%(.*)=[\'"](.*)[\'"]%', $att, $matches);
        $atts[$matches[1]] = $matches[2];
      }
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
}

new DwSnippets();