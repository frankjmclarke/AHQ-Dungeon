<?php
require_once __DIR__ . '/../interfaces/NamedBlockManager.php';

/**
 * Implementation of named block management functionality
 */
class NamedBlockManagerImpl implements NamedBlockManager {
    private $parent_dir;
    
    public function __construct() {
        $this->parent_dir = dirname(__DIR__);
    }
    
    public function extractNamedBlocks($subdir = null) {
        $named_blocks = array();
        
        // Load files from parent directory
        $files = glob($this->parent_dir . "/*.txt");
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $blocks = $this->extractBlocks($content);
            $named_blocks = array_merge($named_blocks, $blocks);
        }
        
        // Load files from subdirectory if specified
        if ($subdir !== null) {
            $subdir_path = $this->parent_dir . "/" . $subdir;
            if (is_dir($subdir_path)) {
                $subdir_files = glob($subdir_path . "/*.txt");
                foreach ($subdir_files as $file) {
                    $content = file_get_contents($file);
                    $blocks = $this->extractBlocks($content);
                    $named_blocks = array_merge($named_blocks, $blocks);
                }
            }
        }
        
        return $named_blocks;
    }
    
    private function extractBlocks($content) {
        $blocks = array();
        $lines = explode("\n", $content);
        $current_block = null;
        $current_lines = array();
        
        foreach ($lines as $line) {
            if (preg_match('/^@(\w+)/', $line, $matches)) {
                if ($current_block !== null) {
                    $blocks[$current_block] = $current_lines;
                    $current_lines = array();
                }
                $current_block = $matches[1];
            } elseif ($current_block !== null) {
                $current_lines[] = $line;
            }
        }
        
        if ($current_block !== null) {
            $blocks[$current_block] = $current_lines;
        }
        
        return $blocks;
    }
} 