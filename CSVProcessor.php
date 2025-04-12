<?php
/**
 * CSVProcessor - Handles processing of output text and CSV data matching
 * 
 * This class has two main responsibilities:
 * 1. Extract and output any text enclosed in double quotes from the input
 * 2. Search for monster names in the bestiary CSV file and output their stats
 */
class CSVProcessor {
    private static $cacheFile = 'csv_cache.dat';
    private static $notFoundCacheFile = 'not_found_cache.dat';
    private static $cacheDuration = 3600; // 1 hour in seconds
    private static $csvFile = 'skaven_bestiary.csv';
/*
Cache Check: Before reading the CSV file, the code checks if the cache file exists and if it's less than 30 minutes old.
Cache Hit: If the cache is valid, it returns the cached data directly from the file.
Cache Miss: If the cache is expired or doesn't exist, it reads the CSV file and creates a new cache.
Shared Cache: The cache file is shared among all users, reducing server load. 
notFoundCache: This cache is used to store names that were not found in the CSV file. This prevents duplicate searches for the same name.
*/

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
        $csvResults = self::matchAndSearchNames($output, $csvData);       
        return self::generateHTMLTable($csvResults);
    }

    /**
     * Load CSV data from cache if valid, otherwise read from CSV file
     */
    private static function loadCSVData() {
        // Check if cache exists and is still valid
        if (file_exists(self::$cacheFile) && (time() - filemtime(self::$cacheFile) < self::$cacheDuration)) {
            return unserialize(file_get_contents(self::$cacheFile));
        }

        // Cache doesn't exist or is expired, load from CSV
        $csvData = [];
        if (($handle = fopen(self::$csvFile, "r")) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $csvData[] = $data;
            }
            fclose($handle);
            
            // Write to cache file
            file_put_contents(self::$cacheFile, serialize($csvData));
        }
        return $csvData;
    }

    /**
     * Load the not-found cache from file if valid, otherwise create new cache
     */
    private static function loadNotFoundCache() {
        if (file_exists(self::$notFoundCacheFile) && 
            (time() - filemtime(self::$notFoundCacheFile) < self::$cacheDuration)) {
            $cache = unserialize(file_get_contents(self::$notFoundCacheFile));
            return is_array($cache) ? $cache : [];
        }
        return [];
    }

    /**
     * Save the not-found cache to file
     * Ensures the cache is sorted before saving
     */
    private static function saveNotFoundCache($notFoundCache) {
        // Remove duplicates and sort
        $notFoundCache = array_unique($notFoundCache, SORT_STRING);
        sort($notFoundCache, SORT_STRING);
        file_put_contents(self::$notFoundCacheFile, serialize($notFoundCache));
    }

    /**
     * Generic binary search method that can handle both CSV data and notFoundCache
     * 
     * @param array $array The array to search in
     * @param string $target The target string to find
     * @param bool $isCSVMode If true, searches CSV data array (first column), if false searches simple string array
     * @return int|bool Returns int index if $isCSVMode is true, boolean if $isCSVMode is false
     */
    private static function binarySearch($array, $target, $isCSVMode = true) {
        if (empty($array) || $target === null) {
            return $isCSVMode ? -1 : false;
        }

        $low = 0;
        $high = count($array) - 1;

        while ($low <= $high) {
            $mid = floor(($low + $high) / 2);
            $compareValue = $isCSVMode ? ($array[$mid][0] ?? '') : $array[$mid];
            $comparison = strcasecmp($compareValue, $target);
            
            if ($comparison < 0) {
                $low = $mid + 1;
            } elseif ($comparison > 0) {
                $high = $mid - 1;
            } else {
                return $isCSVMode ? $mid : true;
            }
        }
        return $isCSVMode ? -1 : false;
    }

    private static function isInNotFoundCache($name, $notFoundCache) {
        return self::binarySearch($notFoundCache, $name, false);
    }

    private static function matchAndSearchNames($output, $csvData) {
        $notFoundCache = self::loadNotFoundCache();
        $csvResults = [];
        $newNotFoundItems = [];

        if (preg_match_all('/([A-Za-z ]+?)(?=[^A-Za-z ]|$)/', $output, $matches)) {
            $names = array_map('trim', $matches[1]);
            $names = array_filter($names, function($n) { return $n !== ""; });
            foreach ($names as $name) {
                if (self::isInNotFoundCache($name, $notFoundCache)) {
                    continue;
                }
                $index = self::binarySearch($csvData, $name, true);
                if ($index !== -1) {
                    $csvResults[$name][] = $csvData[$index];
                } else {
                    // Try variations of the name
                    $found = false;
                    if (substr($name, -1) === "s") {
                        $singular = substr($name, 0, -1);
                        $index = self::binarySearch($csvData, $singular, true);
                        if ($index !== -1) {
                            $csvResults[$name][] = $csvData[$index];
                            $found = true;
                        }
                    } else if (substr($name, -3) === "men") {
                        $singular = substr($name, 0, -3) . "man";
                        $index = self::binarySearch($csvData, $singular, true);
                        if ($index !== -1) {
                            $csvResults[$name][] = $csvData[$index];
                            $found = true;
                        }
                    } else if (substr($name, -3) === "ies") {
                        $singular = substr($name, 0, -3) . "y";
                        $index = self::binarySearch($csvData, $singular, true);
                        if ($index !== -1) {
                            $csvResults[$name][] = $csvData[$index];
                            $found = true;
                        }
                    }
                    if (!$found && !in_array($name, $newNotFoundItems)) {
                        $newNotFoundItems[] = $name;
                    }
                }
            }
        }

        // Only save the cache if new items were added
        if (!empty($newNotFoundItems)) {
            // Merge new items, remove duplicates, and maintain sorting
            $notFoundCache = array_unique(array_merge($notFoundCache, $newNotFoundItems), SORT_STRING);
            self::saveNotFoundCache($notFoundCache);
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
                        $csvOutput .= "<td>" . htmlspecialchars($field ?? '') . "</td>";
                    }
                    $csvOutput .= "</tr>";
                }
            }
            $csvOutput .= "</table>";
        }
        return $csvOutput;
    }
}