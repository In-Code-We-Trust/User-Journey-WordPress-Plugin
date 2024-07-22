<?php
/**
 * Plugin Name: User Journey Logger
 * Description: Logs user actions such as product views, cart additions, and purchases.
 * Version: 1.0
 * Author: Samantha Gutsa
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('user_journey_table', 'ujl_display_user_journey_table');
add_shortcode('conversion_analysis_table', 'ujl_display_conversion_analysis_table');

// Create the database tables on plugin activation
register_activation_hook(__FILE__, 'ujl_create_tables');
function ujl_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Create wc_user_journeys table
    $table_name = $wpdb->prefix . 'wc_user_journeys';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        action varchar(255) NOT NULL,
        product_id bigint(20) NOT NULL,
        product_name varchar(255),
        product_tags text,
        product_categories text,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Log user actions
add_action('template_redirect', 'ujl_log_user_action');
function ujl_log_user_action() {
    if (is_product()) {
        global $post;
        $product_id = $post->ID;
        ujl_insert_action('Viewed product', $product_id);
    }
}

// Log user actions for cart additions and purchases
add_action('woocommerce_add_to_cart', 'ujl_log_add_to_cart', 10, 6);
function ujl_log_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    ujl_insert_action('Added to cart', $product_id);
}

add_action('woocommerce_thankyou', 'ujl_log_purchase');
function ujl_log_purchase($order_id) {
    $order = wc_get_order($order_id);
    foreach ($order->get_items() as $item) {
        ujl_insert_action('Purchased', $item->get_product_id());
    }
}

// Insert action into the database
function ujl_insert_action($action, $product_id = null) {
    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'wc_user_journeys';

    // Get product details
    $product_name = $product_tags = $product_categories = '';
    if ($product_id) {
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : '';
        $product_tags = implode(', ', wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names')));
        $product_categories = implode(', ', wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names')));
    }

    error_log("Logging action: $action, Product ID: $product_id, Product Name: $product_name"); // Debugging

    $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'action' => $action,
            'product_id' => $product_id,
            'product_name' => $product_name,
            'product_tags' => $product_tags,
            'product_categories' => $product_categories,
            'timestamp' => current_time('mysql'),
        )
    );
}

// Shortcode to display the user journey table
function ujl_display_user_journey_table() {
    global $wpdb;

    // Get the user with the most purchases
    $user_purchase_data = $wpdb->get_results("
        SELECT umeta.meta_value AS user_id, COUNT(*) AS purchase_count, SUM(pm.meta_value) AS total_amount
        FROM {$wpdb->prefix}posts AS p
        INNER JOIN {$wpdb->prefix}postmeta AS pm ON p.ID = pm.post_id
        INNER JOIN {$wpdb->prefix}postmeta AS umeta ON p.ID = umeta.post_id
        WHERE p.post_type = 'shop_order'
          AND pm.meta_key = '_order_total'
          AND umeta.meta_key = '_customer_user'
        GROUP BY umeta.meta_value
        ORDER BY purchase_count DESC, total_amount DESC
        LIMIT 1
    ");

    $top_user_sentence = '';
    if ($user_purchase_data) {
        $top_user = $user_purchase_data[0];
        $user_info = get_userdata($top_user->user_id);
        $top_user_sentence = sprintf(
            'The user with the most purchases is %s with a total amount of %s.',
            esc_html($user_info->user_login),
            wc_price($top_user->total_amount)
        );
    }

    // Pagination for user journey
    $per_page = 10;
    $current_page = isset($_GET['journey_page']) ? max(1, intval($_GET['journey_page'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Retrieve the user journey data
    $table_name = $wpdb->prefix . 'wc_user_journeys';
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d", $per_page, $offset));

    // Pagination for returning buyers
    $rb_current_page = isset($_GET['rb_page']) ? max(1, intval($_GET['rb_page'])) : 1;
    $rb_offset = ($rb_current_page - 1) * $per_page;

    // Retrieve the returning buyers data
    $total_returning_buyers = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name");
    $returning_buyers = $wpdb->get_results($wpdb->prepare("
        SELECT user_id, COUNT(*) AS return_count
        FROM $table_name
        GROUP BY user_id
        HAVING COUNT(*) > 1
        ORDER BY return_count DESC
        LIMIT %d OFFSET %d
    ", $per_page, $rb_offset));

    // Start output
    $output = '<div class="wrap">';
    $output .= '<h1>User Journey</h1>';
    
    // Add top user purchase info
    $output .= '<p>' . $top_user_sentence . '</p>';
    
    // Display returning buyers table
    if ($returning_buyers) {
        $output .= '<h2>Returning Buyers</h2>';
        $output .= '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr><th>User ID</th><th>Number of Returns</th></tr></thead>';
        $output .= '<tbody>';
        foreach ($returning_buyers as $buyer) {
            $user_info = get_userdata($buyer->user_id);
            $output .= '<tr>';
            $output .= '<td>' . esc_html($user_info->user_login) . '</td>';
            $output .= '<td>' . esc_html($buyer->return_count) . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody></table>';
        $output .= ujl_pagination($total_returning_buyers, $per_page, $rb_current_page, 'rb_page');
    } else {
        $output .= '<p>No returning buyers found.</p>';
    }
    
    // Display user journey table
    if ($results) {
        $output .= '<h2>User Journey</h2>';
        $output .= '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr><th>User ID</th><th>Action</th><th>Product ID</th><th>Product Name</th><th>Product Tags</th><th>Product Categories</th><th>Timestamp</th></tr></thead>';
        $output .= '<tbody>';
        foreach ($results as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row->user_id) . '</td>';
            $output .= '<td>' . esc_html($row->action) . '</td>';
            $output .= '<td>' . esc_html($row->product_id) . '</td>';
            $output .= '<td>' . esc_html($row->product_name) . '</td>';
            $output .= '<td>' . esc_html($row->product_tags) . '</td>';
            $output .= '<td>' . esc_html($row->product_categories) . '</td>';
            $output .= '<td>' . esc_html($row->timestamp) . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody></table>';
        $output .= ujl_pagination($total_items, $per_page, $current_page, 'journey_page');
    } else {
        $output .= '<p>No user journey data found.</p>';
    }
    $output .= '</div>';

    return $output;
}

// Shortcode to display the conversion analysis table
function ujl_display_conversion_analysis_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_user_journeys';

    // Get filter parameters
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : null;
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : null;
    $checked_out = isset($_GET['checked_out']) ? sanitize_text_field($_GET['checked_out']) : 'all';

    // Pagination
    $per_page = 10;
    $current_page = isset($_GET['ca_page']) ? max(1, intval($_GET['ca_page'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Build query with filters
    $query = "SELECT user_id, action, product_name, product_categories AS category, timestamp FROM $table_name WHERE 1=1";
    $count_query = "SELECT COUNT(*) FROM $table_name WHERE 1=1";
    if ($user_id) {
        $query .= $wpdb->prepare(" AND user_id = %d", $user_id);
        $count_query .= $wpdb->prepare(" AND user_id = %d", $user_id);
    }
    if ($start_date) {
        $query .= $wpdb->prepare(" AND timestamp >= %s", $start_date);
        $count_query .= $wpdb->prepare(" AND timestamp >= %s", $start_date);
    }
    if ($end_date) {
        $query .= $wpdb->prepare(" AND timestamp <= %s", $end_date);
        $count_query .= $wpdb->prepare(" AND timestamp <= %s", $end_date);
    }
    if ($checked_out === 'checked_out') {
        $query .= " AND action = 'Purchased'";
        $count_query .= " AND action = 'Purchased'";
    } elseif ($checked_out === 'not_checked_out') {
        $query .= " AND action = 'Added to cart'";
        $count_query .= " AND action = 'Added to cart'";
    }
    $query .= " ORDER BY timestamp DESC LIMIT $per_page OFFSET $offset";

    $results = $wpdb->get_results($query);
    $total_items = $wpdb->get_var($count_query);

    // Display filter form
    $output = '<form method="get" id="conversion-analysis-filter">';
    $output .= '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
    $output .= '<label for="user_id">User ID:</label>';
    $output .= '<input type="text" name="user_id" id="user_id" value="' . esc_attr($user_id) . '">';
    $output .= '<label for="start_date">Start Date:</label>';
    $output .= '<input type="date" name="start_date" id="start_date" value="' . esc_attr($start_date) . '">';
    $output .= '<label for="end_date">End Date:</label>';
    $output .= '<input type="date" name="end_date" id="end_date" value="' . esc_attr($end_date) . '">';
    $output .= '<label for="checked_out">Status:</label>';
    $output .= '<select name="checked_out" id="checked_out">';
    $output .= '<option value="all"' . selected($checked_out, 'all', false) . '>All</option>';
    $output .= '<option value="checked_out"' . selected($checked_out, 'checked_out', false) . '>Checked Out</option>';
    $output .= '<option value="not_checked_out"' . selected($checked_out, 'not_checked_out', false) . '>Not Checked Out</option>';
    $output .= '</select>';
    $output .= '<input type="submit" value="Filter">';
    $output .= '<button type="button" id="reset-filters">Reset</button>';
    $output .= '</form>';

    // Display results
    if ($results) {
        $output .= '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr><th>User ID</th><th>Action</th><th>Product Name</th><th>Category</th><th>Timestamp</th></tr></thead>';
        $output .= '<tbody>';
        foreach ($results as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row->user_id) . '</td>';
            $output .= '<td>' . esc_html($row->action) . '</td>';
            $output .= '<td>' . esc_html($row->product_name) . '</td>';
            $output .= '<td>' . esc_html($row->category) . '</td>';
            $output .= '<td>' . esc_html($row->timestamp) . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody></table>';
        $output .= ujl_pagination($total_items, $per_page, $current_page, 'ca_page');
    } else {
        $output .= '<p>No conversion analysis data found.</p>';
    }

    // JavaScript for reset button
    $output .= '<script>
    document.getElementById("reset-filters").addEventListener("click", function() {
        document.getElementById("user_id").value = "";
        document.getElementById("start_date").value = "";
        document.getElementById("end_date").value = "";
        document.getElementById("checked_out").value = "all";
        document.getElementById("conversion-analysis-filter").submit();
    });
    </script>';

    return $output;
}

// Add an admin menu for the plugin
add_action('admin_menu', 'ujl_add_admin_menu');
function ujl_add_admin_menu() {
    add_menu_page(
        'User Journey Logger',     // Page title
        'User Journey',            // Menu title
        'manage_options',          // Capability
        'user-journey-logger',     // Menu slug
        'ujl_display_admin_page',  // Function to display the page
        'dashicons-chart-line',    // Icon URL
        6                          // Position
    );
}

// Display the admin page
function ujl_display_admin_page() {
    echo '<div class="wrap">';
    echo '<h1>User Journey Logger</h1>';
    echo do_shortcode('[user_journey_table]');
    echo '</div>';
}
// Add an admin submenu for the conversion analysis
add_action('admin_menu', 'ujl_add_conversion_analysis_menu');
function ujl_add_conversion_analysis_menu() {
    add_submenu_page(
        'user-journey-logger',      // Parent slug
        'Conversion Analysis',      // Page title
        'Conversion Analysis',      // Menu title
        'manage_options',           // Capability
        'conversion-analysis',      // Menu slug
        'ujl_display_conversion_analysis_page' // Function to display the page
    );
}

// Display the conversion analysis admin page
function ujl_display_conversion_analysis_page() {
    echo '<div class="wrap">';
    echo '<h1>Conversion Analysis</h1>';
    echo do_shortcode('[conversion_analysis_table]');
    echo '</div>';
}
// Function to display returning buyers
function ujl_display_returning_buyers() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_user_journeys';

    // Query to get returning buyers
    $query = "
        SELECT user_id, COUNT(*) as visit_count
        FROM $table_name
        GROUP BY user_id
        HAVING COUNT(*) > 1
        ORDER BY visit_count DESC
    ";

    $results = $wpdb->get_results($query);

    // Display results
    if ($results) {
        $output = '<h2>Returning Buyers</h2>';
        $output .= '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr><th>User ID</th><th>Return Count</th></tr></thead>';
        $output .= '<tbody>';
        foreach ($results as $row) {
            $user_info = get_userdata($row->user_id);
            $user_name = $user_info ? $user_info->display_name : 'Unknown User';
            $output .= '<tr>';
            $output .= '<td>' . esc_html($user_name) . '</td>';
            $output .= '<td>' . esc_html($row->visit_count) . '</td>';
            $output .= '</tr>';
        }
        $output .= '</tbody></table>';
    } else {
        $output = '<p>No returning buyers found.</p>';
    }

    return $output;
}

/// Add a new submenu for All Orders
add_action('admin_menu', 'ujl_add_all_orders_menu');
function ujl_add_all_orders_menu() {
    add_submenu_page(
        'user-journey-logger', // Parent slug
        'All Orders',          // Page title
        'All Orders',          // Menu title
        'manage_options',      // Capability
        'all-orders',          // Menu slug
        'ujl_display_all_orders_page' // Function to display the page
    );
}

// Display the All Orders admin page
function ujl_display_all_orders_page() {
    echo '<div class="wrap">';
    echo '<h1>All Orders</h1>';
    echo ujl_display_all_orders_table();
    echo '</div>';
}

// Function to display the All Orders table
function ujl_display_all_orders_table() {
    global $wpdb;

    // Get filter parameters
    $number_of_buyers = isset($_GET['number_of_buyers']) ? intval($_GET['number_of_buyers']) : 0;
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $order_status = isset($_GET['order_status']) ? sanitize_text_field($_GET['order_status']) : '';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $include_refunded = isset($_GET['include_refunded']) ? true : false;

    // Pagination
    $per_page = 10;
    $current_page = isset($_GET['ao_page']) ? max(1, intval($_GET['ao_page'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Build query
    $query = "
        SELECT 
            p.ID as order_id,
            p.post_date as order_date,
            p.post_status as order_status,
            pm1.meta_value as user_id,
            pm2.meta_value as user_email,
            pm3.meta_value as first_name,
            pm4.meta_value as last_name,
            pm5.meta_value as order_total,
            (SELECT GROUP_CONCAT(comment_content SEPARATOR ' | ') 
             FROM {$wpdb->comments} 
             WHERE comment_post_ID = p.ID AND comment_type = 'order_note'
            ) as order_notes
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_customer_user'
        LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_billing_email'
        LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_billing_first_name'
        LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_billing_last_name'
        LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = '_order_total'
        WHERE p.post_type = 'shop_order'
    ";

    $count_query = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'shop_order'";

    // Apply filters
    if ($start_date) {
        $query .= $wpdb->prepare(" AND p.post_date >= %s", $start_date);
        $count_query .= $wpdb->prepare(" AND p.post_date >= %s", $start_date);
    }
    if ($end_date) {
        $query .= $wpdb->prepare(" AND p.post_date <= %s", $end_date);
        $count_query .= $wpdb->prepare(" AND p.post_date <= %s", $end_date);
    }
    if ($order_status) {
        $query .= $wpdb->prepare(" AND p.post_status = %s", 'wc-' . $order_status);
        $count_query .= $wpdb->prepare(" AND p.post_status = %s", 'wc-' . $order_status);
    }
    if ($user_id) {
        $query .= $wpdb->prepare(" AND pm1.meta_value = %d", $user_id);
        $count_query .= $wpdb->prepare(" AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %d)", $user_id);
    }
    if (!$include_refunded) {
        $query .= " AND p.post_status != 'wc-refunded'";
        $count_query .= " AND p.post_status != 'wc-refunded'";
    }

    $query .= " GROUP BY p.ID ORDER BY p.post_date DESC LIMIT $per_page OFFSET $offset";

    $results = $wpdb->get_results($query);
    $total_items = $wpdb->get_var($count_query);

    // Display filter form
    $output = '<form method="get" id="all-orders-filter">';
    $output .= '<input type="hidden" name="page" value="all-orders">';
    $output .= '<label for="number_of_buyers">Number of Buyers:</label>';
    $output .= '<input type="number" name="number_of_buyers" id="number_of_buyers" value="' . esc_attr($number_of_buyers) . '">';
    $output .= '<label for="start_date">Start Date:</label>';
    $output .= '<input type="date" name="start_date" id="start_date" value="' . esc_attr($start_date) . '">';
    $output .= '<label for="end_date">End Date:</label>';
    $output .= '<input type="date" name="end_date" id="end_date" value="' . esc_attr($end_date) . '">';
    $output .= '<label for="order_status">Order Status:</label>';
    $output .= '<select name="order_status" id="order_status">';
    $output .= '<option value="">All</option>';
    $statuses = wc_get_order_statuses();
    foreach ($statuses as $status => $label) {
        $status_without_prefix = str_replace('wc-', '', $status);
        $output .= '<option value="' . esc_attr($status_without_prefix) . '"' . selected($order_status, $status_without_prefix, false) . '>' . esc_html($label) . '</option>';
    }
    $output .= '</select>';
    $output .= '<label for="user_id">User ID:</label>';
    $output .= '<input type="number" name="user_id" id="user_id" value="' . esc_attr($user_id) . '">';
    $output .= '<label for="include_refunded">Include Refunded Orders:</label>';
    $output .= '<input type="checkbox" name="include_refunded" id="include_refunded" ' . checked($include_refunded, true, false) . '>';
    $output .= '<input type="submit" value="Filter">';
    $output .= '<button type="button" id="reset-filters">Reset</button>';
    $output .= '</form>';

    // Display results
    if ($results) {
        $output .= '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr><th>User ID</th><th>Email</th><th>Name</th><th>Order Date</th><th>Order Status</th><th>Order Notes</th><th>Total Spent</th></tr></thead>';
        $output .= '<tbody>';
        $total_spent = 0;
        $total_refunded = 0;
        foreach ($results as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($row->user_id) . '</td>';
            $output .= '<td>' . esc_html($row->user_email) . '</td>';
            $output .= '<td>' . esc_html($row->first_name . ' ' . $row->last_name) . '</td>';
            $output .= '<td>' . esc_html($row->order_date) . '</td>';
            $output .= '<td>' . esc_html(wc_get_order_status_name($row->order_status)) . '</td>';
            $output .= '<td>' . nl2br(esc_html(str_replace(' | ', "\n", $row->order_notes))) . '</td>';
            $output .= '<td>' . wc_price($row->order_total) . '</td>';
            $output .= '</tr>';
            
            if ($row->order_status === 'wc-refunded') {
                $total_refunded += floatval($row->order_total);
            } else {
                $total_spent += floatval($row->order_total);
            }
        }
        $output .= '</tbody></table>';
        $output .= ujl_pagination($total_items, $per_page, $current_page, 'ao_page');

        // Display totals
        $output .= '<h3>Totals</h3>';
        $output .= '<p>Total Spent: ' . wc_price($total_spent) . '</p>';
        if ($include_refunded) {
            $output .= '<p>Total Refunded: ' . wc_price($total_refunded) . '</p>';
            $output .= '<p>Net Total: ' . wc_price($total_spent - $total_refunded) . '</p>';
        }
    } else {
        $output .= '<p>No orders found.</p>';
    }

    // JavaScript for reset button
    $output .= '<script>
    document.getElementById("reset-filters").addEventListener("click", function() {
        document.getElementById("number_of_buyers").value = "";
        document.getElementById("start_date").value = "";
        document.getElementById("end_date").value = "";
        document.getElementById("order_status").value = "";
        document.getElementById("user_id").value = "";
        document.getElementById("include_refunded").checked = false;
        document.getElementById("all-orders-filter").submit();
    });
    </script>';

    return $output;
}

function ujl_pagination($total_items, $per_page, $current_page, $page_param) {
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages <= 1) {
        return '';
    }

    $output = '<div class="tablenav"><div class="tablenav-pages">';
    $output .= paginate_links(array(
        'base' => add_query_arg($page_param, '%#%'),
        'format' => '',
        'prev_text' => __('&laquo;'),
        'next_text' => __('&raquo;'),
        'total' => $total_pages,
        'current' => $current_page
    ));
    $output .= '</div></div>';

    return $output;
}

?>
