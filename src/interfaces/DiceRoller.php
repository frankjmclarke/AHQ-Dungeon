<?php

namespace App\Interfaces;

/**
 * Interface for dice rolling functionality
 */
interface DiceRoller {
    /**
     * Roll dice based on notation
     * 
     * @param string $notation The dice notation (e.g. "1D6", "2D12")
     * @return array Array containing total and individual rolls
     */
    public function roll(string $notation): array;
} 