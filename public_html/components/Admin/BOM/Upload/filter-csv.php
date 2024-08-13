<?php
function filterCSVArray($csvArray, $packageToExclude)
{
    $csvArray = array_filter($csvArray, function($row) {
        return $row[1] !== "DNP";
    });
    
    $csvArray = array_filter($csvArray, function($row) use ($packageToExclude) {
        return !in_array($row[3], $packageToExclude);
    });

    return $csvArray;
}