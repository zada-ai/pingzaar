<?php

use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;

class MVC_Library_Sheets 
{
    /**
     * Reads an Excel file and returns the Reader object for further processing.
     * 
     * @param string $path Path to the Excel file.
     * @return Reader The Reader object.
     * @throws Exception If the file cannot be opened.
     */
    public function read($path)
    {
        $reader = new Reader(); // Instantiate the reader
        $reader->open($path);

        return $reader; // Return the Reader object directly
    }

    /**
     * Creates an Excel file with the given rows of data.
     * 
     * @param string $file Path to the output Excel file.
     * @param array $rows Array of rows to write into the file.
     * @return bool True on success, false on failure.
     */
    public function create($file, $rows)
    {
        try {
            $writer = new Writer(); // Instantiate the writer
            $writer->openToFile($file);

            foreach ($rows as $rowData) {
                // Create a row from an array of values
                $row = Row::fromValues($rowData);
                $writer->addRow($row);
            }

            $writer->close(); // Close the writer
        } catch (Exception $e) {
            return false;
        }

        return true;
    }
}