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
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>API Documentation</title>
        <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="container">
        <h1 class="mt-5">API Documentation</h1>
        <p>This API accepts POST requests with a plaintext payload containing a SQL query. The request must include a header with the connection string to the database.</p>
        <h2>Request</h2>
        <p><strong>Method:</strong> POST</p>
        <p><strong>Headers:</strong></p>
        <ul>
            <li><strong>Connection-String:</strong> The connection string to the database.</li>
        </ul>
        <p>URL: <?php echo 'http://' . $_SERVER['HTTP_HOST'];?></p>
        <p><strong>Body:</strong></p>
        <pre>
            SELECT `column1`, `column2`, ... FROM `table`;
        </pre>
        <h2>Response</h2>
        <p>The response will be in JSON format.</p>
        <p><strong>Success:</strong></p>
        <pre>
    [
        [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ],
        [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ],
        ...
    ]
        </pre>
        <p><strong>Error:</strong></p>
        <pre>
    {
        "error": "Error message here"
    }
        </pre>
        <h2>Query Parameters</h2>
        <p>You can use the following query parameters to alter the response format:</p>
        <ul>
            <li><strong>responseType=single:</strong> Returns only the first item of the first column of the first dataset.</li>
            <pre>
    "value1"
            </pre>
            <li><strong>responseType=pairs:</strong> Returns a JSON array of objects with the first column as keys and the last column as values.</li>
            <pre>
    [
        {"value1": "value2"},
        {"value3": "value4"},
        ...
    ]
            </pre>
            <li><strong>responseType=table:</strong> Returns only the first dataset.</li>
            <pre>
    [
        {
            "column1": "value1",
            "column2": "value2",
            ...
        },
        ...
    ]
            </pre>
            <li><strong>responseType=row:</strong> Returns only the first row of the first dataset.</li>
            <pre>
    {
        "column1": "value1",
        "column2": "value2",
        ...
    }
            </pre>
            <li><strong>responseType=list:</strong> Returns an array with all values of the first column.</li>
            <pre>
    [
        "value1",
        "value3",
        ...
    ]
            </pre>
            <li><strong>responseType=default:</strong> Returns all datasets as a JSON array (default behavior).</li>
            <pre>
    [
        [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ],
        [
            {
                "column1": "value1",
                "column2": "value2",
                ...
            },
            ...
        ],
        ...
    ]
            </pre>
        </ul>
    </body>
    </html>
    <?php
}
?>