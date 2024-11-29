<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $headers = getallheaders();
    $connectionString = isset($headers['Connection-String']) ? $headers['Connection-String'] : null;
    $responseType = isset($_GET['responseType']) ? $_GET['responseType'] : null;

    if ($connectionString) {
        $query = file_get_contents('php://input');

        if ($query) {
            $mysqli = new mysqli($connectionString);

            if ($mysqli->connect_error) {
                echo json_encode(['error' => 'Connection failed: ' . $mysqli->connect_error]);
                exit();
            }

            $data = [];
            if ($mysqli->multi_query($query)) {
                do {
                    if ($result = $mysqli->store_result()) {
                        $dataset = [];
                        while ($row = $result->fetch_assoc()) {
                            $dataset[] = $row;
                        }
                        $data[] = $dataset;
                        $result->free();
                    }
                } while ($mysqli->more_results() && $mysqli->next_result());
            } else {
                echo json_encode(['error' => 'Query failed: ' . $mysqli->error]);
                exit();
            }

            switch ($responseType) {
                case 'value:'
                case 'single':
                    echo json_encode($data[0][0][array_keys($data[0][0])[0]]);
                    break;
                case 'pair':
                case 'pairs':
                    $pairs = [];
                    foreach ($data[0] as $row) {
                        $pairs[] = [array_values($row)[0] => array_values($row)[count($row) - 1]];
                    }
                    echo json_encode($pairs);
                    break;
                case 'table':
                    echo json_encode($data[0]);
                    break;
                case 'row':
                case 'first':
                    echo json_encode($data[0][0]);
                    break;
                case 'list':
                case 'array':
                case 'values':
                    $list = [];
                    foreach ($data[0] as $row) {
                        $list[] = array_values($row)[0];
                    }
                    echo json_encode($list);
                    break;
                default:
                    echo json_encode($data);
                    break;
            }

            $mysqli->close();
        } else {
            echo json_encode(['error' => 'No query provided']);
        }
    } else {
        echo json_encode(['error' => 'No connection string provided']);
    }
} else {
    echo '<script type="text/javascript">window.location.href = "https://github.com/zonaro/mysqlapi";</script>';
 
}
 