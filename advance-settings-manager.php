<?php
/**
 * Plugin Name: Advanced Settings Manager
 * Description: A comprehensive WordPress plugin with a tabbed settings interface supporting multiple field types
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: advanced-settings-manager
 */

if (!defined('ABSPATH')) exit;

// Global helper function
if (!function_exists('asm_get_setting')) {
    function asm_get_setting($key = null, $default = null) {
        static $settings = null;
        
        if ($settings === null) {
            $settings = get_option('advanced_settings_manager_options', array());
        }
        
        if ($key === null) {
            return $settings;
        }
        
        $keys = explode('.', $key);
        $value = $settings;
        
        foreach ($keys as $key_part) {
            if (!isset($value[$key_part])) {
                return $default;
            }
            $value = $value[$key_part];
        }
        
        return $value;
    }
}

class AdvancedSettingsManager {
    private static $instance = null;
    private $settings_config;
    private $option_name = 'advanced_settings_manager_options';

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->initializeSettingsConfig();
        add_action('admin_menu', array($this, 'addMenuPage'));
        add_action('admin_init', array($this, 'registerSettings'));
        add_filter('plugin_action_links', array($this, 'addSettingsLink'), 10, 2);

        // Add this line to handle export early
        add_action('admin_init', array($this, 'handleExport'));
    }

    public function handleExport() {
        if (!isset($_POST['asm_export_settings']) || 
            !isset($_POST['asm_export_nonce']) || 
            !wp_verify_nonce($_POST['asm_export_nonce'], 'asm_export_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to export settings.'));
        }

        $settings = get_option($this->option_name, array());
        $filename = 'settings-export-' . date('Y-m-d') . '.json';
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function initializeSettingsConfig() {
        $this->settings_config = array(
            array(
                'section_title' => 'General Settings',
                'section_key' => 'general',
                'fields' => array(
                    array(
                        'label' => 'API Key',
                        'key' => 'api_key',
                        'type' => 'text',
                        'default' => '',
                        'description' => 'Enter your API key here'
                    ),
                    array(
                        'label' => 'Description',
                        'key' => 'description',
                        'type' => 'textarea',
                        'default' => '',
                        'description' => 'Enter a description for your service'
                    ),
                    array(
                        'label' => 'Service Type',
                        'key' => 'service_type',
                        'type' => 'select',
                        'default' => 'basic',
                        'options' => array(
                            'basic' => 'Basic Service',
                            'premium' => 'Premium Service',
                            'enterprise' => 'Enterprise Service'
                        ),
                    ),
                ),
            ),
            array(
                'section_title' => 'Email Settings',
                'section_key' => 'email',
                'fields' => array(
                    array(
                        'label' => 'Enable Email Notifications',
                        'key' => 'enabled',
                        'type' => 'checkbox',
                        'default' => false,
                    ),
                    array(
                        'label' => 'Notification Type',
                        'key' => 'notification_type',
                        'type' => 'radio',
                        'default' => 'instant',
                        'options' => array(
                            'instant' => 'Instant',
                            'daily' => 'Daily Digest',
                            'weekly' => 'Weekly Digest'
                        ),
                    ),
                    array(
                        'label' => 'Email Template',
                        'key' => 'template',
                        'type' => 'editor',
                        'default' => 'Hello {user},

Thank you for using our service.

Best regards,
{site_name}',
                    ),
                ),
            ),
            array(
                'section_title' => 'Import/Export',
                'section_key' => 'import_export',
                'fields' => array()
            ),
        );
    }

    public function addMenuPage() {
        add_menu_page(
            'Advanced Settings Manager',
            'Advanced Settings',
            'manage_options',
            'advanced-settings-manager',
            array($this, 'renderSettingsPage'),
            'dashicons-admin-generic',
            100
        );
    }

    public function registerSettings() {
        register_setting(
            'advanced_settings_manager',
            $this->option_name,
            array($this, 'validateSettings')
        );

        foreach ($this->settings_config as $section) {
            if ($section['section_key'] !== 'import_export') {
                add_settings_section(
                    $section['section_key'] . '_section',
                    $section['section_title'],
                    null,
                    'advanced_settings_manager_' . $section['section_key']
                );

                foreach ($section['fields'] as $field) {
                    add_settings_field(
                        $field['key'],
                        $field['label'],
                        array($this, 'renderField'),
                        'advanced_settings_manager_' . $section['section_key'],
                        $section['section_key'] . '_section',
                        array(
                            'field' => $field,
                            'section_key' => $section['section_key']
                        )
                    );
                }
            }
        }
    }


    public function validateSettings($input) {
        if (!is_array($input)) {
            return array();
        }
    
        $current_settings = get_option($this->option_name, array());
        
        // Handle settings import
        if (isset($_FILES['settings_import']) && $_FILES['settings_import']['size'] > 0) {
            if (!current_user_can('manage_options')) {
                add_settings_error(
                    'advanced_settings_manager',
                    'insufficient_permissions',
                    'You do not have permission to import settings'
                );
                return $current_settings;
            }
    
            $import_data = file_get_contents($_FILES['settings_import']['tmp_name']);
            $imported_settings = json_decode($import_data, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($imported_settings)) {
                return $imported_settings;
            }
            
            add_settings_error(
                'advanced_settings_manager',
                'invalid_import',
                'Invalid import file format'
            );
            return $current_settings;
        }
    
        $sanitized_input = $current_settings; // Start with existing settings
    
        // Only update the sections that were actually submitted
        foreach ($this->settings_config as $section) {
            $section_key = $section['section_key'];
            
            // Skip if this section wasn't in the submitted data
            if (!isset($input[$section_key])) {
                continue;
            }
    
            if (!isset($sanitized_input[$section_key])) {
                $sanitized_input[$section_key] = array();
            }
    
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                
                if (isset($input[$section_key][$key])) {
                    $value = $input[$section_key][$key];
                    
                    switch ($field['type']) {
                        case 'text':
                            $sanitized_input[$section_key][$key] = sanitize_text_field($value);
                            break;
                            
                        case 'textarea':
                            $sanitized_input[$section_key][$key] = sanitize_textarea_field($value);
                            break;
                            
                        case 'editor':
                            $sanitized_input[$section_key][$key] = wp_kses_post($value);
                            break;
                            
                        case 'checkbox':
                            $sanitized_input[$section_key][$key] = (bool)$value;
                            break;
                            
                        case 'select':
                        case 'radio':
                            if (array_key_exists($value, $field['options'])) {
                                $sanitized_input[$section_key][$key] = $value;
                            } else {
                                $sanitized_input[$section_key][$key] = $field['default'];
                            }
                            break;
                            
                        default:
                            $sanitized_input[$section_key][$key] = $value;
                    }
                } else if ($field['type'] === 'checkbox') {
                    $sanitized_input[$section_key][$key] = false;
                }
            }
        }
    
        return $sanitized_input;
    }

    public function renderField($args) {
        $field = $args['field'];
        $section_key = $args['section_key'];
        $current_value = asm_get_setting("$section_key.{$field['key']}", $field['default']);
        $name = "{$this->option_name}[$section_key][{$field['key']}]";
        $id = "asm_{$section_key}_{$field['key']}";

        switch ($field['type']) {
            case 'text':
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr($current_value)
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="5" class="large-text">%s</textarea>',
                    esc_attr($id),
                    esc_attr($name),
                    esc_textarea($current_value)
                );
                break;

            case 'editor':
                wp_editor(
                    $current_value,
                    $id,
                    array(
                        'textarea_name' => $name,
                        'media_buttons' => true,
                        'tinymce' => true,
                        'textarea_rows' => 10
                    )
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($id),
                    esc_attr($name),
                    checked($current_value, true, false)
                );
                break;

            case 'radio':
                foreach ($field['options'] as $value => $label) {
                    printf(
                        '<label><input type="radio" name="%s" value="%s" %s> %s</label><br>',
                        esc_attr($name),
                        esc_attr($value),
                        checked($current_value, $value, false),
                        esc_html($label)
                    );
                }
                break;

            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));
                foreach ($field['options'] as $value => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($value),
                        selected($current_value, $value, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
                break;
        }

        if (!empty($field['description'])) {
            printf('<p class="description">%s</p>', esc_html($field['description']));
        }
    }

    public function renderSettingsPage() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : $this->settings_config[0]['section_key'];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <?php foreach ($this->settings_config as $section): ?>
                    <a href="?page=advanced-settings-manager&tab=<?php echo esc_attr($section['section_key']); ?>" 
                       class="nav-tab <?php echo $active_tab == $section['section_key'] ? 'nav-tab-active' : ''; ?>">
                       <?php echo esc_html($section['section_title']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php if ($active_tab === 'import_export'): ?>
                <div class="card">
                    <h3>Export Settings</h3>
                    <form method="post">
                        <?php wp_nonce_field('asm_export_nonce', 'asm_export_nonce'); ?>
                        <p>
                            <button type="submit" name="asm_export_settings" class="button button-primary">
                                Export Settings
                            </button>
                        </p>
                    </form>
                </div>

                <div class="card">
                    <h3>Import Settings</h3>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('asm_import_nonce', 'asm_import_nonce'); ?>
                        <p>
                            <input type="file" name="settings_import" accept=".json">
                        </p>
                        <p>
                            <button type="submit" class="button button-primary">
                                Import Settings
                            </button>
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('advanced_settings_manager');
                    do_settings_sections('advanced_settings_manager_' . $active_tab);
                    submit_button();
                    ?>
                </form>
            <?php endif; ?>

            <?php
            // Handle export
            if (isset($_POST['asm_export_settings']) && 
            isset($_POST['asm_export_nonce']) && 
            wp_verify_nonce($_POST['asm_export_nonce'], 'asm_export_nonce')) {

            $settings = get_option($this->option_name, array());
            $filename = 'settings-export-' . date('Y-m-d') . '.json';

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            echo wp_json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
            }

            // Handle import
            if (isset($_FILES['settings_import'])) {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to import settings.'));
            }

            $file = $_FILES['settings_import'];

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                add_settings_error(
                    'advanced_settings_manager',
                    'upload_error',
                    'Failed to upload file: ' . $file['error']
                );
                return;
            }

            // Verify file type
            $file_info = pathinfo($file['name']);
            if ($file_info['extension'] !== 'json') {
                add_settings_error(
                    'advanced_settings_manager',
                    'invalid_file_type',
                    'The uploaded file must be a JSON file.'
                );
                return;
            }

            // Read and parse JSON
            $import_data = file_get_contents($file['tmp_name']);
            $settings = json_decode($import_data, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                add_settings_error(
                    'advanced_settings_manager',
                    'invalid_json',
                    'The uploaded file contains invalid JSON.'
                );
                return;
            }

            // Update settings
            update_option($this->option_name, $settings);
            add_settings_error(
                'advanced_settings_manager',
                'import_success',
                'Settings imported successfully.',
                'updated'
            );
            }
            ?>
        </div>
        <?php
    }

    public function addSettingsLink($links, $file) {
        static $this_plugin;
        
        if (!$this_plugin) {
            $this_plugin = plugin_basename(__FILE__);
        }

        if ($file == $this_plugin) {
            $settings_link = '<a href="' . admin_url('admin.php?page=advanced-settings-manager') . '">' . __('Settings') . '</a>';
            array_unshift($links, $settings_link);
        }

        return $links;
    }
}

// Initialize the plugin
$advancedSettingsManager = AdvancedSettingsManager::getInstance();
