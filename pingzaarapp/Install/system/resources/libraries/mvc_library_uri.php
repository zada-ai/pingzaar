<?php
/**
 * Name: MVC Framework
 * About: Titan Systems MVC Framework
 * Copyright: 2020, All Rights Reserved.
 * Author: Titan Systems <mail@titansystems.ph>
 */

/**
 * URI Library for MVC Framework
 * 
 * Handles URL segment parsing and manipulation for the MVC framework.
 * Provides methods to extract segments, convert URI to associative arrays,
 * and retrieve URI segments as arrays.
 */
class MVC_Library_URI
{
    /**
     * @var array|null URL segments from the current request
     */
    private array|null $path = null;

    /**
     * Constructor
     * 
     * Initializes the URI library with URL segments from the MVC instance.
     */
    public function __construct()
    {
        $this->path = mvc::instance()->url_segments ?? [];
    }

    /**
     * Get a specific URI segment by index
     * 
     * @param int $index The 1-based index of the segment to retrieve
     * @return string|false The segment value or false if not found
     */
    public function segment(int $index): string|false
    {
        if ($index < 1 || !is_array($this->path)) {
            return false;
        }

        $segmentIndex = $index - 1;
        
        if (!isset($this->path[$segmentIndex])) {
            return false;
        }

        $segment = $this->path[$segmentIndex];
        
        // Parse URL and extract path component
        $parsedUrl = parse_url($segment);
        
        return $parsedUrl['path'] ?? '/';
    }

    /**
     * Convert URI segments to associative array starting from specified index
     * 
     * @param int $index The 1-based starting index for conversion
     * @return array Associative array of key-value pairs from URI segments
     */
    public function uri_to_assoc(int $index): array
    {
        if (!is_array($this->path) || $index < 1) {
            return [];
        }

        $assoc = [];
        $startIndex = $index - 1;
        $pathCount = count($this->path);

        // Process segments in pairs (key-value)
        for ($i = $startIndex; $i < $pathCount; $i += 2) {
            $key = $this->path[$i] ?? null;
            
            if ($key !== null) {
                $value = $this->path[$i + 1] ?? null;
                $assoc[$key] = $value;
            }
        }

        return $assoc;
    }

    /**
     * Get URI segments as array starting from specified index
     * 
     * @param int $index The 0-based starting index (default: 0)
     * @return array|false Array of segments or false if invalid
     */
    public function uri_to_array(int $index = 0): array|false
    {
        if (!is_array($this->path) || $index < 0) {
            return false;
        }

        return array_slice($this->path, $index);
    }

    /**
     * Get all URI segments
     * 
     * @return array All URI segments
     */
    public function getSegments(): array
    {
        return $this->path ?? [];
    }

    /**
     * Get the total number of URI segments
     * 
     * @return int Number of segments
     */
    public function getSegmentCount(): int
    {
        return is_array($this->path) ? count($this->path) : 0;
    }

    /**
     * Check if a segment exists at the specified index
     * 
     * @param int $index The 1-based index to check
     * @return bool True if segment exists, false otherwise
     */
    public function hasSegment(int $index): bool
    {
        if ($index < 1 || !is_array($this->path)) {
            return false;
        }

        return isset($this->path[$index - 1]);
    }
}
