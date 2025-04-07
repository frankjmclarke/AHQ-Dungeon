<?php
/**
 * CSVProcessor - Handles processing of output text and CSV data matching
 * 
 * This class has two main responsibilities:
 * 1. Extract and output any text enclosed in double quotes from the input
 * 2. Search for monster names in the bestiary CSV file and output their stats
 */
class CSVProcessor {
    /**
     * Processes output text to:
     * 1. Output any text in double quotes (e.g. "Shrine Altar", "Nothing. GM 1 Dungeon Counter")
     * 2. Match monster names against the bestiary CSV and output their stats in a table
     * 
     * @param string $output The text to process
     * @return string HTML formatted output containing quoted text and any matched monster stats
     */
    public static function processCSVOutput($output) {
        $csvData = self::loadCSVData();
        $output = self::cleanOutput($output);
        $csvResults = self::matchAndSearchNames($output, $csvData);
        return self::generateHTMLTable($csvResults);
    }

    private static function loadCSVData() {
        $csvData = [];
        if (($handle = fopen("skaven_bestiary.csv", "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $csvData[] = $data;
            }
            fclose($handle);
        }
        return $csvData;
    }

    private static function cleanOutput($output) {
        $unwantedStrings = [
            'Loaded table', 'from subdirectory', 'tab', 'br', 'room', 'from top',
            'level', 'furnish', 'hazard', 'passage', 'end', 'feature', 'length',
            'doors', 'SIZE', 'Good entry for ROLL', 'Rolled', 'on room', 'QUEST ROOM',
            'Stairs Down', 'Quest', 'Matrix', 'Treasure', 'Using dice notation',
            'for block', 'quest', 'rooms', 'matrix', 'rolls',
            'resulting in', "NORMAL ROOM", "HAZARD ROOM", "LAIR ROOM",
            'Gold Crowns', 'Output', 'treasure', 'chest', 
            'Treasure Chest', 'hidden',  'Hidden Treasure',
            'Resolving named block', 'Hidden'
        ];
        foreach ($unwantedStrings as $unwanted) {
            $output = str_ireplace($unwanted, '', $output);
        }
        return $output;
    }

    private static function matchAndSearchNames($output, $csvData) {
        $cache = [];
        $csvResults = [];
        if (preg_match_all('/([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
            $names = array_map('trim', $matches[1]);
            $names = array_filter($names, function($n) { return $n !== ""; });
            foreach ($names as $name) {
                if (isset($cache[$name])) {
                    $csvResults[$name] = $cache[$name];
                    continue;
                }
                $index = self::binarySearch($csvData, $name);
                if ($index !== -1) {
                    $csvResults[$name][] = $csvData[$index];
                    $cache[$name] = $csvResults[$name];
                } else if (substr($name, -1) === "s") {
                    $singular = substr($name, 0, -1);
                    $index = self::binarySearch($csvData, $singular);
                    if ($index !== -1) {
                        $csvResults[$name][] = $csvData[$index];
                        $cache[$name] = $csvResults[$name];
                    }
                } else if (substr($name, -3) === "men") {
                    $singular = substr($name, 0, -3) . "man";
                    $index = self::binarySearch($csvData, $singular);
                    if ($index !== -1) {
                        $csvResults[$name][] = $csvData[$index];
                        $cache[$name] = $csvResults[$name];
                    }
                } else if (substr($name, -3) === "ies") {
                    $singular = substr($name, 0, -3) . "y";
                    $index = self::binarySearch($csvData, $singular);
                    if ($index !== -1) {
                        $csvResults[$name][] = $csvData[$index];
                        $cache[$name] = $csvResults[$name];
                    }
                }
            }
        }
        return $csvResults;
    }

    private static function generateHTMLTable($csvResults) {
        $csvOutput = "";
        if (!empty($csvResults)) {
            $csvOutput .= "##CSV_MARKER##";
            $csvOutput .= "<table border='1' cellspacing='0' cellpadding='4' style='max-width:500px; margin:0 auto;'>";
            $csvOutput .= "<tr>";
            $csvOutput .= "<th>Monster</th>";
            $csvOutput .= "<th>WS</th>";
            $csvOutput .= "<th>BS</th>";
            $csvOutput .= "<th>S</th>";
            $csvOutput .= "<th>T</th>";
            $csvOutput .= "<th>Sp</th>";
            $csvOutput .= "<th>Br</th>";
            $csvOutput .= "<th>Int</th>";
            $csvOutput .= "<th>W</th>";
            $csvOutput .= "<th>DD</th>";
            $csvOutput .= "<th>PV</th>";
            $csvOutput .= "<th>Equipment</th>";
            $csvOutput .= "</tr>";
            foreach ($csvResults as $name => $rows) {
                foreach ($rows as $row) {
                    $csvOutput .= "<tr>";
                    foreach ($row as $field) {
                        $csvOutput .= "<td>" . htmlspecialchars($field) . "</td>";
                    }
                    $csvOutput .= "</tr>";
                }
            }
            $csvOutput .= "</table>";
        }
        return $csvOutput;
    }

    /**
     * Performs a binary search on the CSV data array to find a monster name
     * The CSV data must be sorted alphabetically by monster name (first column)
     * 
     * @param array $array The CSV data array to search
     * @param string $target The monster name to find
     * @return int The index of the found monster or -1 if not found
     */
    public static function binarySearch($array, $target) {
        if (empty($array)) {
            return -1; // Return -1 if the array is empty
        }
        $low = 0;
        $high = count($array) - 1;
        while ($low <= $high) {
            $mid = floor(($low + $high) / 2);
            $comparison = strcasecmp($array[$mid][0], $target);
            if ($comparison < 0) {
                $low = $mid + 1;
            } elseif ($comparison > 0) {
                $high = $mid - 1;
            } else {
                return $mid;
            }
        }
        return -1; // Not found
    }
} 