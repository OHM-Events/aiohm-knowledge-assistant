<?php
/**
 * Media Library (Uploads) Crawler.
 * This version fixes the logic for stats calculation to correctly identify all supported files.
 */
if (!defined('ABSPATH')) exit;

class AIOHM_KB_Uploads_Crawler {

    private $rag_engine;
    private $readable_extensions = ['json', 'txt', 'csv', 'pdf', 'doc', 'docx', 'md', 'html', 'htm', 'xlsx', 'xls'];
    
    /**
     * Maximum file size in bytes (10MB)
     */
    private $max_file_size = 10485760;
    
    /**
     * Allowed MIME types for security
     */
    private $allowed_mime_types = [
        'json' => 'application/json',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'md' => 'text/markdown',
        'html' => 'text/html',
        'htm' => 'text/html',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel'
    ];

    public function __construct() {
        $this->rag_engine = new AIOHM_KB_RAG_Engine();
    }
    
    /**
     * Debug method to help identify file processing issues
     * @param string $file_path Path to the file
     * @return array Debug information
     */
    private function debug_file_info($file_path) {
        $info = [
            'exists' => file_exists($file_path),
            'readable' => is_readable($file_path),
            'size' => file_exists($file_path) ? filesize($file_path) : 0,
            'extension' => strtolower(pathinfo($file_path, PATHINFO_EXTENSION)),
            'mime_type' => wp_check_filetype($file_path)['type'],
            'parser_factory_exists' => class_exists('AIOHM_Parser_Factory')
        ];
        
        return $info;
    }

    public function get_stats() {
        $stats = ['total_files' => 0, 'indexed_files' => 0, 'pending_files' => 0, 'by_type' => []];
        $all_files_with_status = $this->find_all_supported_attachments(); // Use the new method

        $stats['total_files'] = count($all_files_with_status);

        foreach ($all_files_with_status as $file_info) {
            $ext = strtolower(pathinfo($file_info['path'], PATHINFO_EXTENSION));
            if (!isset($stats['by_type'][$ext])) {
                $stats['by_type'][$ext] = ['count' => 0, 'indexed' => 0, 'pending' => 0, 'size' => 0];
            }

            $stats['by_type'][$ext]['count']++;
            $stats['by_type'][$ext]['size'] += filesize($file_info['path']);

            if ($file_info['status'] === 'Knowledge Base') {
                $stats['indexed_files']++;
                $stats['by_type'][$ext]['indexed']++;
            } else {
                $stats['pending_files']++;
                $stats['by_type'][$ext]['pending']++;
            }
        }
        return $stats;
    }

    public function find_all_supported_attachments() {
        $all_items = [];
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => -1,
            'post_status'    => 'inherit',
            'cache_results'  => false, // Prevent caching of the post query itself
            'no_found_rows'  => true,  // Optimization
            'update_post_meta_cache' => false, // Prevent populating meta cache during this query
            'update_post_term_cache' => false  // Prevent populating term cache during this query
        ]);

        foreach ($attachments as $attachment) {
            // IMPORTANT: Clear the object cache for the specific post/attachment before getting its meta.
            // This is a strong measure against persistent caching issues.
            clean_post_cache($attachment->ID);

            $file_path = get_attached_file($attachment->ID);
            if ($file_path && file_exists($file_path)) {
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                if (in_array($ext, $this->readable_extensions)) {
                    $is_indexed = get_post_meta($attachment->ID, '_aiohm_indexed', true);
                    
                    // Double-check if content actually exists in database
                    if ($is_indexed) {
                        $item_status = $this->verify_content_in_database($attachment->ID) ? 'Knowledge Base' : 'Ready to Add';
                    } else {
                        $item_status = 'Ready to Add';
                    }
                    $all_items[] = [
                        'id'     => $attachment->ID,
                        'title'  => $attachment->post_title ?: basename($file_path),
                        'link'   => wp_get_attachment_url($attachment->ID),
                        'type'   => wp_check_filetype($file_path)['type'], // Use wp_check_filetype for MIME type
                        'status' => $item_status,
                        'path'   => $file_path // Added path for stats calculation
                    ];
                }
            }
        }
        return $all_items;
    }

    public function find_pending_attachments() {
        $all_supported = $this->find_all_supported_attachments();
        $pending = array_filter($all_supported, function($item) {
            return $item['status'] === 'Ready to Add';
        });
        return $pending;
    }

    public function add_attachments_to_kb(array $attachment_ids) {
        if (empty($attachment_ids)) {
            return [];
        }

        $immediate_count = 0;
        $queued_count = 0;
        
        foreach ($attachment_ids as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            $file_size = $file_path ? filesize($file_path) : 0;
            
            // Process immediately if file is under 50KB
            if ($file_size > 0 && $file_size < 50000) {
                try {
                    $this->process_file_immediately($attachment_id);
                    $immediate_count++;
                } catch (Exception $e) {
                    // Fall back to background processing if immediate fails
                    $this->update_attachment_processing_state($attachment_id, 'pending');
                    $queued_count++;
                }
            } else {
                // Use background processing for larger files
                $this->update_attachment_processing_state($attachment_id, 'pending');
                $queued_count++;
            }
        }

        // Schedule background processing if needed
        if ($queued_count > 0) {
            if (!wp_next_scheduled('aiohm_process_file_queue')) {
                wp_schedule_single_event(time() + 5, 'aiohm_process_file_queue');
            }
        }

        $message = "";
        if ($immediate_count > 0) {
            $message .= "Processed {$immediate_count} files immediately. ";
        }
        if ($queued_count > 0) {
            $message .= "Queued {$queued_count} files for background processing.";
        }

        return ['status' => 'success', 'message' => trim($message)];
    }

    /**
     * Update the processing state of an attachment.
     *
     * @param int    $attachment_id The ID of the attachment.
     * @param string $status        The new status (e.g., 'pending', 'parsing', 'embedding', 'completed', 'failed').
     * @param array  $data          Additional data to store with the state.
     */
    private function update_attachment_processing_state($attachment_id, $status, $data = []) {
        $state = [
            'status'        => $status,
            'last_updated'  => time(),
            'progress'      => $data['progress'] ?? 0,
            'total_chunks'  => $data['total_chunks'] ?? 0,
            'error'         => $data['error'] ?? null,
        ];
        update_post_meta($attachment_id, '_aiohm_processing_state', $state);
    }

    public function process_background_queue() {
        $start_time = time();
        // Run for a maximum of 45 seconds to process more files per run.
        $time_limit = 45;

        // Cache the attachment query to reduce database load
        $cache_key = 'aiohm_attachments_to_process';
        $attachments_to_process = wp_cache_get($cache_key);
        
        if (false === $attachments_to_process) {
            // meta_query required to find attachments needing processing - no alternative exists
            $attachments_to_process = get_posts([
                'post_type' => 'attachment',
                'posts_per_page' => 50, // Limit to prevent memory issues
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'meta_query' => [
                    [
                        'key' => '_aiohm_processing_state',
                        'value'   => 'completed',
                        'compare' => '!=',
                    ],
                ],
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids', // Only get IDs to reduce memory usage
            ]);
            
            // Cache for 2 minutes to reduce frequent meta queries
            wp_cache_set($cache_key, $attachments_to_process, '', 120);
        }

        if (empty($attachments_to_process)) {
            return;
        }

        foreach ($attachments_to_process as $attachment_id) {
            if ((time() - $start_time) > $time_limit) {
                break; // Time limit reached
            }
            $this->process_attachment($attachment_id);
        }

        // Reschedule if there are still items to process.
        if (count($attachments_to_process) > 0) {
            if (!wp_next_scheduled('aiohm_process_file_queue')) {
                wp_schedule_single_event(time() + 15, 'aiohm_process_file_queue');
            }
        }
    }

    private function process_attachment($attachment_id) {
        $state = get_post_meta($attachment_id, '_aiohm_processing_state', true);
        if (empty($state)) {
            return;
        }


        try {
            switch ($state['status']) {
                case 'pending':
                    $this->update_attachment_processing_state($attachment_id, 'parsing', ['progress' => 0]);
                    break;

                case 'parsing':
                    $result = $this->process_file_chunk($attachment_id, $state);
                    if ($result['status'] === 'parsed') {
                        $this->update_attachment_processing_state($attachment_id, 'embedding', ['progress' => 0, 'total_chunks' => $result['total_chunks']]);
                    } else {
                        $this->update_attachment_processing_state($attachment_id, 'parsing', ['progress' => $result['progress']]);
                    }
                    break;

                case 'embedding':
                    $this->process_embedding_chunks($attachment_id, $state);
                    break;
            }
        } catch (Exception $e) {
            $this->update_attachment_processing_state($attachment_id, 'failed', ['error' => $e->getMessage()]);
        }
    }

    private function process_embedding_chunks($attachment_id, $state) {
        $chunks_to_process_per_run = 20;
        $processed_in_this_run = 0;

        wp_suspend_cache_invalidation(true);

        for ($i = 0; $i < $chunks_to_process_per_run; $i++) {
            $chunk_index = $state['progress'] + $i;
            if ($chunk_index >= $state['total_chunks']) {
                break; // All chunks processed
            }

            $this->rag_engine->add_entry_chunk($attachment_id, $chunk_index);
            $processed_in_this_run++;
        }

        wp_suspend_cache_invalidation(false);

        $new_progress = $state['progress'] + $processed_in_this_run;

        if ($new_progress >= $state['total_chunks']) {
            $this->update_attachment_processing_state($attachment_id, 'completed');
            update_post_meta($attachment_id, '_aiohm_indexed', time());
            // Clear cache to ensure status updates are reflected immediately
            clean_post_cache($attachment_id);
            wp_cache_delete($attachment_id, 'posts');
            // Clean up transient files
            $transient_dir = $this->get_transient_directory();
            $temp_file_path = $transient_dir . '/' . $attachment_id . '.txt';
            $chunks_file_path = $transient_dir . '/' . $attachment_id . '_chunks.json';
            if (file_exists($temp_file_path)) {
                wp_delete_file($temp_file_path);
            }
            if (file_exists($chunks_file_path)) {
                wp_delete_file($chunks_file_path);
            }
        } else {
            $this->update_attachment_processing_state($attachment_id, 'embedding', ['progress' => $new_progress, 'total_chunks' => $state['total_chunks']]);
        }
    }

    private function process_file_chunk($attachment_id, $state) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            throw new Exception('File not found.');
        }

        $parser = AIOHM_Parser_Factory::get_parser($file_path);
        if (!$parser) {
            throw new Exception('No suitable parser found.');
        }

        // Parse the file content
        $result = $parser->parse($file_path);
        
        // Store parsed content in transient file
        $transient_dir = $this->get_transient_directory();
        $temp_file_path = $transient_dir . '/' . $attachment_id . '.txt';
        file_put_contents($temp_file_path, $result['content']);

        // Prepare content for chunked processing
        $chunk_data = $this->rag_engine->prepare_content_for_chunking(
            $result['content'], 
            get_the_title($attachment_id), 
            $attachment_id
        );
        
        if (empty($chunk_data['chunks'])) {
            throw new Exception('No chunks created from file content.');
        }
        
        // Store chunks for background processing
        $chunks_file_path = $transient_dir . '/' . $attachment_id . '_chunks.json';
        file_put_contents($chunks_file_path, json_encode($chunk_data['chunks']));
        
        // Store content_id for later use
        update_post_meta($attachment_id, '_aiohm_content_id', $chunk_data['content_id']);

        return ['status' => 'parsed', 'progress' => 1, 'total_chunks' => $chunk_data['chunk_count']];
    }

    private function get_transient_directory() {
        $upload_dir = wp_upload_dir();
        $transient_dir = $upload_dir['basedir'] . '/aiohm-transients';
        if (!file_exists($transient_dir)) {
            wp_mkdir_p($transient_dir);
        }
        return $transient_dir;
    }
    
    /**
     * Verify if attachment content actually exists in the knowledge base database
     * @param int $attachment_id Attachment ID to verify
     * @return bool True if content exists in database
     */
    private function verify_content_in_database($attachment_id) {
        global $wpdb;
        
        // Check if any chunks exist for this attachment in the vector database
        $cache_key = 'aiohm_attachment_content_' . $attachment_id;
        $count = wp_cache_get($cache_key);
        
        if (false === $count) {
            $table_name = $wpdb->prefix . 'aiohm_vector_entries';
            
            // Direct database query required for vector embeddings table - no WordPress equivalent exists
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `" . esc_sql($table_name) . "` WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.attachment_id')) AS UNSIGNED) = %d",
                $attachment_id
            ));

            if ($count === null || !empty($wpdb->last_error)) {
                $like_numeric = '%"attachment_id": ' . intval($attachment_id) . '%';
                $like_string  = '%"attachment_id": "' . intval($attachment_id) . '"%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback query for environments without MySQL JSON functions, necessary for compatibility
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `" . esc_sql($table_name) . "` WHERE metadata LIKE %s OR metadata LIKE %s",
                    $like_numeric,
                    $like_string
                ));
            }
            
            // Cache for 5 minutes to reduce database queries
            wp_cache_set($cache_key, $count, '', 300);
        }
        
        if ($count > 0) {
            return true;
        }
        
        // If no chunks found, remove the _aiohm_indexed meta and clear cache
        delete_post_meta($attachment_id, '_aiohm_indexed');
        clean_post_cache($attachment_id);
        wp_cache_delete($attachment_id, 'posts');
        
        return false;
    }
    
    /**
     * Process file immediately without background queuing (for small files)
     * @param int $attachment_id Attachment ID to process
     * @throws Exception If processing fails
     */
    private function process_file_immediately($attachment_id) {
        // Step 1: Parse file
        $this->update_attachment_processing_state($attachment_id, 'parsing');
        $result = $this->process_file_chunk($attachment_id, ['status' => 'parsing']);
        
        if ($result['status'] !== 'parsed') {
            throw new Exception('Failed to parse file');
        }
        
        // Step 2: Process all embeddings immediately 
        $this->update_attachment_processing_state($attachment_id, 'embedding', [
            'progress' => 0, 
            'total_chunks' => $result['total_chunks']
        ]);
        
        // Process all chunks in one go for small files
        for ($i = 0; $i < $result['total_chunks']; $i++) {
            $this->rag_engine->add_entry_chunk($attachment_id, $i);
        }
        
        // Step 3: Mark as completed
        $this->update_attachment_processing_state($attachment_id, 'completed');
        update_post_meta($attachment_id, '_aiohm_indexed', time());
        clean_post_cache($attachment_id);
        wp_cache_delete($attachment_id, 'posts');
        
        // Clean up transient files
        $transient_dir = $this->get_transient_directory();
        $temp_file_path = $transient_dir . '/' . $attachment_id . '.txt';
        $chunks_file_path = $transient_dir . '/' . $attachment_id . '_chunks.json';
        if (file_exists($temp_file_path)) {
            wp_delete_file($temp_file_path);
        }
        if (file_exists($chunks_file_path)) {
            wp_delete_file($chunks_file_path);
        }
    }

    private function get_supported_attachments($get_all_for_stats = false) {
        // This method's logic is largely superseded by find_all_supported_attachments()
        // It should ideally be refactored or removed if no longer directly used.
        // For now, it will simply return all supported files without status.
        $attachments = get_posts(['post_type' => 'attachment', 'posts_per_page' => -1, 'post_status' => 'inherit']);
        $file_infos = [];

        foreach ($attachments as $attachment) {
            $path = get_attached_file($attachment->ID);
            if ($path && file_exists($path)) {
                 $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                 if (in_array($ext, $this->readable_extensions)) {
                     $file_infos[] = ['path' => $path, 'id' => $attachment->ID];
                 }
            }
        }
        return $file_infos;
    }

    private function process_file($file_path, $attachment_id) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return null;
        }

        $attachment_post = get_post($attachment_id);
        if (!$attachment_post) {
            return null;
        }

        $file_info = pathinfo($file_path);
        $extension = strtolower($file_info['extension'] ?? '');
        
        // Try to get full parsed data from new parser architecture first
        $parsed_data = null;
        $sanitized_content = '';
        $parsed_title = '';
        $parser_metadata = [];
        
        try {
            if (class_exists('AIOHM_Parser_Factory')) {
                // Ensure parser factory is initialized
                AIOHM_Parser_Factory::init();
                
                $parsed_data = AIOHM_Parser_Factory::parse_file($file_path, $extension);
                
                if ($parsed_data && !empty($parsed_data['content'])) {
                    $sanitized_content = $parsed_data['content'];
                    $parsed_title = $parsed_data['title'] ?? '';
                    $parser_metadata = $parsed_data['metadata'] ?? [];
                } else {
                }
            } else {
            }
        } catch (Exception $e) {
            // Log the parsing error for debugging
        }
        
        // Fallback to legacy extraction if new parsers failed
        if (empty($sanitized_content)) {
            $sanitized_content = $this->extract_file_content($file_path, $extension);
            if (empty($sanitized_content)) {
                // Additional fallback for PDFs - try direct parser instantiation like manage page
                if ($extension === 'pdf') {
                    try {
                        if (class_exists('AIOHM_Clean_PDF_Parser')) {
                            $parser = new AIOHM_Clean_PDF_Parser();
                            $result = $parser->parse($file_path);
                            $sanitized_content = $result['content'] ?? '';
                            if (!empty($sanitized_content)) {
                                $parsed_title = $result['title'] ?? '';
                                $parser_metadata = $result['metadata'] ?? [];
                            }
                        }
                    } catch (Exception $e) {
                    }
                }
                
                // If still empty, create descriptive content like manage page does
                if (empty($sanitized_content)) {
                    $filename = basename($file_path);
                    $sanitized_content = "File: {$filename} (content extraction failed - file may be scanned, encrypted, or use unsupported formatting)";
                }
            }
        }
        
        // Determine the best title (priority: parsed > attachment title > filename)
        $title = '';
        if (!empty($parsed_title)) {
            $title = $parsed_title;
        } elseif (!empty($attachment_post->post_title)) {
            $title = $attachment_post->post_title;
        } else {
            $title = $file_info['filename'];
        }
        
        // Merge parser metadata with basic file metadata
        $base_metadata = [
            'source_type' => 'upload_file',
            'attachment_id' => $attachment_id,
            'file_path' => $file_path,
            'file_extension' => $extension,
            'file_size' => filesize($file_path),
            'mime_type' => $attachment_post->post_mime_type,
            'upload_date' => $attachment_post->post_date,
        ];
        
        // Merge with parser metadata (parser metadata takes precedence for conflicts)
        $metadata = array_merge($base_metadata, $parser_metadata);
        
        // Prepare file data
        $file_data = [
            'content' => $sanitized_content,
            'type' => strtoupper($extension), // Use actual file extension as type
            'title' => $title,
            'metadata' => $metadata
        ];

        return $file_data;
    }
    
    /**
     * Extract content from files based on their type using new parser architecture
     * @param string $file_path Full path to the file
     * @param string $extension File extension
     * @return string Extracted and sanitized content
     */
    private function extract_file_content($file_path, $extension) {
        try {
            // Use the new parser factory system
            if (class_exists('AIOHM_Parser_Factory')) {
                // Ensure parser factory is initialized
                AIOHM_Parser_Factory::init();
                
                $parsed_data = AIOHM_Parser_Factory::parse_file($file_path, $extension);
                if ($parsed_data && !empty($parsed_data['content'])) {
                    return $parsed_data['content'];
                }
            }
            
            // Fallback to legacy parsing methods if new parsers fail
            return $this->legacy_extract_file_content($file_path, $extension);
            
        } catch (Exception $e) {
            // Log the extraction error for debugging
            
            // Final fallback to legacy methods
            return $this->legacy_extract_file_content($file_path, $extension);
        }
    }
    
    /**
     * Legacy file extraction methods (kept as fallback)
     * @param string $file_path Full path to the file
     * @param string $extension File extension
     * @return string Extracted and sanitized content
     */
    private function legacy_extract_file_content($file_path, $extension) {
        switch ($extension) {
            case 'pdf':
                return $this->extract_pdf_content($file_path);
                
            case 'doc':
            case 'docx':
                return $this->extract_doc_content($file_path, $extension);
                
            case 'txt':
            case 'md':
            case 'json':
            case 'csv':
                // Read text-based files directly
                $content = file_get_contents($file_path);
                if ($content === false) {
                    return '';
                }
                return $this->sanitize_file_content($content, $extension);
                
            case 'html':
            case 'htm':
                // Basic HTML extraction for legacy fallback
                $content = file_get_contents($file_path);
                if ($content === false) {
                    return '';
                }
                $content = wp_strip_all_tags($content);
                return $this->sanitize_file_content($content, $extension);
                
            case 'xlsx':
            case 'xls':
                // Basic spreadsheet fallback - return descriptive content
                $filename = basename($file_path);
                return "Spreadsheet: {$filename}. This file contains tabular data. For best results, please convert to CSV format and re-upload.";
                
            default:
                return '';
        }
    }
    
    /**
     * Extract content from PDF files
     * @param string $file_path Path to PDF file
     * @return string Extracted text content
     */
    private function extract_pdf_content($file_path) {
        try {
            // Method 1: Try to use WordPress's built-in functions if available
            if (function_exists('wp_read_pdf_content')) {
                $content = wp_read_pdf_content($file_path);
                if (!empty($content)) {
                    return sanitize_textarea_field($content);
                }
            }
            
            // Method 2: Simple text extraction attempt for PDF files
            // This is a basic fallback - many PDFs won't work with this method
            $content = file_get_contents($file_path);
            if ($content === false) {
                return '';
            }
            
            // Try to extract readable text from PDF (very basic approach)
            // This will only work for simple text-based PDFs
            if (preg_match_all('/\((.*?)\)/s', $content, $matches)) {
                $filtered_text = '';
                foreach ($matches[1] as $match) {
                    $decoded = $this->decode_pdf_string_legacy($match);
                    if ($this->is_valid_pdf_content_legacy($decoded)) {
                        $filtered_text .= $decoded . ' ';
                    }
                }
                
                $text = preg_replace('/\s+/', ' ', trim($filtered_text)); // Normalize whitespace
                
                if (strlen($text) > 50) { // Only return if we got substantial text
                    return sanitize_textarea_field($text);
                }
            }
            
            // Fallback: Create descriptive content
            $filename = basename($file_path);
            return "PDF Document: {$filename}. This PDF file has been uploaded and is available for reference. For best results, please extract text content manually and upload as a text file.";
            
        } catch (Exception $e) {
            $filename = basename($file_path);
            return "PDF Document: {$filename} (content extraction failed)";
        }
    }
    
    /**
     * Extract content from DOC/DOCX files
     * @param string $file_path Path to document file
     * @param string $extension File extension (doc or docx)
     * @return string Extracted text content
     */
    private function extract_doc_content($file_path, $extension) {
        try {
            if ($extension === 'docx') {
                // Try to extract from DOCX using ZipArchive
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    if ($zip->open($file_path) === TRUE) {
                        $content = $zip->getFromName('word/document.xml');
                        if ($content !== false) {
                            // Strip XML tags to get plain text
                            $content = wp_strip_all_tags($content);
                            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                            $content = preg_replace('/\s+/', ' ', $content);
                            $content = trim($content);
                            $zip->close();
                            
                            if (strlen($content) > 20) {
                                return sanitize_textarea_field($content);
                            }
                        }
                        $zip->close();
                    }
                }
            }
            
            // Fallback for DOC files or failed DOCX extraction
            $filename = basename($file_path);
            return "Word Document: {$filename}. This document has been uploaded and is available for reference. For best results, please save as text (.txt) and re-upload.";
            
        } catch (Exception $e) {
            $filename = basename($file_path);
            return "Document: {$filename} (content extraction failed)";
        }
    }
    
    /**
     * Sanitize file content based on file type
     * @param string $content Raw file content
     * @param string $extension File extension
     * @return string Sanitized content
     */
    private function sanitize_file_content($content, $extension) {
        $content = str_replace("\0", '', $content);
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $content);
        
        switch ($extension) {
            case 'json':
                // For JSON, decode and re-encode to ensure structure is safe
                $decoded = json_decode($content, true);
                if ($decoded !== null) {
                    // Recursively sanitize JSON values
                    $decoded = $this->sanitize_json_recursive($decoded);
                    return wp_json_encode($decoded);
                }
                return '';
                
            case 'txt':
            case 'md':
                // For text and markdown, strip dangerous HTML but allow basic formatting
                return wp_kses($content, [
                    'p' => [],
                    'br' => [],
                    'strong' => [],
                    'em' => [],
                    'ul' => [],
                    'ol' => [],
                    'li' => [],
                    'h1' => [],
                    'h2' => [],
                    'h3' => [],
                    'h4' => [],
                    'h5' => [],
                    'h6' => [],
                    'blockquote' => [],
                    'code' => [],
                    'pre' => []
                ]);
                
            case 'csv':
                // For CSV, ensure no script injection in cells
                $lines = explode("\n", $content);
                $sanitized_lines = [];
                foreach ($lines as $line) {
                    // Remove potential formula injection
                    $line = preg_replace('/^[=+\-@]/', "'", $line);
                    $sanitized_lines[] = sanitize_text_field($line);
                }
                return implode("\n", $sanitized_lines);
                
            default:
                // For other types, basic sanitization
                return sanitize_textarea_field($content);
        }
    }
    
    /**
     * Recursively sanitize JSON data
     * @param mixed $data JSON data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitize_json_recursive($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize_json_recursive'], $data);
        } elseif (is_string($data)) {
            return sanitize_text_field($data);
        }
        return $data;
    }
    
    /**
     * Get PHP memory limit in bytes
     * @return int Memory limit in bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit === '-1') {
            return PHP_INT_MAX; // No limit
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) substr($memory_limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int) $memory_limit;
        }
    }
    
    /**
     * Format bytes into human-readable format
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    
    /**
     * Legacy PDF string decoder for fallback extraction
     * @param string $pdf_string PDF encoded string
     * @return string Decoded string
     */
    private function decode_pdf_string_legacy($pdf_string) {
        // Handle common PDF string encodings
        $decoded = $pdf_string;
        
        // Handle octal escapes
        $decoded = preg_replace_callback('/\\\\(\d{3})/', function($matches) {
            return chr(octdec($matches[1]));
        }, $decoded);
        
        // Handle other common escapes
        $decoded = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], 
                              ["\n", "\r", "\t", "\\", "(", ")"], $decoded);
        
        return $decoded;
    }
    
    /**
     * Legacy validation for PDF content to filter out metadata
     * @param string $text Decoded text to validate
     * @return bool True if valid content text
     */
    private function is_valid_pdf_content_legacy($text) {
        $text = trim($text);
        
        // Must have minimum length
        if (strlen($text) < 3) {
            return false;
        }
        
        // Skip URLs and web content
        if (preg_match('/^https?:\/\/|www\.|\.com|\.org|\.net|wikipedia\.org/i', $text)) {
            return false;
        }
        
        // Skip repeated language codes
        if (preg_match('/^(en-us|en-gb|de-de|fr-fr|es-es|it-it|pt-pt|ru-ru|zh-cn|ja-jp|ko-kr)$/i', $text)) {
            return false;
        }
        
        // Skip technical gibberish patterns
        if (preg_match('/^[A-Z][.][A-Z0-9][.:@$%]+[A-Za-z0-9._@$%]*$/i', $text)) {
            return false;
        }
        
        // Skip common PDF metadata patterns (enhanced for legacy)
        $bad_patterns = [
            '/^Identity/i',                    // IdentityAdobe, Identity
            '/^Adobe/i',                       // Adobe identifiers
            '/^TextMaker/i',                   // TextMaker software
            '/^LayoutLogic/i',                 // LayoutLogic Software
            '/^D:\d{8}/i',                    // Date timestamps
            '/^[+\-]\d{2}\'/i',               // Timezone offsets
            '/^\/[A-Z]/i',                    // PDF object names
            '/^[A-Z]{3,}$/i',                 // All caps strings
            '/^\d+\s+\d+\s+obj$/i',           // PDF objects
            '/^[a-f0-9]{8,}$/i',              // Long hex strings
            '/^[A-Z]{1,3}[_][a-z]+$/i',       // Codes like CX_a
            '/^the[A-Z]/i',                   // Concatenated words starting with "the"
            '/^%[A-Z0-9=.-]+$/i'              // Encoded strings starting with %
        ];
        
        foreach ($bad_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }
        
        // Must contain some alphabetic characters
        if (!preg_match('/[a-zA-Z]/', $text)) {
            return false;
        }
        
        // Skip strings with excessive repetition
        if (preg_match('/^(.{1,3})\1{4,}/', $text)) {
            return false;
        }
        
        // Must be mostly printable (more lenient for international text)
        $printable_ratio = strlen(preg_replace('/[^\x20-\x7E\x80-\xFF]/', '', $text)) / strlen($text);
        if ($printable_ratio < 0.6) {
            return false;
        }
        
        // Skip strings that are mostly punctuation or numbers
        $alpha_ratio = strlen(preg_replace('/[^a-zA-ZăâîșțĂÂÎȘȚ]/', '', $text)) / strlen($text);
        if ($alpha_ratio < 0.2) {
            return false;
        }
        
        return true;
    }
}
