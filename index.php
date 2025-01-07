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
        if (isset($headers['Data-Names'])) {
            $setNames =  trim($headers['Data-Names']);
            $setNames = preg_split('/[;,]/', $setNames);
            $setNames = array_map('trim', $setNames);
        } else {
            $setNames = [];
        }

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

            // Execute the multi query
            if (!$mysqli->multi_query($query)) {
                throw new Exception('Query failed: ' . $mysqli->error);
            }

            // Fetch results
            $data = [];
            do {
                $data2 = [];
                if ($result = $mysqli->store_result()) {
                    while ($row = $result->fetch_assoc()) {
                        $data2[] = $row;
                    }
                    $data[] = $data2;
                    $result->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());

            //lowercase and trim responseType
            $responseType = strtolower(trim($responseType));

            // Process response type
            switch ($responseType) {
                case 'value':
                case 'single':
                    $firstRow = $data[0][0];
                    echo json_encode($firstRow[0][array_keys($firstRow[0])[0]]);
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
                case 'view':
                case 'set':
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
                    // put in the list the first value of each row
                    foreach ($data[0] as $row) {
                        $list[] = array_values($row)[0];
                    }
                    echo json_encode($list);
                    break;
                case 'namedsets':
                case 'namedset':
                    $namedSets = [];
                    foreach ($data as $index => $set) {
                        $setName = isset($setNames[$index]) ? $setNames[$index] : 'set' . $index;
                        $namedSets[$setName] = $set;
                    }
                    echo json_encode($namedSets);
                    break;
                case 'namedrows':
                case 'namedrow':
                    $namedSets = [];
                    foreach ($data as $index => $set) {
                        $setName = isset($setNames[$index]) ? $setNames[$index] : 'row' . $index;
                        $namedSets[$setName] = $set[0];
                    }
                    break;
                default:
                    echo json_encode($data);
                    break;
            }

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
