<?php
$MsaDB = Atte\DB\MsaDB::getInstance();
$FlowpinDB = Atte\DB\FlowpinDB::getInstance();


$query = "SELECT TOP 150 * FROM [report].[ProductQuantityHistoryView] WHERE EventId < '4773037' ORDER BY EventId DESC";

// $query = "SELECT TOP 150 *
//         FROM [report].[ProductQuantityHistoryView] 
//         WHERE EventTypeValue = 'Modified' 
//         AND FieldOldValue = 'ContractorHasIt' 
//         AND FieldNewValue = 'InOrder'
//         ORDER BY EventId DESC";

$result = $FlowpinDB -> query($query, PDO::FETCH_ASSOC);

$columnNames = array_keys($result[0]);

echo "<table class='table table-bordered table-sm mt-4'>";
echo "<tr>";
foreach($columnNames as $columnName){
    echo "<th>$columnName</th>";
}
echo "</tr>";
foreach($result as $row){
    echo "<tr>";
    foreach($row as $cell){
        echo "<td>$cell</td>";
    }
    echo "</tr>";
}
echo "</table>";
