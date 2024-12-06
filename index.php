<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, DB-Hostname, DB-Username, DB-Password, DB-Database, DB-Port, DB-Socket, Response-Type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        header('Content-Type: application/json');
        $headers = getallheaders();
        $hostname = isset($headers['DB-Hostname']) ? trim($headers['DB-Hostname']) : null;
        $username = isset($headers['DB-Username']) ? trim($headers['DB-Username']) : null;
        $password = isset($headers['DB-Password']) ? trim($headers['DB-Password']) : null;
        $database = isset($headers['DB-Database']) ? trim($headers['DB-Database']) : null;
        $port = isset($headers['DB-Port']) ? trim($headers['DB-Port']) : null;
        $socket = isset($headers['DB-Socket']) ? trim($headers['DB-Socket']) : null;
        $responseType = isset($headers['Response-Type']) ? $headers['Response-Type'] : null;

        if ($hostname !== '' && $username !== '' && $database !== '') {
            $query = file_get_contents('php://input');
            if ($query === false) {
                throw new Exception('No query provided');
            }

            $query = trim($query);

            if ($query === '') {
                throw new Exception('No query provided');
            }

            $mysqli = new mysqli($hostname, $username, $password, $database, $port, $socket);

            if ($mysqli->connect_error) {
                throw new Exception('Connection failed: ' . $mysqli->connect_error);
            }

            // Prepare the statement
            $stmt = $mysqli->prepare($query);
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . $mysqli->error);
            }

            // Execute the statement
            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }

            // Fetch results
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }

            //lowercase and trim responseType
            $responseType = strtolower(trim($responseType));

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
                case 'view':
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
            throw new Exception('Required connection parameters missing');
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
} else {
    header('Location: https://github.com/zonaro/mysqlapi');
    exit();
}
