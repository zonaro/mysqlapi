<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Connection-String, Response-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        header('Content-Type: application/json');
        $headers = getallheaders();
        $connectionString = isset($headers['Connection-String']) ? $headers['Connection-String'] : null;
        $responseType = isset($headers['Response-Type']) ? $headers['Response-Type'] : null;

        if ($connectionString) {
            $query = file_get_contents('php://input');

            if ($query) {
                $mysqli = new mysqli($connectionString);

                if ($mysqli->connect_error) {
                    echo json_encode(['error' => 'Connection failed: ' . $mysqli->connect_error]);
                    exit();
                }

                // Prepare the statement
                $stmt = $mysqli->prepare($query);
                if ($stmt === false) {
                    echo json_encode(['error' => 'Prepare failed: ' . $mysqli->error]);
                    exit();
                }

                // Execute the statement
                if (!$stmt->execute()) {
                    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
                    exit();
                }

                // Fetch results
                $result = $stmt->get_result();
                $data = [];
                while ($row = $result->fetch_assoc()) {
                    $data[] = $row;
                }

                // Process response type
                switch ($responseType) {
                    case 'value':
                    case 'single':
                        echo json_encode($data[0][array_keys($data[0])[0]]);
                        break;
                    case 'pair':
                    case 'pairs':
                        $pairs = [];
                        foreach ($data as $row) {
                            $pairs[] = [array_values($row)[0] => array_values($row)[count($row) - 1]];
                        }
                        echo json_encode($pairs);
                        break;
                    case 'table':
                        echo json_encode($data);
                        break;
                    case 'row':
                    case 'first':
                        echo json_encode($data[0]);
                        break;
                    case 'list':
                    case 'array':
                    case 'values':
                        $list = [];
                        foreach ($data as $row) {
                            $list[] = array_values($row)[0];
                        }
                        echo json_encode($list);
                        break;
                    case 'none':
                        echo json_encode(['affected_rows' => $stmt->affected_rows]);
                        break;
                    default:
                        echo json_encode($data);
                        break;
                }

                $stmt->close();
                $mysqli->close();
            } else {
                echo json_encode(['error' => 'No query provided']);
            }
        } else {
            echo json_encode(['error' => 'No connection string provided']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    header('Location: https://github.com/zonaro/mysqlapi');
    exit();
}
