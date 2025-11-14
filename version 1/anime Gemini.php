<?php
// ====================================================================
// NEW FEATURES: Link 1 (Lnk1) and Link 2 (Lnk2) fields added to database logic
// ====================================================================

// Unified Database Configuration
$db_config = [
    'anime' => [
        'servername' => "localhost",
        'username' => "root",
        'password' => "",
        'port' => "3306",
        'database' => "anime"
    ]
];

// Determine which app to show
$app = isset($_GET['app']) ? $_GET['app'] : 'anime';

// Utility function for database connection
function get_db_connection($target_app, $db_config) {
    if (!isset($db_config[$target_app])) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid application target'
        ]));
    }
    
    $config = $db_config[$target_app];
    $conn = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($conn->connect_error) {
        die(json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]));
    }
    return $conn;
}

// Handle Image Uploads
if (isset($_GET['action']) && $_GET['action'] === 'image_upload') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $uploadDir = "uploads/$target_app/";
    
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    try {
        // Check for regular file upload
        if (isset($_FILES['image'])) {
            $file = $_FILES['image'];
            
            // Validate file
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            
            // SECURITY IMPROVEMENT: Check for file size limit (e.g., 5MB)
            if ($file['size'] > 5 * 1024 * 1024) {
                 throw new Exception('File size exceeds 5MB limit');
            }
            
            // Use fileinfo for better MIME type validation
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            
            if (!array_key_exists($mimeType, $allowedTypes)) {
                throw new Exception('Invalid file type: ' . $mimeType);
            }
            
            $extension = $allowedTypes[$mimeType];
            $filename = uniqid($target_app . '_', true) . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Failed to save file');
            }
            
            echo json_encode([
                'success' => true,
                'filename' => $destination
            ]);
            exit;
        }
        
        // Base64 handling (left as is, but noted to be less secure/efficient than file upload)
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (isset($data['image'])) {
            // ... (Base64 logic remains, typically less used in modern uploads) ...
             throw new Exception('Base64 uploads are currently disabled for security.');
        }
        
        throw new Exception('No valid image provided');

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle Database Connections (Add Anime)
if (isset($_GET['action']) && $_GET['action'] === 'db_connection') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $conn = get_db_connection($target_app, $db_config);

    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    // Validate required fields
    $required = ['title', 'year', 'season', 'image', 'date'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            die(json_encode([
                'success' => false,
                'message' => "Missing required field: $field"
            ]));
        }
    }

    // Sanitize data
    $title = $conn->real_escape_string($data['title']);
    $year = (int)$data['year'];
    $season = $conn->real_escape_string($data['season']);
    $image = $conn->real_escape_string($data['image']);
    $date = $conn->real_escape_string($data['date']);
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : '';
    // NEW FIELDS
    $lnk1 = isset($data['lnk1']) ? $conn->real_escape_string($data['lnk1']) : '';
    $lnk2 = isset($data['lnk2']) ? $conn->real_escape_string($data['lnk2']) : '';

    try {
        // Assuming table has been updated to include Lnk1 and Lnk2 columns
        $stmt = $conn->prepare("INSERT INTO main_db (Title, Year, Season, Img, DateR, Synopsis, Lnk1, Lnk2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sissssss", 
            $title,
            $year,
            $season,
            $image,
            $date,
            $synopsis,
            $lnk1, // NEW BIND
            $lnk2  // NEW BIND
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Anime added successfully',
            'Rec_Num' => $stmt->insert_id
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
    exit;
}

// Handle Anime Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $conn = get_db_connection($target_app, $db_config);

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    $id = (int)$data['id'];
    
    try {
        // First get the image path to delete the file
        $sql = "SELECT Img FROM main_db WHERE Rec_Num = $id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // SECURITY IMPROVEMENT: Check if file is within the intended upload directory
            if (strpos($row['Img'], 'uploads/') === 0 && file_exists($row['Img'])) {
                unlink($row['Img']);
            }
        }
        
        // Then delete the record
        $sql = "DELETE FROM main_db WHERE Rec_Num = $id";
        if ($conn->query($sql)) {
            echo json_encode([
                'success' => true,
                'message' => 'Anime deleted successfully'
            ]);
        } else {
            throw new Exception('Delete failed: ' . $conn->error);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        $conn->close();
    }
    exit;
}

// Handle Get Anime Details
if (isset($_GET['action']) && $_GET['action'] === 'get_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $conn = get_db_connection($target_app, $db_config);

    $id = (int)$_GET['id'];
    
    try {
        // FETCHING NEW FIELDS
        $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis, Lnk1, Lnk2 FROM main_db WHERE Rec_Num = $id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $anime = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'anime' => $anime
            ]);
        } else {
            throw new Exception('Anime not found');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        $conn->close();
    }
    exit;
}

// Handle Anime Update
if (isset($_GET['action']) && $_GET['action'] === 'update_anime') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    error_reporting(0);

    $target_app = $_GET['for'] ?? 'anime';
    $conn = get_db_connection($target_app, $db_config);

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['id'])) {
        die(json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]));
    }

    // Sanitize all update fields
    $id = (int)$data['id'];
    $title = $conn->real_escape_string($data['title'] ?? '');
    $year = (int)($data['year'] ?? 0);
    $season = $conn->real_escape_string($data['season'] ?? '');
    $date = $conn->real_escape_string($data['date'] ?? '');
    $synopsis = isset($data['synopsis']) ? $conn->real_escape_string($data['synopsis']) : '';
    // NEW FIELDS
    $lnk1 = isset($data['lnk1']) ? $conn->real_escape_string($data['lnk1']) : '';
    $lnk2 = isset($data['lnk2']) ? $conn->real_escape_string($data['lnk2']) : '';


    try {
        // SECURITY IMPROVEMENT: Use prepared statement for UPDATE as well
        $stmt = $conn->prepare("UPDATE main_db SET Title=?, Year=?, Season=?, DateR=?, Synopsis=?, Lnk1=?, Lnk2=? WHERE Rec_Num=?");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sisssssi", 
            $title,
            $year,
            $season,
            $date,
            $synopsis,
            $lnk1,
            $lnk2,
            $id
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Anime updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    } finally {
        if (isset($stmt)) $stmt->close();
        $conn->close();
    }
    exit;
}

// ANIME TRACKER SYSTEM
function renderAnimeTracker($config) {
    // BUG FIX: The database connection was only closed when exiting in AJAX calls,
    // not in the main rendering logic, and $con2 was sometimes not used.
    // Re-establishing the connection locally for the rendering part.
    $con2 = new mysqli(
        $config['servername'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port'] ?? 3306
    );

    if ($con2->connect_error) {
        echo "<script> alert('Connection Error'); </script>";
        die("Connection failed: " . $con2->connect_error);
    }

    // Seasonal theme setup
    $Y = date('Y');
    $themeBG = "";
    $seasonGifs = [
        'summer' => "https://images-wixmp-ed30a86b8c4ca887773594c2.wixmp.com/f/ed8839e8-3dcb-4bf1-b158-30dcd13e9589/dbgk12z-b8175564-549b-4bf6-a9bc-e3cd668811fb.gif?token=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1cm46YXBwOjdlMGQxODg5ODIyNjQzNzNhNWYwZDQxNWVhMGQyNmUwIiwiaXNzIjoidXJuOmFwcDo3ZTBkMTg4OTgyMjY0MzczYTVmMGQ0MTVlYTBkMjZlMCIsIm9iaiI6W1t7InBhdGgiOiJcL2ZcL2VkODgzOWU4LTNkY2ItNGJmMS1iMTU4LTMwZGNkMTNlOTU4OVwvZGJnazEyei1iODE3NTU2NC01NDliLTRiZjYtYTliYy1lM2NkNjY4ODExZmIuZ2lmIn1dXSwiYXVkIjpbInVybjpzZXJ2aWNlOmZpbGUuZG93bmxvYWQiXX0.-oUjRC5dqwc4oF4JhMR8Qazb1w8bRA-bNiLOADFgZh8",
        'winter' => "https://supernaturalhippie.com/wp-content/uploads/2019/12/snowflakes-moon-and-mountains-fantasy-gif.gif?w=1200",
        'spring' => "https://mir-s3-cdn-cf.behance.net/project_modules/hd/2c0aa8139400419.622f4c3445217.gif",
        'fall' => "https://giffiles.alphacoders.com/183/18358.gif"
    ];

    date_default_timezone_set('Asia/Tokyo');
    $mo = date('m');

    if ($mo >= 3 && $mo <= 5) {
        $themeBG = $seasonGifs['spring'];
        $var = "Spring";
    } elseif ($mo >= 6 && $mo <= 8) {
        $themeBG = $seasonGifs['summer'];
        $var = "Summer";
    } elseif ($mo >= 9 && $mo <= 11) {
        $themeBG = $seasonGifs['fall'];
        $var = "Fall";
    } else {
        $themeBG = $seasonGifs['winter'];
        $var = "Winter";
    }
    
    // --- BUG FIX: Check for the 'Ebtn' action and process it if present ---
    if(isset($_POST['Ebtn'])) {
        // Set a flag to display the search results section by default
        $display_search_section = true;
    }
    // --- BUG FIX: Check for the 'Sbtn' action and process it if present ---
    if(isset($_POST['Sbtn'])) {
        // Set a flag to display the search results section by default
        $display_search_section = true;
    }
    // --- BUG FIX: Check for the 'Abtn' action and process it if present ---
    if(isset($_POST['Abtn'])) {
        // Set a flag to display the search results section by default
        $display_search_section = true;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <link rel="icon" href="icon.png">
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Anime Season Tracker - Modern UI</title>
        <style>
            /* ==================================================================== */
            /* MODERN UI STYLES                                                     */
            /* ==================================================================== */
            :root {
                --primary-color: #5d5eec; /* Modern Blue/Violet */
                --secondary-color: #4ecdc4; /* Cyan/Teal */
                --dark-color: #1a1a2e; /* Deep dark background */
                --light-color: #e9eef6;
                --overlay-dark: rgba(26, 26, 46, 0.85);
                --overlay-light: rgba(255, 255, 255, 0.1);
                --card-bg: #22223b;
                --text-secondary: #b8c2d5;
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body.anime-app {
                font-family: 'Poppins', sans-serif; /* Modern font */
                background-color: var(--dark-color);
                background-image: linear-gradient(rgba(26, 26, 46, 0.9), rgba(26, 26, 46, 0.9)), url(<?php echo $themeBG; ?>);
                background-attachment: fixed;
                background-size: cover;
                background-position: center;
                color: var(--light-color);
                min-height: 100vh;
                transition: background-image 0.5s ease, background-color 0.5s ease;
            }

            .container {
                max-width: 1400px; /* Wider container */
                margin: 0 auto;
                padding: 20px;
            }

            header {
                text-align: center;
                padding: 50px 0 30px;
            }

            h1 {
                font-size: 3.5rem;
                margin-bottom: 10px;
                color: var(--primary-color);
                text-shadow: 0 0 10px rgba(93, 94, 236, 0.5);
            }

            .season-display {
                font-size: 1.2rem;
                font-weight: 300;
                color: var(--text-secondary);
                margin-bottom: 40px;
            }

            .button-group {
                display: flex;
                justify-content: center;
                gap: 20px;
                margin-bottom: 50px;
            }

            button {
                background-color: var(--primary-color);
                color: white;
                border: none;
                padding: 12px 25px;
                font-size: 1rem;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
                font-weight: 600;
                min-width: 180px;
            }

            button:hover {
                background-color: #4849c3;
                transform: translateY(-3px);
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
            }
            
            button:disabled {
                background-color: #6c757d;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }

            .btn {
                padding: 10px 20px;
                width: 100%;
                border-radius: 6px;
                min-width: 100px;
            }
            
            .btn-secondary {
                background-color: var(--secondary-color);
            }
            .btn-secondary:hover {
                background-color: #3db9b0;
            }
            .btn-danger {
                background-color: #e74c3c;
            }
            .btn-danger:hover {
                background-color: #c0392b;
            }


            .card {
                background-color: var(--card-bg);
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 30px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            }

            .search-card, .add-card {
                width: 100%;
                max-width: 1000px;
                margin: 0 auto;
            }

            table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0 10px; /* Spacing between rows */
            }

            td {
                padding: 10px 15px;
                vertical-align: middle;
            }

            input, select, textarea {
                padding: 10px;
                width: 100%;
                border-radius: 6px;
                border: 1px solid #444;
                background-color: var(--overlay-dark);
                color: var(--light-color);
                transition: box-shadow 0.3s;
            }
            
            /* Table header styling */
            .search-card table tr:first-child td {
                font-weight: bold;
                color: var(--primary-color);
                padding-bottom: 5px;
            }


            input:focus, select:focus, textarea:focus {
                outline: none;
                box-shadow: 0 0 8px var(--secondary-color);
            }

            .search-result {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 25px;
                padding: 20px;
            }

            .anime-item {
                background-color: var(--card-bg);
                border-radius: 12px;
                overflow: hidden;
                display: flex;
                flex-direction: row;
                transition: transform 0.3s ease, box-shadow 0.3s ease;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            }

            .anime-item:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            }

            .anime-poster {
                width: 150px; /* Smaller poster for better card flow */
                min-width: 150px;
                height: 220px;
                object-fit: cover;
            }

            .anime-info {
                padding: 20px;
                flex: 1;
                display: flex;
                flex-direction: column;
            }

            .anime-title {
                font-size: 1.4rem;
                margin-bottom: 5px;
                color: var(--primary-color);
                font-weight: 700;
            }

            .anime-meta {
                color: var(--text-secondary);
                font-size: 0.9rem;
                margin-bottom: 10px;
            }

            .no-results {
                text-align: center;
                padding: 50px;
                font-size: 1.2rem;
                color: var(--text-secondary);
                grid-column: 1 / -1;
            }

            #result {
                margin-top: 20px;
                text-align: center;
            }

            .hidden {
                display: none !important;
            }

            .upload-options {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-top: 25px;
            }

            .file-upload-container {
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .file-input-label {
                padding: 10px 20px;
                background-color: var(--secondary-color);
                color: white;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.3s, transform 0.3s;
                text-align: center;
                min-width: 150px;
                font-weight: 600;
            }

            .file-input-label:hover {
                background-color: #3db9b0;
                transform: translateY(-1px);
            }

            @media (max-width: 768px) {
                .button-group {
                    flex-direction: column;
                    align-items: center;
                    gap: 10px;
                }
                
                .anime-item {
                    flex-direction: column;
                }
                
                .anime-poster {
                    width: 100%;
                    height: auto;
                    max-height: 300px;
                }
                
                .search-result {
                    grid-template-columns: 1fr;
                    padding: 0;
                }
            }
            
            .app-navigation button {
                background-color: #5d5eec;
                min-width: 100px;
                padding: 10px 20px;
            }
            
            /* Edit and Delete buttons */
            .anime-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-top: auto; /* Push actions to the bottom */
            }

            .edit-btn, .delete-btn, .lnk-btn1, .lnk-btn2 {
                padding: 6px 12px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 0.8rem;
                width: auto;
                min-width: 70px;
                transition: all 0.2s;
            }

            .edit-btn {
                background-color: #4a8fe7;
                color: white;
            }

            .delete-btn {
                background-color: #e74c3c;
                color: white;
            }
            
            /* Functioning Link Buttons */
            .lnk-btn1 {
                background-color: #9b59b6; /* Purple for Link 1 */
                color: white;
            }
            .lnk-btn2 {
                background-color: #34495e; /* Dark Blue for Link 2 */
                color: white;
            }
            
            .lnk-btn1:hover { background-color: #8e44ad; }
            .lnk-btn2:hover { background-color: #2c3e50; }

            .edit-btn:hover, .delete-btn:hover {
                opacity: 0.9;
                transform: scale(1.05);
            }

            /* Edit form styles */
            .edit-form {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background-color: var(--card-bg); /* Use card background */
                padding: 30px;
                border-radius: 12px;
                z-index: 1000;
                width: 90%;
                max-width: 600px;
                box-shadow: 0 15px 30px rgba(0,0,0,0.7);
                border: 1px solid var(--primary-color);
            }

            .edit-form h3 {
                margin-bottom: 20px;
                text-align: center;
                color: var(--primary-color);
            }

            .edit-form table {
                width: 100%;
                border-spacing: 0;
            }
            
            .edit-form td {
                padding: 8px 0;
            }
            
            .edit-form tr td:first-child {
                width: 100px;
                font-weight: 600;
            }


            .edit-form input, .edit-form select, .edit-form textarea {
                width: 100%;
                padding: 10px;
                margin-bottom: 10px;
            }

            .form-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-top: 20px;
            }
            
            .form-actions button {
                padding: 10px 20px;
                width: auto;
                min-width: 100px;
            }
            
            /* Scrollable Synopsis Div */
            .S-Div {
                margin-top: 10px;
                width: 100%;
                max-height: 80px; /* Reduced height for better card look */
                overflow-y: auto;
                scrollbar-width: thin;
                scrollbar-color: var(--primary-color) #333;
                text-align: justify;
                font-size: 0.9rem;
                color: var(--text-secondary);
                padding-right: 10px;
            }
            .S-Div::-webkit-scrollbar {
                width: 8px;
            }

            .S-Div::-webkit-scrollbar-thumb {
                background-color: var(--primary-color);
                border-radius: 4px;
            }
            
            .S-Div::-webkit-scrollbar-track {
                background: var(--dark-color);
            }
        </style>
    </head>
    <body class="anime-app">
        <div class="container">
            <div class="app-navigation">
                <button onclick="window.location.href = window.location.pathname;">Home</button>
            </div>
            
            <header>
                <h1>Anime Season Tracker</h1>
                <div class="season-display">Current Season: <?php echo $var . ' ' . date('Y'); ?></div>
            </header>

            <div class="button-group">
                <button onclick="document.getElementById('episode-form').submit()">Today's Episodes</button>
                <button onclick="btnSearch()">Search Anime</button>
                <button onclick="btnUpload()">Add Anime</button>
            </div>
            
            <form method="POST" id="episode-form" class="hidden">
                <input type="hidden" name="Ebtn" value="1">
            </form>

            <div id="search-section" class="card search-card <?php echo isset($display_search_section) ? '' : 'hidden'; ?>">
                <form method="POST" id="searchForm">
                    <table>
                        <tr>
                            <td>Year</td>
                            <td>Season</td>
                            <td>Day of Week</td>
                            <td style="width: 250px;">Actions</td>
                        </tr>
                        <tr>
                            <td>
                                <input required type="number" min="1900" max="<?php echo date('Y'); ?>" 
                                       value="<?php echo $Y; ?>" name="AniYear">
                            </td>
                            <td>
                                <select name="AniSeason">
                                    <option value="Summer" <?php echo (isset($_POST['AniSeason']) && $_POST['AniSeason'] == 'Summer') ? 'selected' : ''; ?>>Summer</option>
                                    <option value="Fall" <?php echo (isset($_POST['AniSeason']) && $_POST['AniSeason'] == 'Fall') ? 'selected' : ''; ?>>Fall</option>
                                    <option value="Winter" <?php echo (isset($_POST['AniSeason']) && $_POST['AniSeason'] == 'Winter') ? 'selected' : ''; ?>>Winter</option>
                                    <option value="Spring" <?php echo (isset($_POST['AniSeason']) && $_POST['AniSeason'] == 'Spring') ? 'selected' : ''; ?>>Spring</option>                                
                                </select>
                            </td>
                             <td>
                                <select name="AniWeek">
                                    <option value="">-</option>
                                    <?php
                                        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                        foreach ($days as $day) {
                                            $selected = (isset($_POST['AniWeek']) && $_POST['AniWeek'] == $day) ? 'selected' : '';
                                            echo "<option value=\"$day\" $selected>$day</option>";
                                        }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn" name="Sbtn" style="min-width: 100px;">Search</button>
                                    <button class="btn btn-secondary" name="Abtn" style="min-width: 100px;">View All</button>
                                </div>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>

            <div id="resDiv" class="search-result">
                <?php
                
                $search_performed = isset($_POST['Sbtn']) || isset($_POST['Ebtn']) || isset($_POST['Abtn']);
                if ($search_performed) {
                    
                    if(isset($_POST['Sbtn'])) {
                        $year = $con2->real_escape_string($_POST['AniYear']);
                        $season = $con2->real_escape_string($_POST['AniSeason']);
                        $week = $con2->real_escape_string($_POST['AniWeek']);
                        
                        $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis, Lnk1, Lnk2 FROM main_db 
                                WHERE Year='$year' AND Season='$season'";
                        if (!empty($week)) {
                            $sql .= " AND DAYNAME(DateR) = '$week'";
                        }
                        $result = $con2->query($sql);

                    } elseif(isset($_POST['Ebtn'])) {
                        $currentSeason = $var; // Use the current season determined above
                        // FIX: Use YEAR(DateR) to match the current year's entries
                        $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis, Lnk1, Lnk2 FROM main_db 
                                WHERE Season='$currentSeason' AND YEAR(DateR)=YEAR(CURDATE()) 
                                AND DAYNAME(DateR) = DAYNAME(CURDATE())";
                        $result = $con2->query($sql);

                    } elseif(isset($_POST['Abtn'])) {
                        $sql = "SELECT Rec_Num, Title, Year, Season, DateR, Img, Synopsis, Lnk1, Lnk2 FROM main_db";
                        $result = $con2->query($sql);
                    }
                    
                    if (isset($result) && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $StDate = "No Information";
                            if (!empty($row['DateR'])) {
                                $date = strtotime($row['DateR']);
                                $StDate = date('M d, l', $date);
                            }
                            
                            // Check for valid links before generating buttons
                            $lnk1_button = !empty($row['Lnk1']) ? '<button class="lnk-btn1" onclick="window.open(\''.$row['Lnk1'].'\', \'_blank\')">Link 1</button>' : '';
                            $lnk2_button = !empty($row['Lnk2']) ? '<button class="lnk-btn2" onclick="window.open(\''.$row['Lnk2'].'\', \'_blank\')">Link 2</button>' : '';

                            echo '<div class="anime-item">
                                    <img class="anime-poster" src="'.$row['Img'].'" alt="'.$row['Title'].'">
                                    <div class="anime-info">
                                        <h2 class="anime-title">'.$row['Title'].'</h2>
                                        <div class="anime-meta">'.$row['Season'].' '.$row['Year'].' | Released: '.$StDate.'</div>
                                        <div class="S-Div">'.($row['Synopsis'] ?: 'No synopsis available').'</div>
                                        <div class="anime-actions">
                                            <button class="edit-btn" onclick="editAnime('.$row['Rec_Num'].')">Edit</button>
                                            <button class="delete-btn btn-danger" onclick="deleteAnime('.$row['Rec_Num'].')">Delete</button>
                                            '.$lnk1_button.'
                                            '.$lnk2_button.'
                                        </div>
                                    </div>
                                  </div>';
                        }
                    } else {
                        // Display the appropriate no-results message
                         $msg = 'No anime found.';
                         if(isset($_POST['Sbtn'])) {
                              $msg = 'No anime found for ' . $con2->real_escape_string($_POST['AniSeason']) . ' ' . $con2->real_escape_string($_POST['AniYear']) . (!empty($_POST['AniWeek']) ? ' on ' . $con2->real_escape_string($_POST['AniWeek']) : '');
                         } elseif(isset($_POST['Ebtn'])) {
                             $msg = 'No new episodes today for ' . $var . ' season';
                         } elseif(isset($_POST['Abtn'])) {
                             $msg = 'No anime found in database';
                         }
                        echo '<div class="no-results">'.$msg.'</div>';
                    }
                }
                
                // If a search was performed, ensure the results div is visible
                if ($search_performed) {
                    echo "<script>document.getElementById('resDiv').style.display = 'grid';</script>";
                } else {
                    echo "<script>document.getElementById('resDiv').style.display = 'none';</script>";
                }
                ?>
            </div>

            <div id="add-section" class="card add-card hidden">
                <center>  <h2>Add New Anime Entry</h2> </center>
                <br>
                <table>
                    <tr>
                        <td>Title</td>
                        <td>Date</td>
                        <td>Season</td>
                        <td>Link 1</td>
                        <td>Link 2</td>
                    </tr>
                    <tr>
                        <td>
                            <input type="text" minlength="1" maxlength="250" 
                                   name="AnimeTitle" id="AnimeTitle" required 
                                   placeholder="Anime title">
                        </td>
                        <td>
                            <input type="date" id="AnimeDate" name="AnimeDate" 
                                   onChange="SeasonChange()" value="<?php echo date('Y-m-d'); ?>">
                            <input type="hidden" id="AnimeYear" name="AnimeYear" 
                                   value="<?php echo date('Y'); ?>">
                        </td>
                        <td>
                            <select name="AnimeSeason" id="AnimeSeason" disabled>
                                <option value="Summer">Summer</option>
                                <option value="Fall">Fall</option>
                                <option value="Winter">Winter</option>
                                <option value="Spring">Spring</option>
                            </select>
                        </td>
                         <td>
                            <input type="url" id="AnimeLnk1" placeholder="https://link1.com">
                        </td>
                         <td>
                            <input type="url" id="AnimeLnk2" placeholder="https://link2.com">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="5">Synopsis:</td>
                    </tr>
                    <tr>
                        <td colspan="5">
                            <textarea id="AnimeSynopsis" rows="3" placeholder="Enter a brief synopsis..."></textarea>
                        </td>
                    </tr>
                </table>
                
                <div class="upload-options">
                    <div class="file-upload-container">
                        <label class="file-input-label">
                            Select Image File
                            <input type="file" id="fileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none">
                        </label>
                        <span id="fileName" style="color: var(--text-secondary);">Select file or upload from clipboard</span>
                        <button class="btn btn-secondary" id="uploadBtn" onclick="uploadImage(event)">Save Anime</button>
                    </div>
                    <div id="fileError" style="color: #e74c3c; display: none; font-weight: 600;"></div>
                </div>
                
                <div id="result"></div>
            </div>
        </div>

        <script>
            // ====================================================================
            // JAVASCRIPT LOGIC UPDATES
            // ====================================================================
            
            // Initialization
            document.addEventListener('DOMContentLoaded', () => {
                // Ensure the correct sections are hidden/shown on initial load or non-post requests
                const isPostRequest = <?php echo json_encode(isset($_POST) && count($_POST) > 0); ?>;
                const isSearchActive = <?php echo json_encode(isset($display_search_section) ?? false); ?>;

                if (!isSearchActive) {
                    document.getElementById('search-section').classList.add('hidden');
                    document.getElementById('resDiv').style.display = 'none';
                } else {
                    document.getElementById('search-section').classList.remove('hidden');
                    document.getElementById('resDiv').style.display = 'grid'; // Use grid for results
                }
                
                document.getElementById('add-section').classList.add('hidden');
                SeasonChange(); // Initialize season based on current date
            });
            
            // Store the selected file
            let selectedFile = null;
            
            function btnSearch() {
                document.getElementById('add-section').classList.add('hidden');
                document.getElementById('search-section').classList.remove('hidden');
                document.getElementById('resDiv').style.display = 'grid'; // Ensure it's set to grid
            }

            function btnUpload() {
                document.getElementById('search-section').classList.add('hidden');
                document.getElementById('add-section').classList.remove('hidden');
                document.getElementById('resDiv').style.display = 'none';
                document.getElementById('AnimeTitle').focus();
            }

            function SeasonChange() {
                const dateInput = new Date(document.getElementById('AnimeDate').value + 'T00:00:00'); // Adding T00:00:00 prevents timezone issues
                const yearHolder = dateInput.getFullYear();
                const monthHolder = dateInput.getMonth(); // 0-11

                document.getElementById('AnimeYear').value = yearHolder;

                let seasonValue = "Winter"; // Default
                if (monthHolder >= 2 && monthHolder <= 4) { // March (2) - May (4)
                    seasonValue = "Spring";
                } else if (monthHolder >= 5 && monthHolder <= 7) { // June (5) - August (7)
                    seasonValue = "Summer";
                } else if (monthHolder >= 8 && monthHolder <= 10) { // September (8) - November (10)
                    seasonValue = "Fall";
                } 
                
                document.getElementById('AnimeSeason').value = seasonValue;
            }

            // File input handling
            document.getElementById('fileInput').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Simple type check
                    if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
                        document.getElementById('fileError').textContent = 'Invalid file type. Only JPEG, PNG, GIF, WEBp are allowed.';
                        document.getElementById('fileError').style.display = 'block';
                        selectedFile = null;
                        this.value = ''; // Clear file input
                        document.getElementById('fileName').textContent = 'Select file or upload from clipboard';
                        return;
                    }
                    
                    selectedFile = file;
                    document.getElementById('fileName').textContent = 'Selected: ' + file.name;
                    document.getElementById('fileError').style.display = 'none';
                }
            });

            // Paste from clipboard
            document.addEventListener('paste', function(e) {
                const items = e.clipboardData?.items;
                if (!items) return;

                for (let item of items) {
                    if (item.type.startsWith('image/')) {
                        e.preventDefault();
                        const file = item.getAsFile();
                        if (file) {
                            selectedFile = file;
                            document.getElementById('fileName').textContent = 'Pasted image: ' + file.type;
                            document.getElementById('fileError').style.display = 'none';
                        }
                        break;
                    }
                }
            });
            
            // Utility function to get base64 string from a File object
            function getBase64(file) {
              return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = () => resolve(reader.result);
                reader.onerror = (error) => reject(error);
              });
            }


            async function uploadImage(event) {
                event.preventDefault();
                
                const title = document.getElementById('AnimeTitle').value.trim();
                const year = document.getElementById('AnimeYear').value;
                const season = document.getElementById('AnimeSeason').value;
                const date = document.getElementById('AnimeDate').value;
                const synopsis = document.getElementById('AnimeSynopsis').value.trim();
                const lnk1 = document.getElementById('AnimeLnk1').value.trim();
                const lnk2 = document.getElementById('AnimeLnk2').value.trim();


                if (!title) {
                    alert('Please enter an anime title');
                    return;
                }

                if (!selectedFile) {
                    alert('Please select an image file or paste an image');
                    return;
                }

                const uploadBtn = document.getElementById('uploadBtn');
                uploadBtn.disabled = true;
                uploadBtn.textContent = 'Processing...';

                try {
                    // Upload image first
                    const formData = new FormData();
                    formData.append('image', selectedFile, selectedFile.name);

                    const uploadResponse = await fetch('?action=image_upload&for=anime', {
                        method: 'POST',
                        body: formData
                    });

                    const uploadResult = await uploadResponse.json();

                    if (!uploadResult.success) {
                        throw new Error(uploadResult.message || 'Image upload failed');
                    }

                    // Save anime data to database
                    const saveResponse = await fetch('?action=db_connection&for=anime', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            title: title,
                            year: year,
                            season: season,
                            image: uploadResult.filename,
                            date: date,
                            synopsis: synopsis, // Include synopsis
                            lnk1: lnk1,         // Include new link fields
                            lnk2: lnk2
                        })
                    });

                    const saveResult = await saveResponse.json();

                    if (saveResult.success) {
                        alert('Anime added successfully! Record ID: ' + saveResult.Rec_Num);
                        // Reset form fields
                        document.getElementById('AnimeTitle').value = '';
                        document.getElementById('AnimeSynopsis').value = '';
                        document.getElementById('AnimeLnk1').value = '';
                        document.getElementById('AnimeLnk2').value = '';
                        document.getElementById('AnimeDate').value = '<?php echo date('Y-m-d'); ?>';
                        SeasonChange();
                        selectedFile = null;
                        document.getElementById('fileName').textContent = 'Select file or upload from clipboard';
                        document.getElementById('fileInput').value = '';
                    } else {
                        // If database save fails, you might want to consider deleting the uploaded image
                        throw new Error(saveResult.message || 'Failed to save anime data');
                    }

                } catch (error) {
                    console.error('Error:', error);
                    alert('Error: ' + error.message);
                } finally {
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = 'Save Anime';
                }
            }

            // Edit and Delete functions
            async function editAnime(id) {
                try {
                    const response = await fetch(`?action=get_anime&for=anime&id=${id}`);
                    const result = await response.json();
                    
                    if (result.success) {
                        const anime = result.anime;
                        
                        // Create edit form
                        const editForm = document.createElement('div');
                        editForm.className = 'edit-form';
                        editForm.innerHTML = `
                            <h3>Edit Anime #${id}</h3>
                            <table>
                                <tr>
                                    <td>Title:</td>
                                    <td><input type="text" id="editTitle" value="${anime.Title}"></td>
                                </tr>
                                <tr>
                                    <td>Year:</td>
                                    <td><input type="number" id="editYear" value="${anime.Year}"></td>
                                </tr>
                                <tr>
                                    <td>Season:</td>
                                    <td>
                                        <select id="editSeason">
                                            <option value="Summer" ${anime.Season === 'Summer' ? 'selected' : ''}>Summer</option>
                                            <option value="Fall" ${anime.Season === 'Fall' ? 'selected' : ''}>Fall</option>
                                            <option value="Winter" ${anime.Season === 'Winter' ? 'selected' : ''}>Winter</option>
                                            <option value="Spring" ${anime.Season === 'Spring' ? 'selected' : ''}>Spring</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td>Release Date:</td>
                                    <td><input type="date" id="editDate" value="${anime.DateR}"></td>
                                </tr>
                                <tr>
                                    <td>Link 1:</td>
                                    <td><input type="url" id="editLnk1" placeholder="https://link1.com" value="${anime.Lnk1 || ''}"></td>
                                </tr>
                                <tr>
                                    <td>Link 2:</td>
                                    <td><input type="url" id="editLnk2" placeholder="https://link2.com" value="${anime.Lnk2 || ''}"></td>
                                </tr>
                                <tr>
                                    <td>Synopsis:</td>
                                    <td><textarea id="editSynopsis" rows="4" placeholder="Enter anime synopsis">${anime.Synopsis || ''}</textarea></td>
                                </tr>
                            </table>
                            <div class="form-actions">
                                <button class="btn" onclick="saveEdit(${id})">Save Changes</button>
                                <button class="btn btn-secondary" onclick="closeEditForm()">Cancel</button>
                            </div>
                        `;
                        
                        document.body.appendChild(editForm);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error loading anime data');
                }
            }

            async function saveEdit(id) {
                const saveBtn = event.target;
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
                
                try {
                    const response = await fetch('?action=update_anime&for=anime', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            id: id,
                            title: document.getElementById('editTitle').value.trim(),
                            year: document.getElementById('editYear').value,
                            season: document.getElementById('editSeason').value,
                            date: document.getElementById('editDate').value,
                            synopsis: document.getElementById('editSynopsis').value.trim(),
                            lnk1: document.getElementById('editLnk1').value.trim(), // Save new link field 1
                            lnk2: document.getElementById('editLnk2').value.trim()  // Save new link field 2
                        })
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert('Anime updated successfully!');
                        closeEditForm();
                        // Re-submit the existing form to refresh results with the update
                        const currentForm = document.getElementById('searchForm');
                        if (currentForm) {
                            // Find the button that was last clicked to ensure correct context reload
                            // For simplicity and effectiveness, we'll reload the page, which is the most reliable way to refresh PHP state.
                            location.reload(); 
                        } else {
                            location.reload();
                        }
                        
                    } else {
                        throw new Error(result.message);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error updating anime: ' + error.message);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                }
            }

            function closeEditForm() {
                const editForm = document.querySelector('.edit-form');
                if (editForm) {
                    editForm.remove();
                }
            }

            async function deleteAnime(id) {
                if (!confirm('Are you sure you want to delete this anime? This action cannot be undone.')) {
                    return;
                }

                try {
                    const response = await fetch('?action=delete_anime&for=anime', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ id: id })
                    });

                    const result = await response.json();

                    if (result.success) {
                        alert('Anime deleted successfully!');
                        location.reload();
                    } else {
                        throw new Error(result.message);
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Error deleting anime: ' + error.message);
                }
            }
        </script>
    </body>
    </html>
    <?php
    $con2->close();
}
?>

<?php
// Main rendering logic
if ($app === 'anime') {
    renderAnimeTracker($db_config['anime']);
}
?>