<?php
class CSVProcessor {
    // Processes the given output to extract names and match them against a CSV file
    // Reads from 'skaven_bestiary.csv' and attempts to find matches for each name
    // Handles pluralization by checking singular forms of names ending in 's' or 'men'
    // Constructs an HTML table with the matched CSV data and returns it as a string
    // Returns an empty string if no matches are found
    public static function processCSVOutput($output) {
        $csvOutput = "";
        $cache = []; // Cache to store results of previous searches

        // Load CSV data into an array
        $csvData = [];
        if (($handle = fopen("skaven_bestiary.csv", "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $csvData[] = $data;
            }
            fclose($handle);
        }

        // Match sequences of words, ignoring numbers
        if (preg_match_all('/([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
            $names = array_map('trim', $matches[1]);
            $names = array_filter($names, function($n) { return $n !== ""; });
            $csvResults = [];
            
            foreach ($names as $name) {
                // Check cache first
                if (isset($cache[$name])) {
                    $csvResults[$name] = $cache[$name];
                    continue;
                }
                // Perform binary search
                $index = self::binarySearch($csvData, $name);
                if ($index !== -1) {
                    $csvResults[$name][] = $csvData[$index];
                    $cache[$name] = $csvResults[$name]; // Cache the result
                }
            }

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
        }
        return $csvOutput;
    }

    // Binary search function as a static method
    public static function binarySearch($array, $target) {
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