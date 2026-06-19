<?php
// export.php
require_once 'db.php';

if (isset($_GET['table'])) {
    $table = $_GET['table'];
    $valid_tables = [
        'chat_messages', 'feedback', 'user_queries', 'system_logs', 'error_logs', 'ai_models',
        'web_sessions', 'knowledge_base_documents', 'knowledge_base_categories', 'scraped_content'
    ];
    
    if (in_array($table, $valid_tables)) {
        $result = $conn->query("SELECT * FROM $table");
        $format = $_GET['format'] ?? 'csv';
        
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $table . '_export.json"');
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit();
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $table . '_export.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Get column names
            $fields = $result->fetch_fields();
            $headers = array();
            foreach ($fields as $field) {
                $headers[] = $field->name;
            }
            fputcsv($output, $headers);
            
            // Output rows
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit();
        }
    }
}

$conn->close();
?>