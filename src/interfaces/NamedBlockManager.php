<?php
/**
 * Interface for named block management functionality
 */
interface NamedBlockManager {
    /**
     * Extract named blocks from files in the parent directory and optionally a subdirectory
     * @param string|null $subdir Optional subdirectory to load blocks from
     * @return array Array of block names to their contents
     */
    public function extractNamedBlocks($subdir = null);
} 