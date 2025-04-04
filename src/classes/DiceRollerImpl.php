<?php

namespace App\Classes;

require_once __DIR__ . '/../interfaces/DiceRoller.php';

use App\Interfaces\DiceRoller;

/**
 * Implementation of dice rolling functionality
 */
class DiceRollerImpl implements DiceRoller {
    public function roll(string $notation): array {
        if (preg_match('/^(\d+)D(\d+)$/', $notation, $matches)) {
            $num_dice = intval($matches[1]);
            $num_sides = intval($matches[2]);
            $rolls = array();
            $total = 0;
            
            for ($i = 0; $i < $num_dice; $i++) {
                $roll = rand(1, $num_sides);
                $rolls[] = $roll;
                $total += $roll;
            }
            
            return array(
                'total' => $total,
                'rolls' => $rolls
            );
        }
        
        return array('total' => 0, 'rolls' => array());
    }
} 