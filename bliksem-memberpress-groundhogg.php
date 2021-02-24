<?php
/**
 * Plugin Name: BLIKSEM Memberpress Groundhogg
 * Description: BLIKSEM Memberpress Groundhogg Link
 * Version: 1.0.0
 * Author: Andre Nell
 * License: GPL3
 */

if (! defined('WPINC')) {
    exit;
}

register_activation_hook(__FILE__, function () {
    // to update these options, go to /wp-admin/options.php
    add_option('bmg:Gh-token', '4cfcc620c11b4a42bbf1183e47d5776f');
    add_option('bmg:Gh-public-key', ''be8e7cc5cd9de876d4ea5598c537abb6);
    add_option('bmg:security_token', '05c36f759c403ac0fb3d437790e2abf5');
});

// register rest api routes
add_action('rest_api_init', function () {
    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-created
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-created', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_created_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-paused
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-paused', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_paused_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-resumed
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-resumed', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_resumed_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-stopped
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-stopped', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_paused_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-upgraded
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-upgraded', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_upgraded_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-downgraded
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-downgraded', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_upgraded_event',
        'permission_callback' => '__return_true',
    ]);

    // register /wp-json/bliksem-memberpress-groundhogg-link/v1/subscription-expired
    register_rest_route('bliksem-memberpress-groundhogg-link/v1', '/subscription-expired', [
        'methods' => 'POST',
        'callback' => 'bmg_subscription_expired_event',
        'permission_callback' => '__return_true',
    ]);
});

// Add contact and add membership tag to contact in groundhogg when new membership is created
function bmg_subscription_created_event(WP_REST_Request $request) : WP_REST_Response
{
    if (($_GET['security_token'] ?? '') !== get_option('bmg:security_token')) {
        return new WP_REST_Response(null);
    }

    // event data
    $data = json_decode(file_get_contents('php://input'), 1);

    if (($data['event'] ?? '') !== 'subscription-created') {
        return new WP_REST_Response(null);
    }

    // check if membership id exists
    if (! $membership_id = (int) $data['data']['membership']['id']) {
        return new WP_REST_Response(null);
    }

    // create tag
    $gh_req = wp_remote_post(rest_url('gh/v3/tags'), [
        'method' => 'POST',
        'headers' => [
            'content-type' => 'application/json; charset=utf-8',
            'Gh-token' => get_option('bmg:Gh-token'),
            'Gh-public-key' => get_option('bmg:Gh-public-key'),
        ],
        'body' => json_encode([
            'tags' => [ "membership-{$membership_id}" ]
        ]),
    ]);

    $tags = json_decode($gh_req['body'], 1);
    $tags = (array) ($tags['tags'] ?? null);
    $membership_tag_id = array_search("membership-{$membership_id}", $tags);

    // if no tag was created or retrieved
    if (! $membership_tag_id) {
        return new WP_REST_Response(null);
    }

    // pull existing contact if any
    $gh_req = wp_remote_post(add_query_arg([
        'id_or_email' => $data['data']['member']['email'],
        'by_user_id' => false,
    ], rest_url('gh/v3/contacts')), [
        'method' => 'GET',
        'headers' => [
            'content-type' => 'application/json; charset=utf-8',
            'Gh-token' => get_option('bmg:Gh-token'),
            'Gh-public-key' => get_option('bmg:Gh-public-key'),
        ],
    ]);
    
    $user = json_decode($gh_req['body'], 1);
    $contact = $user['contact'] ?? null;

    if ($contact['ID'] ?? null) { // update existing contact
        if (in_array($membership_tag_id, (array) ($contact['tags'] ?? null))) {
            return new WP_REST_Response(null);
        }

        $contact['tags'] = $contact['tags'] ?? [];
        $contact['tags'] []= $membership_tag_id;

        $gh_req = wp_remote_post(rest_url('gh/v3/contacts'), [
            'method' => 'PUT',
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
                'Gh-token' => get_option('bmg:Gh-token'),
                'Gh-public-key' => get_option('bmg:Gh-public-key'),
            ],
            'body' => json_encode([
                'id_or_email' => $contact['ID'],
                'by_user_id' => true,
                'contact' => $contact,
            ]),
        ]);
    } else { // create new contact
        $gh_req = wp_remote_post(rest_url('gh/v3/contacts'), [
            'method' => 'POST',
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
                'Gh-token' => get_option('bmg:Gh-token'),
                'Gh-public-key' => get_option('bmg:Gh-public-key'),
            ],
            'body' => json_encode([
                'email' => $data['data']['member']['email'],
                'user_id' => $data['data']['member']['id'],
                'tags' => [ $membership_tag_id ],
            ]),
        ]);
    }

    return new WP_REST_Response(null);
}

// // # Remove membership tag from contact in groundhogg when membership is paused
// function bmg_subscription_paused_event( WP_REST_Request $request ) : WP_REST_Response
// {
//     if ( ($_GET['security_token'] ?? '') !== get_option('bmg:security_token') )
//         return new WP_REST_Response(null);

//     // event data
//     $data = json_decode(file_get_contents('php://input'), 1);
        
//     if ( ! in_array(($data['event'] ?? ''), ['subscription-paused','subscription-stopped']) )
//         return new WP_REST_Response(null);

//     // check if membership id exists
//     if ( ! $membership_id = (int) $data['data']['membership']['id'] )
//         return new WP_REST_Response(null);

//     // create tag
//     $gh_req = wp_remote_post(rest_url('gh/v3/tags'), [
//         'method' => 'POST',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'tags' => [ "membership-{$membership_id}" ]
//         ]),
//     ]);

//     $tags = json_decode($gh_req['body'], 1);
//     $tags = (array) ( $tags['tags'] ?? null );
//     $membership_tag_id = array_search("membership-{$membership_id}", $tags);

//     // if no tag was created or retrieved
//     if ( ! $membership_tag_id )
//         return new WP_REST_Response(null);

//     // delete tag from user
//     $gh_req = wp_remote_post(rest_url('gh/v3/tags/remove'), [
//         'method' => 'PUT',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'id_or_email' => $data['data']['member']['email'],
//             'by_user_id' => false,
//             'tags' => [ $membership_tag_id ],
//         ]),
//     ]);

//     return new WP_REST_Response(null);
// }

// // # Add membership tag to contact in groundhogg when  membership is resumed
// function bmg_subscription_resumed_event( WP_REST_Request $request ) : WP_REST_Response
// {
//     if ( ($_GET['security_token'] ?? '') !== get_option('bmg:security_token') )
//         return new WP_REST_Response(null);

//     // event data
//     $data = json_decode(file_get_contents('php://input'), 1);
    
//     if ( ($data['event'] ?? '') !== 'subscription-resumed' )
//         return new WP_REST_Response(null);

//     // check if membership id exists
//     if ( ! $membership_id = (int) $data['data']['membership']['id'] )
//         return new WP_REST_Response(null);

//     // create tag
//     $gh_req = wp_remote_post(rest_url('gh/v3/tags'), [
//         'method' => 'POST',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'tags' => [ "membership-{$membership_id}" ]
//         ]),
//     ]);

//     $tags = json_decode($gh_req['body'], 1);
//     $tags = (array) ( $tags['tags'] ?? null );
//     $membership_tag_id = array_search("membership-{$membership_id}", $tags);

//     // if no tag was created or retrieved
//     if ( ! $membership_tag_id )
//         return new WP_REST_Response(null);

//     // add tag to user user
//     wp_remote_post(rest_url('gh/v3/tags/apply'), [
//         'method' => 'PUT',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'id_or_email' => $data['data']['member']['email'],
//             'by_user_id' => false,
//             'tags' => [ $membership_tag_id ],
//         ]),
//     ]);

//     return new WP_REST_Response(null);
// }

// // XRemove/add membership tag to contact in groundhogg when  membership is changed
// function bmg_subscription_upgraded_event( WP_REST_Request $request ) : WP_REST_Response
// {
//     if ( ($_GET['security_token'] ?? '') !== get_option('bmg:security_token') )
//         return new WP_REST_Response(null);

//     // event data
//     $data = json_decode(file_get_contents('php://input'), 1);
    
//     if ( ! in_array(($data['event'] ?? ''), [
//         'subscription-upgraded-to-one-time',
//         'subscription-upgraded-to-recurring',
//         'subscription-downgraded-to-one-time',
//         'subscription-downgraded-to-recurring']) )
//         return new WP_REST_Response(null);

//     // check if membership id exists
//     if ( ! $membership_id = (int) $data['data']['membership']['id'] )
//         return new WP_REST_Response(null);

//     // create tag
//     $gh_req = wp_remote_post(rest_url('gh/v3/tags'), [
//         'method' => 'POST',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'tags' => [ "membership-{$membership_id}" ]
//         ]),
//     ]);

//     $app_tags = json_decode($gh_req['body'], 1);
//     $app_tags = (array) ( $app_tags['tags'] ?? null );
//     $membership_tag_id = array_search("membership-{$membership_id}", $app_tags);

//     // if no tag was created or retrieved
//     if ( ! $membership_tag_id )
//         return new WP_REST_Response(null);

//     // contact does not exist
//     if ( ! $contact = \Groundhogg\get_contactdata($data['data']['member']['email']) )
//         return new WP_REST_Response(null);

//     $tags = $contact->get_tags();
//     $all_tags = \Groundhogg\Plugin::$instance->dbs->get_db('tags')->search('membership-');
//     $unset = [];

//     foreach ( $all_tags as $tag ) {
//         // this is a membership tag, let's delete it from the user's
//         if ( preg_match('/^membership\-[0-9]+$/', $tag->tag_name) && $tag->tag_id != $membership_tag_id ) {
//             $unset []= (int) $tag->tag_id;
//         }
//     }

//     // delete all membership tags except current
//     if ( count($unset) ) {
//         $contact->remove_tag($unset);
//     }

//     // add the current tag if not already in the list
//     if ( ! in_array((int) $membership_tag_id, array_map('intval', $tags)) ) {
//         $contact->add_tag($membership_tag_id);
//     }

//     return new WP_REST_Response(null);
// }



// // # Remove membership tag from contact in groundhogg when membership expires
// function bmg_subscription_expired_event( WP_REST_Request $request ) : WP_REST_Response
// {
//     if ( ($_GET['security_token'] ?? '') !== get_option('bmg:security_token') )
//         return new WP_REST_Response(null);

//     // event data
//     $data = json_decode(file_get_contents('php://input'), 1);
    
//     if ( ($data['event'] ?? '') !== 'subscription-expired' )
//         return new WP_REST_Response(null);

//     // check if membership id exists
//     if ( ! $membership_id = (int) $data['data']['membership']['id'] )
//         return new WP_REST_Response(null);

//     // create tag
//     $gh_req = wp_remote_post(rest_url('gh/v3/tags'), [
//         'method' => 'POST',
//         'headers' => [
//             'content-type' => 'application/json; charset=utf-8',
//             'Gh-token' => get_option('bmg:Gh-token'),
//             'Gh-public-key' => get_option('bmg:Gh-public-key'),
//         ],
//         'body' => json_encode([
//             'tags' => [ "membership-{$membership_id}" ]
//         ]),
//     ]);

//     $app_tags = json_decode($gh_req['body'], 1);
//     $app_tags = (array) ( $app_tags['tags'] ?? null );
//     $membership_tag_id = array_search("membership-{$membership_id}", $app_tags);

//     // if no tag was created or retrieved
//     if ( ! $membership_tag_id )
//         return new WP_REST_Response(null);

//     // contact does not exist
//     if ( ! $contact = \Groundhogg\get_contactdata($data['data']['member']['email']) )
//         return new WP_REST_Response(null);

//     $tags = $contact->get_tags();

//     // add the current tag if not already in the list
//     if ( in_array((int) $membership_tag_id, array_map('intval', $tags)) ) {
//         $contact->remove_tag($membership_tag_id);
//     }

//     return new WP_REST_Response(null);
// 		}
