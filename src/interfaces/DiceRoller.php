<?php
/**
 * Interface for dice rolling functionality
 */
interface DiceRoller {
    /**
     * Roll dice according to the given notation (e.g., "1D6", "2D12")
     * @param string $notation The dice notation to roll
     * @return array Array containing 'total' and 'rolls'
     */
    public function roll($notation);
} 