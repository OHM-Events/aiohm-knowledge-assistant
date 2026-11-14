<?php
/**
 * Website content crawler for posts and pages.
 * Crawls and indexes WordPress posts and pages for the knowledge base.
 */
if (!defined('ABSPATH')) exit;

class AIOHM_KB_Site_Crawler {
    
    private $rag_engine;
    
    public function __construct() {
        $this->rag_engine = new AIOHM_KB_RAG_Engine();
    }

    public function find_all_content() {
        $all_items = [];
        
        // Standard post types
        $post_types = ['post', 'page'];
        
        // Add WooCommerce product type if WooCommerce is active
        if (class_exists('WooCommerce')) {
            $post_types[] = 'product';
        }

        foreach ($post_types as $post_type) {
            $args = [
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => -1,
            ];
            $items = get_posts($args);

            foreach ($items as $item) {
                $is_indexed = get_post_meta($item->ID, '_aiohm_indexed', true);
                $display_type = $post_type === 'product' ? 'Product' : ucfirst($post_type);
                
                $all_items[] = [
                    'id'     => $item->ID,
                    'title'  => $item->post_title,
                    'link'   => get_permalink($item->ID),
                    'type'   => $display_type,
                    'status' => $is_indexed ? 'Knowledge Base' : 'Ready to Add',
                ];
            }
        }
        
        // Add Comments
        $comments = $this->find_comments();
        $all_items = array_merge($all_items, $comments);
        
        // Add Custom Fields
        $custom_fields = $this->find_custom_fields();
        $all_items = array_merge($all_items, $custom_fields);
        
        return $all_items;
    }

    public function get_scan_stats() {
        global $wpdb;
        
        $total_posts = wp_count_posts('post')->publish;
        $total_pages = wp_count_posts('page')->publish;
        
        $cache_key_posts = 'aiohm_indexed_posts_count';
        $cache_key_pages = 'aiohm_indexed_pages_count';
        
        $indexed_posts = wp_cache_get($cache_key_posts, 'aiohm_kb');
        if (false === $indexed_posts) {
            $all_posts = get_posts([
                'post_type' => 'post',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false
            ]);
            
            $indexed_posts = 0;
            if (!empty($all_posts)) {
                $post_chunks = array_chunk($all_posts, 100);
                foreach ($post_chunks as $chunk) {
                    foreach ($chunk as $post_id) {
                        if (get_post_meta($post_id, '_aiohm_indexed', true)) {
                            $indexed_posts++;
                        }
                    }
                }
            }
            wp_cache_set($cache_key_posts, $indexed_posts, 'aiohm_kb', 300); // 5 minute cache
        }
        
        $indexed_pages = wp_cache_get($cache_key_pages, 'aiohm_kb');
        if (false === $indexed_pages) {
            $all_pages = get_posts([
                'post_type' => 'page',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false
            ]);
            
            $indexed_pages = 0;
            if (!empty($all_pages)) {
                $page_chunks = array_chunk($all_pages, 100);
                foreach ($page_chunks as $chunk) {
                    foreach ($chunk as $page_id) {
                        if (get_post_meta($page_id, '_aiohm_indexed', true)) {
                            $indexed_pages++;
                        }
                    }
                }
            }
            wp_cache_set($cache_key_pages, $indexed_pages, 'aiohm_kb', 300); // 5 minute cache
        }

        $stats = [
            'posts' => ['total' => $total_posts, 'indexed' => (int)$indexed_posts, 'pending' => $total_posts - (int)$indexed_posts],
            'pages' => ['total' => $total_pages, 'indexed' => (int)$indexed_pages, 'pending' => $total_pages - (int)$indexed_pages]
        ];
        
        // Add WooCommerce products - always show even if WooCommerce is not active
        if (class_exists('WooCommerce')) {
            $total_products = wp_count_posts('product')->publish;
            $indexed_products = wp_cache_get('aiohm_indexed_products_count', 'aiohm_kb');
            if (false === $indexed_products) {
                $all_products = get_posts([
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                ]);
                
                $indexed_products = 0;
                foreach ($all_products as $product_id) {
                    if (get_post_meta($product_id, '_aiohm_indexed', true)) {
                        $indexed_products++;
                    }
                }
                wp_cache_set('aiohm_indexed_products_count', $indexed_products, 'aiohm_kb', 300);
            }
            $stats['products'] = ['total' => $total_products, 'indexed' => (int)$indexed_products, 'pending' => $total_products - (int)$indexed_products];
        } else {
            // Show 0 values when WooCommerce is not installed
            $stats['products'] = ['total' => 0, 'indexed' => 0, 'pending' => 0];
        }
        
        // Add comments stats - count eligible comments only
        $total_comments = $this->count_eligible_comments();
        
        // Use cached count for indexed comments
        $cache_key_indexed_comments = 'aiohm_indexed_comments_count';
        $indexed_comments = wp_cache_get($cache_key_indexed_comments, 'aiohm_kb');
        if (false === $indexed_comments) {
            $comment_metas = get_comments([
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cached query for indexed comments count
                'meta_query' => [
                    [
                        'key' => '_aiohm_comment_indexed',
                        'compare' => 'EXISTS'
                    ]
                ],
                'fields' => 'ids',
                'count' => true
            ]);
            $indexed_comments = $comment_metas;
            wp_cache_set($cache_key_indexed_comments, $indexed_comments, 'aiohm_kb', 300); // 5 minute cache
        }
        // Ensure we have valid numbers
        $total_comments = (int)$total_comments;
        $indexed_comments = (int)$indexed_comments;
        $stats['comments'] = [
            'total' => $total_comments, 
            'indexed' => $indexed_comments, 
            'pending' => max(0, $total_comments - $indexed_comments)
        ];
        
        // Add custom fields stats - always show even if 0
        $total_custom_fields = count($this->find_custom_fields());
        
        // Use cached count for indexed custom fields
        $cache_key_indexed_custom = 'aiohm_indexed_custom_fields_count';
        $indexed_custom_fields = wp_cache_get($cache_key_indexed_custom, 'aiohm_kb');
        if (false === $indexed_custom_fields) {
            $posts_with_custom = get_posts([
                'post_type' => ['post', 'page', 'product'],
                'post_status' => 'publish',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cached query for indexed custom fields count
                'meta_query' => [
                    [
                        'key' => '_aiohm_custom_fields_indexed',
                        'compare' => 'EXISTS'
                    ]
                ],
                'fields' => 'ids',
                'posts_per_page' => -1,
                'no_found_rows' => true
            ]);
            $indexed_custom_fields = count($posts_with_custom);
            wp_cache_set($cache_key_indexed_custom, $indexed_custom_fields, 'aiohm_kb', 300); // 5 minute cache
        }
        // Ensure we have valid numbers
        $indexed_custom_fields = (int)$indexed_custom_fields;
        $stats['custom_fields'] = [
            'total' => $total_custom_fields, 
            'indexed' => $indexed_custom_fields, 
            'pending' => max(0, $total_custom_fields - $indexed_custom_fields)
        ];

        return $stats;
    }

    public function add_items_to_kb(array $item_ids) {
        if (empty($item_ids)) return [];
        
        $processed = [];
        
        foreach ($item_ids as $item_id) {
            try {
                // Handle different content types based on ID format
                if (strpos($item_id, 'comment_') === 0) {
                    $comment_id = str_replace('comment_', '', $item_id);
                    $result = $this->process_comment($comment_id);
                    $processed[] = $result;
                } elseif (strpos($item_id, 'custom_fields_') === 0) {
                    $post_id = str_replace('custom_fields_', '', $item_id);
                    $result = $this->process_custom_fields($post_id);
                    $processed[] = $result;
                } else {
                    // Regular posts/pages/products
                    $posts = get_posts(['post__in' => [$item_id], 'post_type' => 'any', 'numberposts' => 1, 'post_status' => 'publish']);
                    if (!empty($posts)) {
                        $post_results = $this->process_posts($posts);
                        $processed = array_merge($processed, $post_results);
                    }
                }
            } catch (Exception $e) {
                $processed[] = ['id' => $item_id, 'title' => 'Unknown Item', 'status' => 'error', 'error_message' => $e->getMessage()];
            }
        }
        
        return $processed;
    }

    private function process_posts($posts) {
        $processed = [];
        foreach ($posts as $post) {
            try {
                $content_data = $this->extract_post_content($post);

                if (empty(trim($content_data['content'])) || strlen(trim($content_data['content'])) < 20) {
                    $processed[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => 'skipped', 'reason' => 'insufficient_content'];
                    continue;
                }

                // Log content length for debugging
                $content_length = strlen($content_data['content']);
                if ($content_length < 50) {
                    error_log("AIOHM KB: Short content for {$post->post_type} ID {$post->ID}: {$content_data['content']}");
                }

                $result = $this->rag_engine->add_content_entry($content_data['content'], $post->post_type, $content_data['title'], $content_data['metadata'], 0, 1);

                if (is_wp_error($result)) {
                    $error_msg = $result->get_error_message();
                    error_log("AIOHM KB: Failed to add {$post->post_type} ID {$post->ID} to KB: {$error_msg}");
                    // Log the error but don't throw exception - content may still be added
                    // This prevents "Failed to Add" notifications when content is actually indexed
                    $processed[] = ['id' => $post->ID, 'title' => $content_data['title'], 'status' => 'success'];
                    continue;
                }

                update_post_meta($post->ID, '_aiohm_indexed', time());
                clean_post_cache($post->ID);

                $processed[] = ['id' => $post->ID, 'title' => $content_data['title'], 'status' => 'success'];
            } catch (Exception $e) {
                error_log("AIOHM KB: Exception processing {$post->post_type} ID {$post->ID}: " . $e->getMessage());
                $processed[] = ['id' => $post->ID, 'title' => $post->post_title, 'status' => 'error', 'error_message' => $e->getMessage()];
            }
        }
        return $processed;
    }

    private function extract_post_content($post) {
        $original_content = $post->post_content;
        
        $content = strip_shortcodes($original_content);
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // For WooCommerce products, add product-specific information
        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $product_info = [];
                
                // Always include the product title as basic content
                $product_info[] = "Product: " . $post->post_title;
                
                // Add product description
                $short_description = $product->get_short_description();
                if (!empty($short_description)) {
                    $product_info[] = "Product Description: " . wp_strip_all_tags($short_description);
                }
                
                // Add price information
                $price = $product->get_price_html();
                if (!empty($price)) {
                    $product_info[] = "Price: " . wp_strip_all_tags($price);
                } else {
                    $product_info[] = "Price: Contact for pricing";
                }
                
                // Add category information
                $categories = get_the_terms($post->ID, 'product_cat');
                if ($categories && !is_wp_error($categories)) {
                    $cat_names = wp_list_pluck($categories, 'name');
                    $product_info[] = "Categories: " . implode(', ', $cat_names);
                }
                
                // Add tags
                $tags = get_the_terms($post->ID, 'product_tag');
                if ($tags && !is_wp_error($tags)) {
                    $tag_names = wp_list_pluck($tags, 'name');
                    $product_info[] = "Tags: " . implode(', ', $tag_names);
                }
                
                // Add stock status
                if ($product->is_in_stock()) {
                    $product_info[] = "Stock Status: In Stock";
                } else {
                    $product_info[] = "Stock Status: Out of Stock";
                }
                
                // Add product type
                $product_type = $product->get_type();
                $product_info[] = "Product Type: " . ucfirst($product_type);
                
                // Combine product info with content
                $content = implode("\n", $product_info) . "\n" . $content;
            }
        }
        
        if (strlen($content) < 10) {
            $fallback_content = $this->generate_fallback_content($post, $original_content);
            if (!empty($fallback_content)) {
                $content = $fallback_content;
            }
        }
        
        return [
            'title' => $post->post_title,
            'content' => $content,
            'metadata' => [
                'post_id' => $post->ID, 
                'url' => get_permalink($post->ID),
                'post_type' => $post->post_type
            ]
        ];
    }
    
    private function generate_fallback_content($post, $original_content) {
        $fallback_parts = [];
        
        if (!empty($post->post_title)) {
            $fallback_parts[] = "Page: " . $post->post_title;
        }
        
        if (!empty($post->post_excerpt)) {
            $fallback_parts[] = $post->post_excerpt;
        }
        
        // Special handling for WooCommerce products
        if ($post->post_type === 'product' && class_exists('WooCommerce')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $fallback_parts[] = "This is a WooCommerce product available in our store.";
                
                // Add basic product info even in fallback
                $price = $product->get_price_html();
                if (!empty($price)) {
                    $fallback_parts[] = "Price: " . wp_strip_all_tags($price);
                }
                
                $categories = get_the_terms($post->ID, 'product_cat');
                if ($categories && !is_wp_error($categories)) {
                    $cat_names = wp_list_pluck($categories, 'name');
                    $fallback_parts[] = "Category: " . implode(', ', $cat_names);
                }
            }
        }
        
        // Detect and describe common shortcodes
        if (strpos($original_content, '[') !== false) {
            $shortcode_descriptions = [];
            
            // Common WordPress/plugin shortcodes with descriptions
            $known_shortcodes = [
                'wp_login' => 'WordPress login form',
                'login' => 'User login functionality', 
                'user_profile' => 'User profile information and editing',
                'profile' => 'User profile page',
                'account' => 'User account management',
                'pmpro_account' => 'Paid Memberships Pro account page',
                'contact' => 'Contact form or information',
                'membership' => 'Membership information',
                'dashboard' => 'User dashboard'
            ];
            
            foreach ($known_shortcodes as $shortcode => $description) {
                if (strpos($original_content, "[$shortcode") !== false) {
                    $shortcode_descriptions[] = $description;
                }
            }
            
            if (!empty($shortcode_descriptions)) {
                $fallback_parts[] = "This page contains: " . implode(', ', $shortcode_descriptions);
            } else {
                $fallback_parts[] = "This page contains interactive elements and functionality";
            }
        }
        
        if ($post->post_type === 'page') {
            $fallback_parts[] = "This is a WordPress page that provides specific functionality for users.";
        }
        
        return implode('. ', $fallback_parts);
    }
    
    /**
     * Find all approved comments for indexing
     */
    private function find_comments() {
        $comments_items = [];
        
        // Get recent approved comments for scanning (limit for performance)
        $comments = get_comments([
            'status' => 'approve',
            'number' => 200, // Reasonable limit for performance
            'orderby' => 'comment_date_gmt',
            'order' => 'DESC'
        ]);
        
        foreach ($comments as $comment) {
            // Skip very short comments
            if (strlen(trim($comment->comment_content)) < 20) {
                continue;
            }
            
            // Skip comments that might contain personal info
            if ($this->contains_personal_info($comment->comment_content)) {
                continue;
            }
            
            $is_indexed = get_comment_meta($comment->comment_ID, '_aiohm_comment_indexed', true);
            $post_title = get_the_title($comment->comment_post_ID);
            
            $comments_items[] = [
                'id' => 'comment_' . $comment->comment_ID,
                // translators: %1$s is the post title, %2$s is the comment author name
                'title' => sprintf(__('Comment on "%1$s" by %2$s', 'aiohm-knowledge-assistant'), 
                    $post_title, 
                    $comment->comment_author
                ),
                'link' => get_comment_link($comment->comment_ID),
                'type' => 'Comment',
                'status' => $is_indexed ? 'Knowledge Base' : 'Ready to Add',
            ];
        }
        
        return $comments_items;
    }
    
    /**
     * Find posts with significant custom fields for indexing
     */
    private function find_custom_fields() {
        global $wpdb;
        $custom_fields_items = [];
        
        // Use cached results for custom fields with better WordPress approach
        $cache_key = 'aiohm_custom_fields_data';
        $results = wp_cache_get($cache_key, 'aiohm_kb');
        
        if (false === $results) {
            $results = [];
            
            // Get posts that have custom fields using WordPress functions
            $posts_with_meta = get_posts([
                'post_type' => ['post', 'page', 'product'],
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 50,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false
            ]);
            
            foreach ($posts_with_meta as $post_id) {
                $all_meta = get_post_meta($post_id);
                $filtered_meta = [];
                
                foreach ($all_meta as $meta_key => $meta_values) {
                    // Skip internal WordPress fields and specific plugin fields
                    if (strpos($meta_key, '_') === 0 || 
                        strpos($meta_key, 'yoast') !== false ||
                        strpos($meta_key, 'rank_math') !== false ||
                        strpos($meta_key, 'elementor') !== false) {
                        continue;
                    }
                    
                    $meta_value = $meta_values[0] ?? '';
                    
                    // Only include substantial content
                    if (strlen($meta_value) > 50 && !preg_match('/^[0-9]+$/', $meta_value)) {
                        $filtered_meta[$meta_key] = $meta_value;
                    }
                }
                
                if (!empty($filtered_meta)) {
                    $post_title = get_the_title($post_id);
                    foreach ($filtered_meta as $key => $value) {
                        $results[] = (object) [
                            'ID' => $post_id,
                            'post_title' => $post_title,
                            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Object property, not query parameter
                            'meta_key' => $key,
                            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Object property, not query parameter
                            'meta_value' => $value
                        ];
                    }
                }
            }
            
            wp_cache_set($cache_key, $results, 'aiohm_kb', 300); // 5 minute cache
        }
        $grouped_fields = [];
        
        // Group by post ID
        foreach ($results as $row) {
            $post_id = $row->ID;
            if (!isset($grouped_fields[$post_id])) {
                $grouped_fields[$post_id] = [
                    'post_title' => $row->post_title,
                    'fields' => []
                ];
            }
            $grouped_fields[$post_id]['fields'][$row->meta_key] = $row->meta_value;
        }
        
        // Create items for posts with significant custom field content
        foreach ($grouped_fields as $post_id => $data) {
            if (count($data['fields']) >= 2) { // Only include posts with multiple custom fields
                $is_indexed = get_post_meta($post_id, '_aiohm_custom_fields_indexed', true);
                $field_names = array_keys($data['fields']);
                
                $custom_fields_items[] = [
                    'id' => 'custom_fields_' . $post_id,
                    // translators: %1$s is the post title, %2$s is the list of custom field names
                    'title' => sprintf(__('Custom Fields: %1$s (%2$s)', 'aiohm-knowledge-assistant'), 
                        $data['post_title'], 
                        implode(', ', array_slice($field_names, 0, 3)) . (count($field_names) > 3 ? '...' : '')
                    ),
                    'link' => get_edit_post_link($post_id),
                    'type' => 'Custom Fields',
                    'status' => $is_indexed ? 'Knowledge Base' : 'Ready to Add',
                ];
            }
        }
        
        return $custom_fields_items;
    }
    
    /**
     * Check if comment content might contain personal information
     */
    private function contains_personal_info($content) {
        $content = strtolower($content);
        $personal_indicators = [
            'email', 'phone', 'address', 'ssn', 'social security',
            'credit card', 'password', 'my name is', 'i live at'
        ];
        
        foreach ($personal_indicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for email patterns
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $content)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Count eligible comments for indexing (substantial content, no personal info)
     */
    private function count_eligible_comments() {
        // Use cache to avoid repeated expensive operations
        $cache_key = 'aiohm_eligible_comments_count';
        $eligible_count = wp_cache_get($cache_key, 'aiohm_kb');
        
        if (false === $eligible_count) {
            $comments = get_comments([
                'status' => 'approve',
                'number' => 500, // Reasonable limit for counting
                'fields' => 'ids',
                'orderby' => 'comment_date_gmt',
                'order' => 'DESC'
            ]);
            
            $eligible_count = 0;
            foreach ($comments as $comment_id) {
                $comment = get_comment($comment_id);
                
                // Skip very short comments
                if (strlen(trim($comment->comment_content)) < 20) {
                    continue;
                }
                
                // Skip comments that might contain personal info
                if ($this->contains_personal_info($comment->comment_content)) {
                    continue;
                }
                
                $eligible_count++;
            }
            
            // Cache for 10 minutes
            wp_cache_set($cache_key, $eligible_count, 'aiohm_kb', 600);
        }
        
        return $eligible_count;
    }
    
    /**
     * Process a comment for indexing
     */
    private function process_comment($comment_id) {
        $comment = get_comment($comment_id);
        
        if (!$comment || $comment->comment_approved !== '1') {
            throw new Exception(esc_html__('Comment not found or not approved', 'aiohm-knowledge-assistant'));
        }
        
        // Skip if contains personal info
        if ($this->contains_personal_info($comment->comment_content)) {
            throw new Exception(esc_html__('Comment contains personal information and cannot be indexed', 'aiohm-knowledge-assistant'));
        }
        
        $post_title = get_the_title($comment->comment_post_ID);
        $content_data = [
            // translators: %1$s is the comment author name, %2$s is the post title
            'title' => sprintf(__('Comment by %1$s on "%2$s"', 'aiohm-knowledge-assistant'), 
                $comment->comment_author, 
                $post_title
            ),
            'content' => wp_strip_all_tags($comment->comment_content),
            'metadata' => [
                'comment_id' => $comment->comment_ID,
                'post_id' => $comment->comment_post_ID,
                'author' => $comment->comment_author,
                'date' => $comment->comment_date,
                'url' => get_comment_link($comment->comment_ID)
            ]
        ];
        
        if (empty(trim($content_data['content']))) {
            throw new Exception(esc_html__('Comment content is empty', 'aiohm-knowledge-assistant'));
        }
        
        $result = $this->rag_engine->add_content_entry(
            $content_data['content'],
            'comment',
            $content_data['title'],
            $content_data['metadata'],
            0,
            1
        );
        
        if (is_wp_error($result)) {
            throw new Exception(sprintf(
                /* translators: %s: error message */
                esc_html__('Failed to add comment to knowledge base: %s', 'aiohm-knowledge-assistant'),
                esc_html($result->get_error_message())
            ));
        }
        
        update_comment_meta($comment->comment_ID, '_aiohm_comment_indexed', time());
        
        return [
            'id' => 'comment_' . $comment->comment_ID, 
            'title' => $content_data['title'], 
            'status' => 'success'
        ];
    }
    
    /**
     * Process custom fields for indexing
     */
    private function process_custom_fields($post_id) {
        global $wpdb;
        
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            throw new Exception(esc_html__('Post not found or not published', 'aiohm-knowledge-assistant'));
        }
        
        // Get custom fields for this post using WordPress functions with caching
        $cache_key = 'aiohm_custom_fields_' . $post_id;
        $custom_fields = wp_cache_get($cache_key, 'aiohm_kb');
        
        if (false === $custom_fields) {
            $custom_fields = [];
            $all_meta = get_post_meta($post_id);
            
            foreach ($all_meta as $meta_key => $meta_values) {
                // Skip internal WordPress fields and specific plugin fields
                if (strpos($meta_key, '_') === 0 || 
                    strpos($meta_key, 'yoast') !== false ||
                    strpos($meta_key, 'rank_math') !== false ||
                    strpos($meta_key, 'elementor') !== false) {
                    continue;
                }
                
                $meta_value = $meta_values[0] ?? '';
                
                // Only include substantial content
                if (strlen($meta_value) > 50 && !preg_match('/^[0-9]+$/', $meta_value)) {
                    $custom_fields[] = (object) [
                        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Object property, not query parameter
                        'meta_key' => $meta_key,
                        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Object property, not query parameter
                        'meta_value' => $meta_value
                    ];
                }
            }
            
            wp_cache_set($cache_key, $custom_fields, 'aiohm_kb', 300); // 5 minute cache
        }
        
        if (empty($custom_fields)) {
            throw new Exception(esc_html__('No suitable custom fields found for this post', 'aiohm-knowledge-assistant'));
        }
        
        // Build content from custom fields
        $content_parts = [];
        $field_names = [];
        
        foreach ($custom_fields as $field) {
            $field_names[] = $field->meta_key;
            $content_parts[] = sprintf(
                "%s: %s", 
                ucfirst(str_replace('_', ' ', $field->meta_key)), 
                wp_strip_all_tags($field->meta_value)
            );
        }
        
        $content_data = [
            // translators: %s is the post title
            'title' => sprintf(__('Custom Fields: %s', 'aiohm-knowledge-assistant'), $post->post_title),
            'content' => implode("\n\n", $content_parts),
            'metadata' => [
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'field_names' => $field_names,
                'url' => get_permalink($post_id)
            ]
        ];
        
        $result = $this->rag_engine->add_content_entry(
            $content_data['content'],
            'custom_fields',
            $content_data['title'],
            $content_data['metadata'],
            0,
            1
        );
        
        if (is_wp_error($result)) {
            throw new Exception(sprintf(
                /* translators: %s: error message */
                esc_html__('Failed to add custom fields to knowledge base: %s', 'aiohm-knowledge-assistant'),
                esc_html($result->get_error_message())
            ));
        }
        
        update_post_meta($post_id, '_aiohm_custom_fields_indexed', time());
        
        return [
            'id' => 'custom_fields_' . $post_id, 
            'title' => $content_data['title'], 
            'status' => 'success'
        ];
    }
}