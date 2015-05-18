<?php
/*
Plugin Name: bbPress Bulk Unsubscribe
Plugin URI: http://wedevs.com/
Description: Unsubscribe from forum subscriptions at once
Version: 0.1
Author: Tareq Hasan
Author URI: http://tareq.wedevs.com/
License: GPL2
*/

/**
 * Copyright (c) 2015 Tareq Hasan (email: tareq@wedevs.com). All rights reserved.
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * **********************************************************************
 */

// don't call the file directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * WeDevs_BBP_Bulk_Unsubscribe class
 *
 * @class WeDevs_BBP_Bulk_Unsubscribe The class that holds the entire WeDevs_BBP_Bulk_Unsubscribe plugin
 */
class WeDevs_BBP_Bulk_Unsubscribe {

    /**
     * Constructor for the WeDevs_BBP_Bulk_Unsubscribe class
     *
     * Sets up all the appropriate hooks and actions
     * within our plugin.
     *
     * @uses register_activation_hook()
     * @uses register_deactivation_hook()
     * @uses is_admin()
     * @uses add_action()
     */
    public function __construct() {

        // Localize our plugin
        add_action( 'init', array( $this, 'localization_setup' ) );

        add_action( 'bbp_template_after_user_subscriptions', array( $this, 'unsubscribe_form' ) );
        add_action( 'bbp_template_after_user_profile', array( $this, 'unsubscribe_form' ) );

        add_action( 'wp_ajax_bbp_bulk_unsubsribe', array( $this, 'unsubsribe_ajax' ) );
    }

    /**
     * Initializes the WeDevs_BBP_Bulk_Unsubscribe() class
     *
     * Checks for an existing WeDevs_BBP_Bulk_Unsubscribe() instance
     * and if it doesn't find one, creates it.
     */
    public static function init() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new WeDevs_BBP_Bulk_Unsubscribe();
        }

        return $instance;
    }

    /**
     * Initialize plugin for localization
     *
     * @uses load_plugin_textdomain()
     */
    public function localization_setup() {
        load_plugin_textdomain( 'bbp-unsubscribe', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Do the unsubscribe form handling
     *
     * We might have thousands of subscriptions in the forum, so
     * unsubscribing from every topic at once is not a good thing.
     * Thats why we are doing a small amount of task in a request.
     *
     * @return [type] [description]
     */
    public function unsubsribe_ajax() {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bbp_bulk_unsubscribe' ) ) {
            wp_send_json_error();
        }

        $user_id       = current_user_can( 'moderate' ) ? (int) $_POST['user_id'] : get_current_user_id();
        $limit         = apply_filters( 'bbp_bulk_unsubscribe_limit', 50 );
        $subscriptions = bbp_get_user_subscribed_topic_ids( $user_id );

        if ( $subscriptions ) {
            $length      = count( $subscriptions );
            $loop_length = ( $limit > $length ) ? $length : $limit;

            for ($i = 0; $i < $loop_length; $i++) {
                bbp_remove_user_topic_subscription( $user_id, $subscriptions[ $i ] );
            }
        }

        // re-calculate stats
        $subscriptions = bbp_get_user_subscribed_topic_ids( $user_id );
        $length        = count( $subscriptions );

        wp_send_json_success( array(
            'left'    => $length,
            'message' => sprintf( __( '%d left to unsubsribe', 'bbp-unsubscribe' ), $length )
        ) );
    }

    /**
     * Show the unsubscribe form to the user/moderator
     *
     * @return void
     */
    public function unsubscribe_form() {
        $user_id = bbp_get_displayed_user_id();

        if ( current_user_can( 'moderate' ) || $user_id = get_current_user_id() ) {

            $subscriptions = bbp_get_user_subscribed_topic_ids( $user_id );
            $count         = count( $subscriptions );
            ?>

            <h2><?php _e( 'Manage Subscription', 'bbp-unsubscribe' ); ?></h2>

            <?php if ( $count ) { ?>
                <p><?php printf( __( 'You are currently subscribed to %d %s.', 'bbp-unsubscribe' ), $count, _n( 'topic', 'topics', $count, 'bbp-unsubscribe' ) ); ?></p>
            <?php } else { ?>
                <p><?php _e( 'You are not subscribed to any topic.', $domain ); ?></p>
                <?php
                return;
            } ?>

            <div id="bbp-bulk-unsusbribe-response"></div>
            <form id="bbp-bulk-unsubscribe" action="" method="post" accept-charset="utf-8">

                <?php wp_nonce_field( 'bbp_bulk_unsubscribe' ); ?>
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <input type="hidden" name="action" value="bbp_bulk_unsubsribe">
                <input type="submit" name="bbp_bulk_unsubscribe" value="<?php esc_attr_e( 'Unsubscribe from All', 'bbp-unsubscribe' ); ?>">
                <span class="bbp-unsub-loader" style="display:none"></span>
            </form>

            <script type="text/javascript">
                jQuery(function($) {

                    var responseDiv = $('#bbp-bulk-unsusbribe-response');

                    $('form#bbp-bulk-unsubscribe').on('submit', function(event) {
                        event.preventDefault();

                        var form = $(this),
                            submit = form.find('input[type=submit]'),
                            loader = form.find('.bbp-unsub-loader');

                        submit.attr('disabled', 'disabled');
                        loader.show();

                        $.post('<?php echo admin_url( 'admin-ajax.php'); ?>', form.serialize(), function(resp) {
                            if ( resp.success ) {
                                responseDiv.html( '<span>' + resp.data.message + '</span>' );

                                if ( resp.data.left > 0 ) {
                                    form.submit();
                                    return;
                                } else {
                                    submit.removeAttr('disabled');
                                    loader.hide();
                                    responseDiv.html('');
                                    window.location.reload();
                                }
                            }
                        });
                    });
                });
            </script>

            <style type="text/css">
                #bbp-bulk-unsusbribe-response span {
                    color: #8a6d3b;
                    background-color: #fcf8e3;
                    border-color: #faebcc;
                    padding: 15px;
                    margin: 10px 0;
                    border: 1px solid transparent;
                    border-radius: 4px;
                    display: block;
                }

                .bbp-unsub-loader {
                    background: url('<?php echo admin_url( 'images/spinner-2x.gif') ?>') no-repeat;
                    width: 20px;
                    height: 20px;
                    display: inline-block;
                    background-size: cover;
                }
            </style>
            <?php
        }
    }

} // WeDevs_BBP_Bulk_Unsubscribe

WeDevs_BBP_Bulk_Unsubscribe::init();