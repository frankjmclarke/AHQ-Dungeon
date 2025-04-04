<?php

namespace App\Interfaces;

/**
 * Interface for dungeon generation functionality
 */
interface DungeonGenerator {
    /**
     * Run the dungeon generator with the given parameters
     * @param array $params Parameters including tables, verbose, and subdir
     */
    public function run($params);
} 