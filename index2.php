<?php
// index2.php

require_once 'tables.php';

/**
 * Sanitize GET parameters.
 */
function get_sanitized_params() {
    $params = array();
    foreach ($_GET as $key => $value) {
        $params[$key] = is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '';
    }
    return $params;
}

/**
 * Validate and sanitize subdirectory.
 */
function validate_subdir($subdir) {
    if (!$subdir) {
        return null;
    }
    $sanitized = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $subdir);
    if (!is_dir($sanitized)) {
        throw new Exception("Invalid subdirectory '{$subdir}'");
    }
    return $sanitized;
}

/**
 * Main entry point.
 */
function main() {
    global $config;
    
    try {
        $params = get_sanitized_params();
        
        // Set verbosity if requested.
        if (isset($params['verbose']) && $params['verbose'] === "1") {
            $config->setVerbose(true);
        }
        
        // Ensure the "tables" parameter is provided.
        if (!isset($params['tables']) || empty(trim($params['tables']))) {
            echo "Usage: index2.php?tables=TableName1,TableName2[,...]&verbose=1&subdir=your_subdir (optional)";
            return;
        }
        
        $subdir = validate_subdir(isset($params['subdir']) ? $params['subdir'] : null);
        
        // Start output buffering.
        ob_start();
        
        // Load tables and named rules.
        list($tables, $named_rules) = load_tables_and_rules($subdir);
        
        // Process the user-specified tables.
        process_user_tables($params['tables'], $tables, $named_rules, $config->isVerbose());
        
        // Output the results.
        $output = ob_get_clean();
        echo $output;
        echo generate_monster_stats_table($output);
        
    } catch (Exception $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo "[Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "]";
    }
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    main();
}
?>
